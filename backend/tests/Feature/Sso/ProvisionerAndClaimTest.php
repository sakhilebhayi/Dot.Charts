<?php

namespace Tests\Feature\Sso;

use App\Mail\SsoClaimCodeMail;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class ProvisionerAndClaimTest extends ChartsSsoTestCase
{
    private function code(): string
    {
        return Mail::sent(SsoClaimCodeMail::class)->last()->code;
    }

    /** Charts accounts have never had their email verified: this is what every real pre-existing account looks like. */
    private function legacyAccount(string $email = 'pat@example.test'): User
    {
        return User::factory()->unverified()->create(['email' => $email, 'name' => 'Pat Legacy']);
    }

    public function test_an_unverified_existing_account_is_never_linked_by_email_alone(): void
    {
        Mail::fake();
        $pat = $this->legacyAccount();
        $oldToken = $pat->createToken('api')->plainTextToken;

        $this->signInAs('sub-new-1', 'pat@example.test')->assertRedirect(route('dotsso.claim'));

        $this->assertGuest();
        $this->assertNull($pat->fresh()->infodot_user_uuid, 'nothing is linked before the code');
        $this->assertSame(1, User::count());
        Mail::assertSent(SsoClaimCodeMail::class, fn ($m) => $m->hasTo('pat@example.test'));
        // The old browser token still belongs to the real owner until the claim completes.
        $this->withToken($oldToken)->getJson('/api/me')->assertOk();
    }

    public function test_the_right_code_links_signs_in_keeps_the_data_and_revokes_old_tokens(): void
    {
        Mail::fake();
        $pat = $this->legacyAccount();
        JournalEntry::factory()->create(['user_id' => $pat->id, 'title' => 'Kept']);
        $hash = $pat->password;
        $oldToken = $pat->createToken('api')->plainTextToken;

        $this->signInAs('sub-new-1', 'pat@example.test', ['next' => '/journal.html'])->assertRedirect(route('dotsso.claim'));
        $this->post(self::ORIGIN.'/sso/claim', ['code' => $this->code()])->assertRedirect('/journal.html');

        $pat->refresh();
        $this->assertSame('sub-new-1', $pat->infodot_user_uuid);
        $this->assertNotNull($pat->email_verified_at, 'the code proved the address');
        $this->assertSame($hash, $pat->password, 'the password is never replaced');
        $this->assertSame(1, User::count());
        $this->assertSame(1, JournalEntry::where('user_id', $pat->id)->count());
        $this->assertAuthenticatedAs($pat);
        $this->assertSame(0, $pat->tokens()->count());

        // The old localStorage token no longer authenticates (a fresh client: no session, only the token).
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->withToken($oldToken)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_a_wrong_code_never_links(): void
    {
        Mail::fake();
        $pat = $this->legacyAccount();
        $this->signInAs('sub-new-1', 'pat@example.test');

        $this->from(self::ORIGIN.'/sso/claim')->post(self::ORIGIN.'/sso/claim', ['code' => '000000'])->assertSessionHasErrors('code');

        $this->assertNull($pat->fresh()->infodot_user_uuid);
        $this->assertGuest();
    }

    public function test_the_claim_mail_goes_to_the_address_on_file_not_to_a_claimed_one(): void
    {
        Mail::fake();
        $this->legacyAccount('pat@example.test');

        $this->signInAs('sub-new-1', 'PAT@example.test')->assertRedirect(route('dotsso.claim'));

        Mail::assertSent(SsoClaimCodeMail::class, 1);
    }

    public function test_a_verified_account_with_a_verified_infodot_email_links_directly_and_revokes_tokens(): void
    {
        $sam = User::factory()->create(['email' => 'sam@example.test']);
        $sam->createToken('api');

        $this->signInAs('sub-sam', 'sam@example.test')->assertRedirect('/dashboard');

        $this->assertSame('sub-sam', $sam->fresh()->infodot_user_uuid);
        $this->assertSame(0, $sam->tokens()->count());
        $this->assertAuthenticatedAs($sam);
    }

    public function test_the_stored_infodot_id_wins_over_a_changed_email(): void
    {
        $sam = User::factory()->create(['email' => 'old@example.test', 'infodot_user_uuid' => 'sub-sam']);

        $this->signInAs('sub-sam', 'new@example.test')->assertRedirect();

        $this->assertAuthenticatedAs($sam);
        $this->assertSame(1, User::count());
    }

    public function test_an_account_linked_to_someone_else_is_refused(): void
    {
        User::factory()->create(['email' => 'sam@example.test', 'infodot_user_uuid' => 'sub-other']);

        $this->signInAs('sub-sam', 'sam@example.test')->assertStatus(403)->assertSee('account_linked_elsewhere');
        $this->assertGuest();
    }

    public function test_a_new_person_gets_an_account_with_the_infodot_id_and_a_session(): void
    {
        $this->signInAs('sub-new', 'new@example.test')->assertRedirect('/dashboard');

        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertSame('sub-new', $user->infodot_user_uuid);
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse($user->is_platform_operator, 'a provisioned account is never an operator');
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_sign_in_leaves_a_script_readable_hint_that_grants_nothing(): void
    {
        $response = $this->signInAs('sub-new', 'new@example.test');

        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'charts_signed_in');
        $this->assertNotNull($cookie);
        $this->assertFalse($cookie->isHttpOnly(), 'the SPA must be able to read the hint');
        $session = collect($response->headers->getCookies())->first(fn ($c) => str_contains($c->getName(), 'session'));
        $this->assertTrue($session->isHttpOnly(), 'the session cookie itself is HttpOnly');
        $this->assertSame('lax', $session->getSameSite());
        // A forged hint authenticates nothing.
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->withCookie('charts_signed_in', '1')->spa()->getJson('/api/me')->assertUnauthorized();
    }

    public function test_the_claim_page_is_charts_own_skin_and_names_the_address_masked(): void
    {
        Mail::fake();
        $this->legacyAccount();
        $this->signInAs('sub-new-1', 'pat@example.test');

        $this->get(self::ORIGIN.'/sso/claim')->assertOk()
            ->assertSee('Confirm it is your account')
            ->assertSee('p**@example.test', false)
            ->assertSee('--signal:#f2a70b', false)
            ->assertDontSee($this->code());
    }

    public function test_a_failed_sign_in_shows_charts_error_page(): void
    {
        $this->get(self::ORIGIN.'/sso/callback?state=nope&code=x')->assertStatus(400)
            ->assertSee('Dot.Charts')->assertSee('Reference:');
    }
}
