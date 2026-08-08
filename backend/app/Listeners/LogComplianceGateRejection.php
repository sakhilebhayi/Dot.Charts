<?php

namespace App\Listeners;

use App\Events\ComplianceGateRejected;
use Illuminate\Support\Facades\Log;

class LogComplianceGateRejection
{
    public function handle(ComplianceGateRejected $event): void
    {
        Log::info('trading.compliance.gate_rejected', [
            'pack_title' => $event->packTitle,
            'matched_keywords' => $event->matchedKeywords,
        ]);
    }
}
