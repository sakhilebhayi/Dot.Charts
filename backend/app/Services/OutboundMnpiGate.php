<?php

namespace App\Services;

use App\Services\Concerns\ScreensMnpiContent;

/**
 * The outbound half of the compliance gate InboundMnpiGate's docblock
 * implied but never built. Screens a Knowledge Pack's own content against
 * the same instrument map, at the point it's about to be approved and
 * signed -- before that, a pack is unsigned and inert; approval is the
 * moment it becomes a real, retrievable, eventually cross-platform
 * artifact. Same fail-closed matching, same DkpGateDecision audit trail as
 * the inbound gate, distinguished only by direction='outbound'.
 */
class OutboundMnpiGate
{
    use ScreensMnpiContent;

    public function screen(array $pack): array
    {
        return $this->screenContent($pack, 'outbound');
    }
}
