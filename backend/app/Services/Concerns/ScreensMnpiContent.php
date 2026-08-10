<?php

namespace App\Services\Concerns;

use App\Events\ComplianceGateRejected;
use App\Models\DkpGateDecision;

/**
 * Shared content-materiality screen used by both InboundMnpiGate (content
 * arriving from another platform) and OutboundMnpiGate (a pack about to be
 * approved/signed here). Same matching logic, same fail-closed rule, same
 * audit trail -- the only thing that differs between the two call sites is
 * the 'direction' recorded on the decision row.
 */
trait ScreensMnpiContent
{
    /**
     * Screens a raw pack envelope-shaped array against the instrument map.
     * Fail-closed: any keyword match rejects. No attempt to judge
     * "already public" from free text.
     */
    protected function screenContent(array $pack, string $direction): array
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
            'direction' => $direction,
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
