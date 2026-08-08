<?php

namespace App\Console\Commands;

use App\Services\IncidentPackGenerator;
use Illuminate\Console\Command;

class GenerateIncidentPack extends Command
{
    protected $signature = 'dkp:generate-incident';

    protected $description = 'Generate the real, one-off incident_report pack for the storage/framework missing-directories bug (commit 73c4f3d). Idempotent.';

    public function handle(IncidentPackGenerator $generator): int
    {
        $incidentBody = [
            'incident_id' => 'chartsense-inc-2026-08-08-001',
            'kind' => 'incident',
            'severity' => 'sev3',
            'detection' => [
                'detected_at' => '2026-08-08T17:46:20Z',
                'detected_by' => 'Manual verification during Strategy Builder F2 implementation pass (live php artisan serve testing)',
                'method' => 'A request expected to return 404 (nonexistent custom-strategy ID) returned 500 instead, surfaced only under the live dev server, not the test suite',
            ],
            'impact' => [
                'systems' => ['CustomStrategyController', 'BacktestController', 'Laravel framework cache/session/view compilation'],
                'description' => "Every firstOrFail()-based 404 response (in both controllers) was replaced by a 500 'Please provide a valid cache path' error under php artisan serve, because storage/framework/{cache,sessions,views} and storage/logs did not exist in the checkout at all, despite .gitignore expecting .gitkeep placeholders for them.",
            ],
            'timeline' => [
                ['at' => '2026-08-08T17:40:00Z', 'event' => 'F2 manual verification step requested a nonexistent custom-strategy ID, expecting a 404 JSON response'],
                ['at' => '2026-08-08T17:43:00Z', 'event' => "Observed a 500 'Please provide a valid cache path' error instead; root-caused to missing storage/framework and storage/logs directories"],
                ['at' => '2026-08-08T17:46:20Z', 'event' => 'Fix applied: restored the four missing directories with .gitkeep placeholders, verified the same request now returns 404 as expected'],
            ],
            'root_cause' => [
                'statement' => "storage/framework/{cache,sessions,views} and storage/logs did not exist in this checkout at all -- Laravel's live dev server needs these directories to write compiled views, cache entries, and sessions, and their absence caused every firstOrFail()-triggered exception handling path to fail with a filesystem error before it could render the intended 404 response.",
                'contributing_factors' => [
                    '.gitignore expected .gitkeep placeholders in these directories, but the placeholders themselves were never committed',
                    "php artisan test uses a different runtime config that doesn't need these paths, so the automated test suite never exercised this failure mode",
                ],
            ],
            'corrective_actions' => [
                [
                    'action' => 'Restore the missing storage/framework/{cache,sessions,views} and storage/logs directories with .gitkeep placeholders',
                    'owner' => 'ChartSense Platform Lead',
                    'due' => '2026-08-08',
                    'status' => 'done',
                ],
            ],
            'lessons' => [
                [
                    'lesson' => "Directories a framework needs at runtime but that git can't track empty (cache/session/log/view-compilation paths) must have committed .gitkeep placeholders verified present in a fresh checkout -- a passing test suite alone does not prove a live server will boot cleanly, if the test runtime config sidesteps the same paths.",
                    'verified' => true,
                    'verification_evidence' => 'Fix applied and independently re-verified live (php artisan serve, same request now returns the correct 404) in the same session; the missing-directories failure mode is a standard, well-understood Laravel deployment gotcha, not a novel or unverified claim.',
                ],
            ],
        ];

        $result = $generator->generate('storage-framework-missing-2026-08-08', $incidentBody, 0.95);

        if ($result['generated']) {
            $this->info("Generated incident pack {$result['pack']->pack_id}.");
        } else {
            $this->info("Skipped: incident already generated ({$result['pack']->pack_id}).");
        }

        return self::SUCCESS;
    }
}
