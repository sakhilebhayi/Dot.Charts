<?php

namespace App\Services;

use App\Models\KnowledgePack;
use App\Models\User;
use RuntimeException;

class KnowledgePackApprovalService
{
    public function __construct(
        private readonly DkpSigner $signer = new DkpSigner,
        private readonly OutboundMnpiGate $outboundGate = new OutboundMnpiGate,
    ) {}

    public function approve(KnowledgePack $pack, User $reviewer): KnowledgePack
    {
        if ($pack->status !== 'pending_approval') {
            throw new RuntimeException("Cannot approve a pack with status \"{$pack->status}\" -- only pending_approval packs may be approved.");
        }

        // Approval is the moment this pack's content becomes a real, signed,
        // eventually cross-platform artifact -- screen it here, before
        // signing, rather than trusting that whatever generated it already
        // avoided MNPI content. Fail-closed: a match blocks approval outright
        // rather than signing first and flagging after.
        $outboundResult = $this->outboundGate->screen([
            'title' => $pack->title,
            'summary' => $pack->summary,
            'payloads' => $pack->envelope['payloads'] ?? [],
        ]);

        if ($outboundResult['decision'] === 'reject') {
            $keywords = implode(', ', $outboundResult['matched_keywords']);
            throw new RuntimeException("Cannot approve pack \"{$pack->pack_id}\" -- outbound compliance gate rejected it (matched keywords: {$keywords}).");
        }

        $envelope = $pack->envelope;
        $envelope['signatures'] = $this->signer->sign($envelope);

        if (! $this->signer->verify($envelope)) {
            throw new RuntimeException('Approved Knowledge Pack failed self-verification -- refusing to persist an unverifiable artifact.');
        }

        $pack->update([
            'envelope' => $envelope,
            'status' => 'approved',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        return $pack->fresh();
    }

    public function reject(KnowledgePack $pack, User $reviewer, string $reason): KnowledgePack
    {
        if ($pack->status !== 'pending_approval') {
            throw new RuntimeException("Cannot reject a pack with status \"{$pack->status}\" -- only pending_approval packs may be rejected.");
        }

        if (trim($reason) === '') {
            throw new RuntimeException('A rejection reason is required.');
        }

        $pack->update([
            'status' => 'rejected',
            'rejected_reason' => $reason,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        return $pack->fresh();
    }
}
