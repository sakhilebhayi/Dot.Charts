<?php

namespace App\Services;

use App\Models\KnowledgePack;
use Illuminate\Support\Str;
use RuntimeException;

class InsightPackGenerator
{
    private const KEY_ID = 'dot-charts-dkp-v1';

    public function __construct(private readonly DkpSigner $signer = new DkpSigner())
    {
    }

    /**
     * Generates, signs, and persists one insight pack. Idempotent per
     * $slug -- re-calling with the same slug returns the existing pack
     * without duplicating.
     */
    public function generate(
        string $slug,
        string $statement,
        string $domain,
        string $method,
        array $evidence,
        string $scope,
        float $confidence,
    ): array {
        $existing = KnowledgePack::where('payload_type', 'insight')
            ->where('period', $slug)
            ->first();

        if ($existing) {
            return ['generated' => false, 'reason' => 'already_generated', 'pack' => $existing];
        }

        $packId = 'dkp:dot-charts:' . (string) Str::uuid();
        $createdAt = now();

        $title = "Insight: {$domain}";
        $summary = $statement;

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
                'payload_type' => 'insight',
                'body' => [
                    'statement' => $statement,
                    'domain' => $domain,
                    'method' => $method,
                    'evidence' => $evidence,
                    'scope' => $scope,
                ],
            ]],
            'provenance' => [
                'sources' => [[
                    'kind' => 'human_observation',
                    'uri' => 'chartsense://code-audit',
                    'observed_at' => $createdAt->toIso8601String(),
                ]],
                'transformations' => [[
                    'step' => 'author_and_sign',
                    'tool' => 'InsightPackGenerator',
                    'tool_version' => '1.0.0',
                    'actor' => 'system',
                ]],
                'published_by' => 'dot-charts',
            ],
            'confidence' => $confidence,
            'signatures' => [],
        ];

        $envelope['signatures'] = $this->signer->sign($envelope);

        if (! $this->signer->verify($envelope)) {
            throw new RuntimeException('Generated Knowledge Pack failed self-verification -- refusing to persist an unverifiable artifact.');
        }

        $pack = KnowledgePack::create([
            'pack_id' => $packId,
            'payload_type' => 'insight',
            'strategy_class' => null,
            'account_count' => null,
            'pack_version' => '1.0.0',
            'title' => $title,
            'summary' => $summary,
            'period' => $slug,
            'envelope' => $envelope,
            'created_at' => $createdAt,
        ]);

        return ['generated' => true, 'reason' => null, 'pack' => $pack];
    }
}
