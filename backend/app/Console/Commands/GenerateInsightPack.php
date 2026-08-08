<?php

namespace App\Console\Commands;

use App\Services\InsightPackGenerator;
use Illuminate\Console\Command;

class GenerateInsightPack extends Command
{
    protected $signature = 'dkp:generate-insight';

    protected $description = 'Generate the real, one-off insight pack reporting the chart-analysis AI-honesty disclosure. Idempotent.';

    public function handle(InsightPackGenerator $generator): int
    {
        $result = $generator->generate(
            slug: 'chart-analysis-demo-disclosure-v1',
            statement: "ChartSense's chart-analysis endpoint (POST /api/chart/analyze) always discloses "
                . 'whether returned pattern-recognition results are placeholder/demo data or computed from '
                . 'real market data, via an explicit is_demo boolean and disclaimer field on every response '
                . '-- it never presents unlabeled placeholder output as if it were a real analysis.',
            domain: 'trading-analysis-integrity',
            method: 'Manual code audit of ChartAnalysisController::analyzeChart() and its placeholder '
                . 'fallback path, verified against the live API response schema.',
            evidence: [[
                'kind' => 'external',
                'reference' => 'chartsense://backend/app/Http/Controllers/ChartAnalysisController.php',
                'note' => 'is_demo/disclaimer fields present on both the real-analysis and placeholder response branches',
            ]],
            scope: 'Site-wide -- applies to every chart-analysis response, not a sampled subset.',
            confidence: 0.85,
        );

        if ($result['generated']) {
            $this->info("Generated insight pack {$result['pack']->pack_id}.");
        } else {
            $this->info("Skipped: insight already generated ({$result['pack']->pack_id}).");
        }

        return self::SUCCESS;
    }
}
