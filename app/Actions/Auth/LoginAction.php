<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Hash;

final class LoginAction
{
    /**
     * @throws AuthenticationException
     */
    public function execute(string $email, string $password, string $deviceName = 'api'): string
    {
        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            throw new AuthenticationException('The provided credentials are incorrect.');
        }

        return $user->createToken($deviceName)->plainTextToken;
    }
}
