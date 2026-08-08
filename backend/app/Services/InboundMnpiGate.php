<?php

namespace App\Services;

use App\Events\ComplianceGateRejected;
use App\Models\DkpGateDecision;

class InboundMnpiGate
{
    /**
     * Screens a raw pack envelope-shaped array against the instrument map.
     * Fail-closed: any keyword match rejects. No attempt to judge
     * "already public" from free text.
     */
    public function screen(array $pack): array
    {
        $haystack = $this->buildHaystack($pack);
        $matchedKeywords = [];

        foreach (config('dkp_instrument_map', []) as $keyword => $instruments) {
            if (str_contains($haystack, strtolower($keyword))) {
                $matchedKeywords[] = $keyword;
            }
        }

        $decision = empty($matchedKeywords) ? 'pass' : 'reject';
        $reason = $decision === 'reject' ? 'MNPI content-materiality match' : null;

        DkpGateDecision::create([
            'direction' => 'inbound',
            'decision' => $decision,
            'reason' => $reason,
            'matched_keywords' => $matchedKeywords ?: null,
            'pack_title' => $pack['title'] ?? '',
            'pack_summary' => $pack['summary'] ?? '',
            'decided_at' => now(),
        ]);

        if ($decision === 'reject') {
            ComplianceGateRejected::dispatch($pack['title'] ?? '', $matchedKeywords);
        }

        return ['decision' => $decision, 'reason' => $reason, 'matched_keywords' => $matchedKeywords];
    }

    private function buildHaystack(array $pack): string
    {
        $parts = [$pack['title'] ?? '', $pack['summary'] ?? ''];

        foreach ($pack['payloads'] ?? [] as $payload) {
            $parts[] = $this->stringify($payload['body'] ?? []);
        }

        return strtolower(implode(' ', $parts));
    }

    private function stringify(mixed $value): string
    {
        if (is_array($value)) {
            return implode(' ', array_map($this->stringify(...), $value));
        }

        return (string) $value;
    }
}
