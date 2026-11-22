<?php

namespace Tests\Feature\Sso;

/** The SPA's `next=` becomes the server-side deep link, validated twice, and nothing else can set it. */
class DeepLinkTest extends ChartsSsoTestCase
{
    public function test_an_in_app_page_comes_back_exactly(): void
    {
        $this->signInAs('sub-a', 'a@example.test', ['next' => '/journal.html?entry=7'])->assertRedirect('/journal.html?entry=7');
    }

    public function test_every_charts_page_is_an_allowed_deep_link(): void
    {
        foreach (['/home.html', '/backtest.html', '/history.html', '/journal.html', '/options.html', '/strategy-builder.html', '/'] as $path) {
            $this->flushSession();
            $this->app['auth']->forgetGuards();
            $this->signInAs('sub-a', 'a@example.test', ['next' => $path])->assertRedirect($path);
        }
    }

    public function test_hostile_or_non_page_destinations_fall_back_to_the_landing_page(): void
    {
        $bad = [
            '//evil.test/x', 'https://evil.test/home.html', 'http://platform.test/home.html', '/\\evil.test', '/%2f%2fevil.test',
            'javascript:alert(1)', '/api/me', '/sso/login', '/sso/logout', '/login.html', '/register.html', '/sanctum/csrf-cookie',
            '/home.html/../../api/me', '/guardian/health', "/home.html\r\nX: y", '/unknown.html', '',
        ];

        foreach ($bad as $next) {
            $this->flushSession();
            $this->app['auth']->forgetGuards();
            $r = $this->signInAs('sub-a', 'a@example.test', ['next' => $next]);
            $location = $r->headers->get('Location');
            $this->assertTrue(
                in_array($location, ['/dashboard', 'https://platform.test/dashboard'], true),
                "next=[$next] ended at [$location]",
            );
            $this->assertStringNotContainsString('evil', (string) $location);
        }
    }

    public function test_the_deep_link_is_not_read_from_the_callback(): void
    {
        $authorize = $this->startLogin(['next' => '/history.html']);
        $code = $this->idp->approve($authorize, ['sub' => 'sub-a', 'email' => 'a@example.test']);

        $this->get(self::ORIGIN.'/sso/callback?'.http_build_query(['code' => $code, 'state' => $authorize['state'], 'next' => 'https://evil.test', 'redirect' => '//evil.test']))
            ->assertRedirect('/history.html');
    }

    public function test_a_second_sign_in_without_next_forgets_an_older_next(): void
    {
        $this->startLogin(['next' => '/history.html']);
        $authorize = $this->startLogin(['next' => '/nope']);
        $this->finishLogin($authorize, ['sub' => 'sub-a', 'email' => 'a@example.test'])->assertRedirect('/dashboard');
    }

    public function test_the_landing_path_reaches_the_home_page(): void
    {
        $this->get('/dashboard')->assertRedirect('/home.html');
    }
}
