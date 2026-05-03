<?php

namespace App\Http\Controllers\Api;

use App\Actions\Auth\VerifyTwoFactorAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TwoFactorController extends Controller
{
    public function verify(Request $request, VerifyTwoFactorAction $action): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'digits:6'],
        ]);

        try {
            $action->execute($request->user(), $validated['code']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => '2FA verified. You may now access protected endpoints for 15 minutes.',
        ]);
    }
}
