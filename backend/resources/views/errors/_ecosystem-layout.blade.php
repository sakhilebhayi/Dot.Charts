<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $code }} — @yield('title') · Dot.Charts</title>
        <meta name="robots" content="noindex">
        {{-- The frontend build owns the docroot, so its favicon and logo
             assets resolve from these error pages too. --}}
        <link rel="icon" href="/favicon-32x32.png">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">

        @php
            // Every Dot platform, pulled from the shared ecosystem registry
            // (config/ecosystem.php, identical across all platforms) rather
            // than a fixed hand-picked subset -- add a platform to the
            // registry once and it shows up here automatically everywhere.
            // Self-exclusion uses this generator-verified literal name
            // rather than config('app.name'), since not every platform's
            // .env reliably has APP_NAME set correctly.
            $currentPlatformName = 'Dot.Charts';
            $discover = collect(config('ecosystem.platforms', []))
                ->reject(fn ($p) => ($p['name'] ?? null) === $currentPlatformName)
                ->reject(fn ($p) => ($p['active'] ?? true) === false)
                ->values()
                ->all();
        @endphp

        <style>
            :root {
                --bg: #020617;
                --bg-secondary: #0f172a;
                --text: #e5e7eb;
                --text-muted: #94a3b8;
                --accent: #22d3ee;
                --panel: rgba(15, 23, 42, 0.7);
                --line: rgba(148, 163, 184, 0.15);
            }
            * { box-sizing: border-box; }
            html { background: var(--bg); }
            body {
                margin: 0;
                font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background:
                    radial-gradient(ellipse at top, rgba(34, 211, 238, 0.06), transparent 55%),
                    var(--bg);
                color: var(--text-muted);
                min-height: 100dvh;
                display: flex;
                flex-direction: column;
            }
            a { color: inherit; }
            .mono { font-family: ui-monospace, 'SF Mono', Menlo, monospace; }
            .press { transition: transform 160ms cubic-bezier(0.23, 1, 0.32, 1); }
            .press:active { transform: scale(0.97); }
            .link-underline { background-image: linear-gradient(currentColor, currentColor); background-position: 0 100%; background-repeat: no-repeat; background-size: 0% 1px; transition: background-size 200ms cubic-bezier(0.23, 1, 0.32, 1); }
            @media (hover: hover) and (pointer: fine) {
                .link-underline:hover { background-size: 100% 1px; }
            }
        </style>
    </head>
    <body>
        <header style="border-bottom: 1px solid var(--line);">
            <nav style="max-width: 1400px; margin: 0 auto; padding: 14px 20px; display: flex; align-items: center; justify-content: space-between;">
                <a href="/" class="press" style="display: flex; align-items: center; gap: 10px; text-decoration: none;">
                    <img src="/images/logo.png" alt="Dot.Charts" style="height: 36px; width: auto;">
                </a>
                <a href="/backtest.html" class="link-underline" style="color: var(--accent); font-size: 14px; text-decoration: none; padding-bottom: 1px;">Run a backtest</a>
            </nav>
        </header>

        <main style="flex: 1; display: flex; align-items: center; padding: 48px 20px;">
            <div style="max-width: 640px; margin: 0 auto; width: 100%; text-align: center;">
                <div style="display: inline-flex; align-items: center; justify-content: center; width: 88px; height: 88px; border-radius: 24px; background: rgba(148, 163, 184, 0.08); margin-bottom: 28px;" aria-hidden="true">
                    @yield('icon')
                </div>

                <p class="mono" style="font-size: 13px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-muted); margin: 0 0 10px;">Error {{ $code }}</p>
                <h1 style="font-size: clamp(26px, 4vw, 34px); font-weight: 700; line-height: 1.2; margin: 0 0 14px; color: var(--text);">@yield('title')</h1>
                <p style="font-size: 16px; line-height: 1.6; color: var(--text-muted); margin: 0 0 32px;">@yield('message')</p>

                <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 12px; margin-bottom: 56px;">
                    @yield('actions')
                </div>

                @if (!empty($discover))
                    <div style="border-top: 1px solid var(--line); padding-top: 28px; text-align: left;">
                        <p class="mono" style="font-size: 12px; letter-spacing: 0.06em; text-transform: uppercase; color: var(--text-muted); margin: 0 0 14px; text-align: center;">While you're here — the rest of the Dot Ecosystem ({{ count($discover) }})</p>
                        <div style="display: flex; gap: 8px; overflow-x: auto; padding: 2px 2px 8px; scroll-snap-type: x proximity; -webkit-overflow-scrolling: touch;">
                            @foreach ($discover as $platform)
                                <a href="{{ $platform['url'] }}" class="press" style="flex: 0 0 auto; scroll-snap-align: start; display: flex; align-items: center; gap: 8px; padding: 7px 14px 7px 7px; background: var(--bg-secondary); border: 1px solid var(--line); border-radius: 999px; text-decoration: none; white-space: nowrap;">
                                    <span class="material-symbols-rounded" aria-hidden="true" style="display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 50%; background: {{ $platform['accent'] ?? 'var(--accent)' }}; color: #ffffff; font-size: 16px; flex-shrink: 0;">{{ $platform['icon'] ?? 'apps' }}</span>
                                    <span style="font-weight: 600; font-size: 13px; color: var(--text);">{{ $platform['name'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </main>

        <footer style="border-top: 1px solid var(--line); padding: 24px 20px;">
            <div style="max-width: 1400px; margin: 0 auto; display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 16px;">
                <p class="mono" style="font-size: 12px; color: var(--text-muted); margin: 0;">&copy; {{ date('Y') }} Dot.Charts — part of the Dot Ecosystem.</p>
            </div>
        </footer>
    </body>
</html>
