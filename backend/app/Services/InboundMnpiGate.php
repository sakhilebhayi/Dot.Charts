<?php

namespace App\Services;

use App\Services\Concerns\ScreensMnpiContent;

class InboundMnpiGate
{
    use ScreensMnpiContent;

    /**
     * Screens a raw pack envelope-shaped array (arriving from another
     * platform) against the instrument map. Fail-closed: any keyword match
     * rejects. No attempt to judge "already public" from free text.
     */
    public function screen(array $pack): array
    {
        return $this->screenContent($pack, 'inbound');
    }
}
