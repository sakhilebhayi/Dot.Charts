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

        $images = $this->landing()->query('//main//img[@src="/images/logo.png"]');
        $this->assertSame(1, $images->length);
        $this->assertSame('Dot.Charts', $images->item(0)->getAttribute('alt'));
    }

    public function test_the_empty_states_are_what_the_page_ships_with(): void
    {
        $xpath = $this->landing();

        // Each panel starts on its empty state and with its list hidden; the script only swaps them when the API
        // returns that account's own records.
        foreach (['runs', 'strategies', 'journal'] as $panel) {
            $empty = $xpath->query("//*[@id='{$panel}Empty'][@data-empty-state]");
            $this->assertSame(1, $empty->length, "{$panel} has an empty state");
            $this->assertFalse($empty->item(0)->hasAttribute('hidden'));
            $this->assertNotSame('', trim($empty->item(0)->textContent));
            $this->assertTrue($xpath->query("//*[@id='{$panel}List']")->item(0)->hasAttribute('hidden'));
        }
    }

    public function test_the_shipped_page_states_no_figures_of_its_own(): void
    {
        $text = $this->landing()->query('//body')->item(0)->textContent;

        // Every number a person can see on the landing is written by the script from an API record. The markup
        // itself carries none, so there is nothing to go stale and nothing that could read as a claim.
        $this->assertDoesNotMatchRegularExpression('/\d/', $text);
        $this->assertDoesNotMatchRegularExpression('/guarantee|profit|outperform|win rate|returns of/i', $text);
        $this->assertStringContainsString('not financial advice', $text);
    }

    public function test_the_way_back_to_infodot_is_two_plain_links(): void
    {
        $xpath = $this->landing();

        $home = $xpath->query('//a[@href="https://infodot.co.za/home"]');
        $platforms = $xpath->query('//a[@href="https://infodot.co.za/platforms"]');

        $this->assertSame(1, $home->length);
        $this->assertSame('InfoDot', trim($home->item(0)->textContent));
        $this->assertSame(1, $platforms->length);
        $this->assertSame('Platforms', trim($platforms->item(0)->textContent));
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

        // The frontend keeps a static mirror of the registry (frontend/src/ecosystem.js): same rule, by name.
        $liveNames = array_column(config('ecosystem.platforms'), 'name');
        preg_match_all("/name: '([^']+)'/", $this->frontend('src/ecosystem.js'), $matches);

        $this->assertNotEmpty($matches[1]);
        $this->assertSame([], array_values(array_diff($matches[1], $liveNames)));
    }
}
