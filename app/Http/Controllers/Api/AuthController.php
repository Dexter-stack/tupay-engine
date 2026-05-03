<?php

namespace App\Http\Controllers\Api;

use App\Actions\Auth\LoginAction;
use App\Http\Controllers\Controller;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function login(Request $request, LoginAction $action): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        try {
            $token = $action->execute(
                $validated['email'],
                $validated['password'],
                $request->userAgent() ?? 'api',
            );
        } catch (AuthenticationException $e) {
            return response()->json(['message' => $e->getMessage()], 401);
        }

        return response()->json([
            'message' => 'Login successful. Please verify your 2FA code to access protected endpoints.',
            'token' => $token,
        ]);
    }
}
