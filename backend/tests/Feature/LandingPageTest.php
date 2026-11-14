<?php

namespace Tests\Feature;

use App\Models\BacktestRun;
use App\Models\CustomStrategy;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The signed-in landing (frontend/home.html) is a static page that fills itself from the API. These tests hold both
 * halves: the shipped markup (one primary action, the original logo, honest empty states, no figures of its own) and
 * the four endpoints it reads (answer for every kind of account, and return that account's records only).
 */
class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    /** SHA-256 of the original logo artwork. The landing presents this file; it never alters or replaces it. */
    private const LOGO_SHA256 = 'acb7b9078ea239067a3d4283253abcaf983b70d486b6f184a168eaa508bcc06a';

    private const LIVE_PLATFORM_KEYS = [
        'agents', 'analytics', 'auction', 'central', 'charts', 'design', 'docs', 'dopemine', 'ehail', 'emall', 'engage',
        'files', 'finance', 'forms', 'hr', 'memory', 'mines', 'notify', 'press', 'projects', 'pulse', 'sheet', 'tasks',
        'tutor', 'infodot',
    ];

    private function frontend(string $path): string
    {
        $file = base_path('../frontend/'.$path);
        $this->assertFileExists($file);

        return (string) file_get_contents($file);
    }

    private function landing(): \DOMXPath
    {
        $document = new \DOMDocument;
        @$document->loadHTML($this->frontend('home.html'));

        return new \DOMXPath($document);
    }

    public function test_the_landing_has_exactly_one_primary_action_and_it_leads_to_a_built_page(): void
    {
        $actions = $this->landing()->query('//*[@data-primary-action]');

        $this->assertSame(1, $actions->length, 'the landing carries exactly one primary action');
        $this->assertSame('a', $actions->item(0)->nodeName);
        $this->assertSame('/backtest.html', $actions->item(0)->getAttribute('href'));

        // The target and the landing itself are both real pages in the Vite build.
        $this->frontend('backtest.html');
        $vite = $this->frontend('vite.config.js');
        $this->assertStringContainsString("resolve(__dirname, 'backtest.html')", $vite);
        $this->assertStringContainsString("resolve(__dirname, 'home.html')", $vite);
    }

    public function test_signing_in_and_registering_arrive_at_the_landing(): void
    {
        $this->assertStringContainsString("window.location.href = '/home.html';", $this->frontend('src/login.js'));
        $this->assertStringContainsString("window.location.href = '/home.html';", $this->frontend('src/register.js'));
    }

    public function test_the_landing_shows_the_original_logo_file(): void
    {
        $logo = base_path('../frontend/public/images/logo.png');

        $this->assertFileExists($logo);
        $this->assertSame(self::LOGO_SHA256, hash_file('sha256', $logo), 'the logo artwork must not change');

        // The dashboard has no body logo: the original file sits once, in the shared app header (R-52: printed by
        // src/app-header.js; the brand link's aria-label names it, so the image itself has an empty alt).
        $this->assertSame(0, $this->landing()->query('//main//img')->length);
        $header = $this->frontend('src/app-header.js');
        $this->assertSame(1, substr_count($header, "img.src = '/images/logo.png';"));
        $this->assertStringContainsString("label: 'Dot.Charts home'", $header);
        $this->assertSame(1, $this->landing()->query('//*[@id="app-header"]')->length);
    }

    public function test_the_empty_states_are_what_the_page_ships_with(): void
    {
        $xpath = $this->landing();

        // The dashboard (runs table and selected-run panel) starts hidden and the runs empty state is the shipped
        // copy; the two lower lists start on their empty states and hidden lists. The script only swaps them when the
        // API returns that account's own records.
        $this->assertTrue($xpath->query("//*[@id='desk']")->item(0)->hasAttribute('hidden'));
        foreach (['runs', 'strategies', 'journal'] as $panel) {
            $empty = $xpath->query("//*[@id='{$panel}Empty'][@data-empty-state]");
            $this->assertSame(1, $empty->length, "{$panel} has an empty state");
            $this->assertNotSame('', trim($empty->item(0)->textContent));
        }
        foreach (['strategies', 'journal'] as $panel) {
            $this->assertFalse($xpath->query("//*[@id='{$panel}Empty']")->item(0)->hasAttribute('hidden'));
            $this->assertTrue($xpath->query("//*[@id='{$panel}List']")->item(0)->hasAttribute('hidden'));
        }
    }

    public function test_the_full_disclaimer_is_always_in_the_page_and_never_shortened(): void
    {
        $disclaimer = $this->landing()->query("//*[@id='disclaimer']");
        $this->assertSame(1, $disclaimer->length);
        $this->assertSame(
            'Disclaimer. Educational analysis, not financial advice. A backtest describes the past and does not predict future results. Do your own research and never trade more than you can afford to lose.',
            trim(preg_replace('/\s+/', ' ', $disclaimer->item(0)->textContent)),
        );
        // It sits outside the scrolling region, so it is on screen whatever the person has scrolled to.
        $this->assertSame(0, $this->landing()->query("//main//*[@id='disclaimer']")->length);
    }

    public function test_the_dashboard_keeps_no_inline_styles_or_scripts_and_no_search_pill(): void
    {
        $html = $this->frontend('home.html');
        $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html);
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)/i', $html);
        $this->assertSame(0, $this->landing()->query('//input[@type="search"] | //*[@role="search"]')->length);
    }

    public function test_the_selected_run_is_validated_and_the_curve_is_fetched_for_that_run_only(): void
    {
        $script = $this->frontend('src/home.js');
        // ?run= is only honoured when it names a run that is in the loaded rows.
        $this->assertStringContainsString('state.runs.some((r) => r.id === id)', $script);
        $this->assertStringContainsString("state.runs.find((r) => r.id === wanted)", $script);
        // The list carries no curve; the one selected run is fetched.
        $this->assertStringContainsString('get(`/backtests/${run.id}`)', $script);
        $this->assertStringNotContainsString('results.equity_curve', str_replace('full.results && full.results.equity_curve', '', $script));
    }

    public function test_the_shipped_page_states_no_figures_of_its_own(): void
    {
        $text = $this->landing()->query('//body')->item(0)->textContent;

        // Every number a person can see on the landing is written by the script from an API record. The markup
        // itself carries none, so there is nothing to go stale and nothing that could read as a claim. (The runs table's
        // column headings, such as Win rate, are labels of stored fields, not statements, so that phrase is allowed.)
        $this->assertDoesNotMatchRegularExpression('/\d/', $text);
        $this->assertDoesNotMatchRegularExpression('/guarantee|profit|outperform|returns of/i', $text);
        $this->assertStringContainsString('not financial advice', $text);
    }

    public function test_the_way_back_to_infodot_is_the_shared_switcher(): void
    {
        // The two plain links ("InfoDot", "Platforms") were superseded by the shared Dot Apps switcher, which the
        // header module mounts into the app header's tools area (R-52). InfoDot is its first tile and "See all platforms" is its link.
        $this->assertStringContainsString("from './app-header.js';", $this->frontend('src/home.js'));
        $this->assertStringContainsString('mountSwitcher(switcherRoot, m,', $this->frontend('src/app-header.js'));
        $this->assertStringNotContainsString('infodot.co.za', $this->frontend('home.html'));
        $this->assertSame('InfoDot', config('ecosystem.platforms.infodot.name'));
    }

    public function test_everything_the_landing_links_to_inside_the_app_exists(): void
    {
        foreach ($this->landing()->query('//a[starts-with(@href, "/")]') as $link) {
            $href = $link->getAttribute('href');
            $this->frontend($href === '/' ? 'index.html' : ltrim($href, '/'));
        }
    }

    public function test_the_landing_reads_answer_for_a_member_and_for_a_platform_operator(): void
    {
        $member = User::factory()->create();
        $operator = User::factory()->create();
        $operator->forceFill(['is_platform_operator' => true])->save();

        foreach ([$member, $operator] as $user) {
            Sanctum::actingAs($user);

            $this->getJson('/api/me')->assertOk()->assertJsonPath('name', $user->name);
            foreach (['/api/backtests', '/api/strategies', '/api/journal-entries'] as $endpoint) {
                // A brand-new account: the lists are empty, so the landing keeps its empty states.
                $this->getJson($endpoint)->assertOk()->assertJsonPath('total', 0)->assertJsonCount(0, 'data');
            }
        }
    }

    public function test_the_landing_reads_return_only_the_accounts_own_records(): void
    {
        $owner = User::factory()->create(['name' => 'Thandi Mokoena']);
        $someoneElse = User::factory()->create();

        BacktestRun::factory()->count(5)->create(['user_id' => $owner->id]);
        CustomStrategy::factory()->count(2)->create(['user_id' => $owner->id]);
        JournalEntry::factory()->create(['user_id' => $owner->id]);

        BacktestRun::factory()->count(7)->create(['user_id' => $someoneElse->id]);
        CustomStrategy::factory()->count(4)->create(['user_id' => $someoneElse->id]);
        JournalEntry::factory()->count(3)->create(['user_id' => $someoneElse->id]);

        Sanctum::actingAs($owner);

        $this->getJson('/api/me')->assertOk()->assertJsonPath('name', 'Thandi Mokoena');
        // "All 5 runs" is the one count the landing prints, and it is this total.
        $this->getJson('/api/backtests')->assertOk()->assertJsonPath('total', 5)->assertJsonCount(5, 'data');
        $this->getJson('/api/strategies')->assertOk()->assertJsonPath('total', 2)->assertJsonCount(2, 'data');
        $this->getJson('/api/journal-entries')->assertOk()->assertJsonPath('total', 1)->assertJsonCount(1, 'data');
    }

    public function test_the_platform_lists_name_only_live_platforms(): void
    {
        $this->assertSame([], array_values(array_diff(array_keys(config('ecosystem.platforms')), self::LIVE_PLATFORM_KEYS)));

        // The frontend keeps NO mirror of the registry any more: it reads the endpoint (see EcosystemSwitcherEndpointTest).
        $this->assertSame(0, preg_match("/name: '[^']+'/", $this->frontend('src/ecosystem.js')));
    }

    public function test_the_frontend_reads_the_registry_from_the_endpoint_and_keeps_no_copy(): void
    {
        $this->assertStringContainsString('/ecosystem/switcher', $this->frontend('src/registry.js'));
        $this->assertSame(0, preg_match('/https:\/\/[a-z]+\.infodot\.co\.za/', $this->frontend('src/ecosystem.js')));
    }

    public function test_nothing_shipped_points_at_the_dead_infodot_app_domain(): void
    {
        $shipped = array_merge(
            glob(base_path('../frontend/*.html')),
            array_filter(glob(base_path('../frontend/src/*')), 'is_file'),
            glob(base_path('config/*.php')),
            glob(base_path('resources/views/errors/*.php')),
            [base_path('.env.example')],
        );

        $this->assertGreaterThan(20, count($shipped));
        foreach ($shipped as $file) {
            $this->assertStringNotContainsString('infodot.app', (string) file_get_contents($file), $file);
        }
    }

    public function test_every_page_with_the_app_header_offers_the_way_back_to_the_landing(): void
    {
        // R-52: one header module for every page. The brand link keeps the signed-out destination ("/", the public
        // console) and leads to /home.html when a token is held; the old per-page swap module is gone.
        $module = $this->frontend('src/app-header.js');
        $this->assertStringContainsString("href: signedIn ? '/home.html' : '/'", $module);
        $this->assertStringNotContainsString('setToken', $module);
        $this->assertFileDoesNotExist(base_path('../frontend/src/home-link.js'));

        foreach (['backtest', 'history', 'journal', 'options', 'strategy-builder', 'main', 'home'] as $script) {
            $this->assertStringContainsString("'./app-header.js'", $this->frontend("src/{$script}.js"), $script);
        }
        foreach (['index', 'home', 'backtest', 'history', 'journal', 'options', 'strategy-builder'] as $page) {
            $this->assertStringContainsString('<div id="app-header" data-page="', $this->frontend("{$page}.html"), $page);
        }
    }
}
