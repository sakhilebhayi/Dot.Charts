<?php

namespace App\Services;

use App\Models\KnowledgePack;
use Illuminate\Support\Str;

class IncidentPackGenerator
{
    private const KEY_ID = 'dot-charts-dkp-v1';

    public function __construct(private readonly DkpSigner $signer = new DkpSigner) {}

    /**
     * Generates, signs, and persists one incident_report pack. Idempotent
     * per $slug. $incidentBody must match schemas/incident.schema.json's
     * required fields exactly.
     */
    public function generate(string $slug, array $incidentBody, float $confidence): array
    {
        $existing = KnowledgePack::where('payload_type', 'incident_report')
            ->where('period', $slug)
            ->first();

        if ($existing) {
            return ['generated' => false, 'reason' => 'already_generated', 'pack' => $existing];
        }

        $packId = 'dkp:dot-charts:'.(string) Str::uuid();
        $createdAt = now();

        $title = "Incident report: {$incidentBody['incident_id']}";
        $summary = $incidentBody['root_cause']['statement'];

        $envelope = [
            'dkp_version' => '1.0.0',
            'pack_id' => $packId,
            'pack_version' => '1.0.0',
            'platform' => 'dot-charts',
            'title' => $title,
            'summary' => $summary,
            'created_at' => $createdAt->toIso8601String(),
            'contributors' => [[
                'id' => 'chartsense-knowledge-pack-generator',
                'kind' => 'ai',
                'display_name' => 'ChartSense Knowledge Pack Generator',
                'key_id' => self::KEY_ID,
            ]],
            'payloads' => [[
                'payload_type' => 'incident_report',
                'body' => $incidentBody,
            ]],
            'provenance' => [
                'sources' => [[
                    'kind' => 'human_observation',
                    'uri' => 'chartsense://incident-report',
                    'observed_at' => $createdAt->toIso8601String(),
                ]],
                'transformations' => [[
                    'step' => 'author_and_sign',
                    'tool' => 'IncidentPackGenerator',
                    'tool_version' => '1.0.0',
                    'actor' => 'system',
                ]],
                'published_by' => 'dot-charts',
            ],
            'confidence' => $confidence,
            'signatures' => [],
        ];

        $pack = KnowledgePack::create([
            'pack_id' => $packId,
            'payload_type' => 'incident_report',
            'strategy_class' => null,
            'account_count' => null,
            'pack_version' => '1.0.0',
            'title' => $title,
            'summary' => $summary,
            'period' => $slug,
            'envelope' => $envelope,
            'status' => 'pending_approval',
            'created_at' => $createdAt,
        ]);

        return ['generated' => true, 'reason' => null, 'pending_approval' => true, 'pack' => $pack];
    }
}
