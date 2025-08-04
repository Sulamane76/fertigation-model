<?php
header('Content-Type: application/json');

/**
 * Calculate monthly IRR from cash flows
 */
function calculate_irr(array $cashFlows): float {
    $guess = 0.1; $max_iter = 1000; $tolerance = 1.0e-7;
    for ($i = 0; $i < $max_iter; $i++) {
        $npv = 0.0; $dnpv = 0.0;
        foreach ($cashFlows as $t => $cf) {
            $npv += $cf / pow(1 + $guess, $t);
            if ($t > 0) $dnpv -= $t * $cf / pow(1 + $guess, $t + 1);
        }
        if ($dnpv == 0) return NAN;
        $newGuess = $guess - $npv / $dnpv;
        if (abs($newGuess - $guess) < $tolerance) return $newGuess;
        $guess = $newGuess;
    }
    return NAN;
}

/**
 * Lagged cash flow model with IRR and ROI multiple
 */
function run_financial_model(array $params): array {
    $timeHorizon = $params['time_horizon'] ?? 18;
    $conversionRate = ($params['conversion_rate'] ?? 0) / 100;
    $unitMargin = $params['unit_margin'] ?? 0;
    $cac = $params['cac'] ?? 0;
    $opex = $params['opex_monthly'] ?? 0;
    $setup = $params['setup_costs'] ?? 0;
    $rampMonths = max(1, $params['ramp_months'] ?? 4);
    $revenueDelay = max(0, $params['revenue_delay'] ?? 2);

    // Treat initial investment as setup + 3 months of opex
    $initialInvestment = $setup + ($opex * 3);
    $cashFlows = [-$initialInvestment];
    $cumulative = -$initialInvestment;
    $payback = 'N/A';

    $revenuePipeline = []; 
    $cacPipeline = [];

    $fullLeads = $params['leads_per_month'] ?? 0;
    if (!empty($params['is_contractor'])) {
        $fullLeads *= ($params['contractor_count'] ?? 0);
    }

    for ($m = 1; $m <= $timeHorizon; $m++) {
        $ramp = min(1.0, $m / $rampMonths);
        $leads = $fullLeads * $ramp;
        $customers = $leads * $conversionRate;

        $revenuePipeline[$m + $revenueDelay] = $customers * $unitMargin;
        $cacPipeline[$m + $revenueDelay] = $customers * $cac;

        $rev = $revenuePipeline[$m] ?? 0;
        $cost = $cacPipeline[$m] ?? 0;
        $cf = $rev - $cost - $opex;
        $cashFlows[] = $cf;

        $cumulative += $cf;
        if ($cumulative >= 0 && $payback === 'N/A') $payback = $m;
    }

    $irrMonthly = calculate_irr($cashFlows);
    $irrAnnual = is_nan($irrMonthly) ? 0 : (pow(1 + $irrMonthly, 12) - 1);

    $totalInflows = array_sum(array_filter($cashFlows, fn($v) => $v > 0));
    $roiMultiple = $initialInvestment > 0 ? $totalInflows / $initialInvestment : 0;

    return [
        'irr' => $irrAnnual,
        'irr_percent' => round($irrAnnual * 100, 2) . '%',
        'payback_period' => $payback,
        'net_cash_flow' => number_format(array_sum($cashFlows), 0),
        'roi_multiple' => round($roiMultiple, 2),
        'initial_investment' => number_format($initialInvestment, 0)
    ];
}

