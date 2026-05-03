<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use PragmaRX\Google2FA\Google2FA;

final class VerifyTwoFactorAction
{
    private const TTL = 900;

    public function __construct(private readonly Google2FA $google2fa) {}

    /**
     * @throws \RuntimeException
     */
    public function execute(User $user, string $code): void
    {
        if (! $user->google2fa_secret) {
            throw new \RuntimeException('Two-factor authentication is not configured for this account.');
        }

        $valid = $this->google2fa->verifyKey($user->google2fa_secret, $code);

        if (! $valid) {
            throw new \RuntimeException('Invalid or expired 2FA code.');
        }

        Cache::put($this->cacheKey($user->id), true, self::TTL);
    }

    public static function cacheKey(int|string $userId): string
    {
        return "2fa_verified:{$userId}";
    }
}
