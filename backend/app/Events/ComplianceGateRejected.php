<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class ComplianceGateRejected
{
    use Dispatchable;

    public function __construct(
        public readonly string $packTitle,
        public readonly array $matchedKeywords,
    ) {
    }
}