// --- 5 Channels with realistic defaults ---
$channels = [
    'contractor' => [
        'is_contractor' => true, 'contractor_count' => 50, 'leads_per_month' => 5,
        'conversion_rate' => 30, 'cac' => 500, 'unit_margin' => 600,
        'opex_monthly' => 7500, 'setup_costs' => 15000,
        'ramp_months' => 4, 'revenue_delay' => 2
    ],
    'direct' => [
        'is_contractor' => false, 'leads_per_month' => 300,
        'conversion_rate' => 20, 'cac' => 300, 'unit_margin' => 700,
        'opex_monthly' => 15000, 'setup_costs' => 30000,
        'ramp_months' => 4, 'revenue_delay' => 2
    ],
    'distributor' => [
        'is_contractor' => false, 'leads_per_month' => 150,
        'conversion_rate' => 35, 'cac' => 150, 'unit_margin' => 400,
        'opex_monthly' => 5000, 'setup_costs' => 10000,
        'ramp_months' => 4, 'revenue_delay' => 2
    ],
    'retail' => [
        'is_contractor' => false, 'leads_per_month' => 100,
        'conversion_rate' => 25, 'cac' => 250, 'unit_margin' => 500,
        'opex_monthly' => 7000, 'setup_costs' => 15000,
        'ramp_months' => 4, 'revenue_delay' => 2
    ],
    'hybrid' => [
        'is_contractor' => false, 'leads_per_month' => 60,
        'conversion_rate' => 25, 'cac' => 200, 'unit_margin' => 550,
        'opex_monthly' => 9000, 'setup_costs' => 20000,
        'ramp_months' => 4, 'revenue_delay' => 2
    ]
];

// --- Params ---
$timeHorizon = $_GET['time_horizon'] ?? 24;
$viewMode = $_GET['view'] ?? 'full';
$runMonteCarlo = isset($_GET['montecarlo']) && $_GET['montecarlo'] == 1;

// --- Deterministic Run ---
$results = [];
foreach ($channels as $name => $params) {
    $params['time_horizon'] = $timeHorizon;
    $results[$name] = run_financial_model($params);
    $results[$name]['channel'] = ucfirst($name);
}

// --- Summary ---
$irrs = array_column($results, 'irr');
$bestIrrKey = array_search(max($irrs), $irrs);

$summary = [
    'best_irr_channel' => array_keys($results)[$bestIrrKey] ?? '',
    'fastest_payback_months' => min(array_column($results, 'payback_period')),
    'highest_roi_multiple' => max(array_column($results, 'roi_multiple'))
];

// --- Simple / Board views ---
if ($viewMode === 'simple') {
    $results = array_map(fn($r) => [
        'channel' => $r['channel'],
        'irr_percent' => $r['irr_percent'],
        'payback_period' => $r['payback_period'],
        'roi_multiple' => $r['roi_multiple']
    ], $results);
}

if ($viewMode === 'board') {
    $boardView = [];
    foreach ($results as $key => $vals) {
        $boardView[$key] = [
            'channel' => $vals['channel'],
            'payback_months' => $vals['payback_period'],
            'roi_multiple' => $vals['roi_multiple'],
            'irr_display' => round($vals['irr']*100,2).'%'
        ];
    }
    echo json_encode(['summary'=>$summary,'channels'=>$boardView], JSON_PRETTY_PRINT);
    exit;
}

// --- Monte Carlo ---
$monteCarloOutput = null;
if ($runMonteCarlo) {
    $iterations = 1000;
    foreach ($channels as $name => $params) {
        $irrSamples = [];
        for ($i=0;$i<$iterations;$i++) {
            $iter = $params;
            $iter['leads_per_month'] *= (1+(mt_rand(-20,20)/100));
            $iter['conversion_rate'] *= (1+(mt_rand(-20,20)/100));
            $iter['unit_margin'] *= (1+(mt_rand(-10,10)/100));
            $iter['cac'] *= (1+(mt_rand(-15,15)/100));
            $iter['time_horizon']=$timeHorizon;

            $res = run_financial_model($iter);
            $irrSamples[]=$res['irr']*100;
        }
        sort($irrSamples);
        $c=count($irrSamples);
        $p5=$irrSamples[(int)($c*0.05)];
        $p50=$irrSamples[(int)($c*0.5)];
        $p95=$irrSamples[(int)($c*0.95)];

        $monteCarloOutput[$name]=[
            'min_irr_percent'=>round(min($irrSamples),2).'%',
            'p5_irr_percent'=>round($p5,2).'%',
            'p50_irr_percent'=>round($p50,2).'%',
            'p95_irr_percent'=>round($p95,2).'%',
            'max_irr_percent'=>round(max($irrSamples),2).'%',
            'mean_irr_percent'=>round(array_sum($irrSamples)/$c,2).'%'
        ];
    }
}

// --- Output ---
$output=['summary'=>$summary,'channels'=>$results];
if($monteCarloOutput) $output['monte_carlo']=$monteCarloOutput;

echo json_encode($output,JSON_PRETTY_PRINT);
