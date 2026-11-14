<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * The platform list for the shared "Dot Apps" switcher in the static frontend (dot-ui static renderer).
 *
 * This is the ONE place the frontend learns the registry from: config/ecosystem.php, the same file the error pages
 * read. The page script must not carry a second copy. The answer is deliberately NOT personal: Dot.Charts has its
 * own sign-in and no InfoDot company concept or service secret, so it cannot know which platforms the person's
 * company holds, and nothing here claims to. The switcher therefore runs in its honest "own sign-in" mode: core
 * platforms through InfoDot's /go (InfoDot decides and signs the person in) and a visible "could not check from
 * here" line. See docs/dot-ui-switcher.md.
 */
class EcosystemSwitcherController extends Controller
{
    /** This platform's key in the registry. */
    public const CURRENT = 'charts';

    public function __invoke(): JsonResponse
    {
        $platforms = [];

        foreach ((array) config('ecosystem.platforms', []) as $key => $platform) {
            if (! is_string($key) || ! is_array($platform) || ! isset($platform['name'], $platform['url'])) {
                continue;
            }

            $platforms[] = [
                'key' => $key,
                'name' => (string) $platform['name'],
                'url' => (string) $platform['url'],
                'accent' => (string) ($platform['accent'] ?? ''),
            ];
        }

        return response()->json([
            'state' => 'degraded',
            'current' => self::CURRENT,
            'infodot_url' => (string) config('ecosystem.platforms.infodot.url', 'https://infodot.co.za'),
            'notice' => 'Could not check your platforms from here',
            'notice_detail' => 'Dot.Charts has its own sign-in and does not know which platforms your company has, so these open through InfoDot, which checks what you can use.',
            'sub' => 'The core Dot platforms, opened through InfoDot.',
            'platforms' => $platforms,
        ])->header('Cache-Control', 'public, max-age=300');
    }
}
