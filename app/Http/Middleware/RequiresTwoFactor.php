<?php

namespace App\Http\Middleware;

use App\Actions\Auth\VerifyTwoFactorAction;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class RequiresTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! Cache::has(VerifyTwoFactorAction::cacheKey($user->id))) {
            return response()->json([
                'message' => '2FA verification required.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
