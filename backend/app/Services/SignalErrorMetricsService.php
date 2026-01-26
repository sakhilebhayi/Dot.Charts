<?php

namespace App\Services;

class SignalErrorMetricsService
{
    /**
     * Track error metrics for signal predictions
     * @param array $predictions Array of predicted signals ("buy", "sell", "hold")
     * @param array $actuals Array of actual outcomes ("buy", "sell", "hold")
     * @return array Metrics: accuracy, precision, recall, F1, confusion matrix
     */
    public function computeMetrics(array $predictions, array $actuals): array
    {
        $labels = ['buy', 'sell', 'hold'];
        $confusion = array_fill_keys($labels, array_fill_keys($labels, 0));
        $correct = 0;
        $total = count($predictions);
        foreach ($predictions as $i => $pred) {
            $actual = $actuals[$i] ?? null;
            if ($actual === null) continue;
            if ($pred === $actual) $correct++;
            $confusion[$actual][$pred]++;
        }
        $accuracy = $total ? $correct / $total : 0;
        $precision = [];
        $recall = [];
        $f1 = [];
        foreach ($labels as $label) {
            $tp = $confusion[$label][$label];
            $fp = array_sum(array_column($confusion, $label)) - $tp;
            $fn = array_sum($confusion[$label]) - $tp;
            $precision[$label] = ($tp + $fp) ? $tp / ($tp + $fp) : 0;
            $recall[$label] = ($tp + $fn) ? $tp / ($tp + $fn) : 0;
            $f1[$label] = ($precision[$label] + $recall[$label]) ? 2 * $precision[$label] * $recall[$label] / ($precision[$label] + $recall[$label]) : 0;
        }
        return [
            'accuracy' => round($accuracy, 3),
            'precision' => $precision,
            'recall' => $recall,
            'f1_score' => $f1,
            'confusion_matrix' => $confusion
        ];
    }
}
