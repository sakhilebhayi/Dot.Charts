<?php

namespace App\Sso;

use App\Mail\SsoClaimCodeMail;
use App\Models\User;
use Dot\SsoClient\Contracts\UserProvisioner;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * What the shared sign-in package needs to know about Charts' users table. The matching order and the claim flow live in
 * the package; this class only reads and writes users.
 *
 * Charts never verified an email address when people registered here, so EVERY pre-existing account is "unverified"
 * and reaches a person only through the emailed claim code (the package never links by email alone). Linking revokes
 * the account's old bearer tokens: those are the never-expiring tokens that live in browsers' localStorage, and the
 * person now has a session instead.
 */
class ChartsProvisioner implements UserProvisioner
{
    /** A readable-by-script hint that a session exists. Not a credential: the session cookie is HttpOnly. */
    public const HINT_COOKIE = 'charts_signed_in';

    public function findByUuid(string $uuid): ?Authenticatable
    {
        return User::query()->where('infodot_user_uuid', $uuid)->first();
    }

    public function findByEmail(string $email): ?Authenticatable
    {
        return User::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])->first();
    }

    public function uuidOf(Authenticatable $user): ?string
    {
        return $user instanceof User ? $user->infodot_user_uuid : null;
    }

    public function hasVerifiedEmail(Authenticatable $user): bool
    {
        return $user instanceof User && $user->email_verified_at !== null;
    }

    public function link(Authenticatable $user, string $uuid): void
    {
        if (! $user instanceof User) {
            return;
        }

        $user->forceFill(['infodot_user_uuid' => $uuid])->save();

        // The old localStorage tokens stop working the moment the account is linked.
        $user->tokens()->delete();
    }

    public function confirmEmail(Authenticatable $user): void
    {
        if ($user instanceof User && $user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }
    }

    public function create(array $claims): ?Authenticatable
    {
        $user = new User;
        $user->forceFill([
            'name' => mb_substr($claims['name'], 0, 255),
            'email' => $claims['email'],
            // The column is NOT NULL; nobody knows this value and local login is closed while SSO is on.
            'password' => Str::random(64),
            'email_verified_at' => now(),
            'infodot_user_uuid' => $claims['sub'],
        ])->save();

        return $user;
    }

    public function afterSignIn(Authenticatable $user, array $claims): void
    {
        Cookie::queue(cookie(self::HINT_COOKIE, '1', 0, '/', null, null, false, false, 'lax'));
    }

    public function sendClaimCode(Authenticatable $user, string $code, int $minutes): void
    {
        Mail::to($user->email)->send(new SsoClaimCodeMail($user->name, $code, $minutes));
    }
}
