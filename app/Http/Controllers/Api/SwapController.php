<?php

namespace App\Http\Controllers\Api;

use App\Actions\Swap\ExecuteSwapAction;
use App\Exceptions\InsufficientFundsException;
use App\Exceptions\SwapInProgressException;
use App\Http\Controllers\Controller;
use App\Http\Resources\SwapResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SwapController extends Controller
{
    public function swap(Request $request, ExecuteSwapAction $action): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $result = $action->execute($request->user(), $validated['amount']);
        } catch (SwapInProgressException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (InsufficientFundsException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new SwapResource($result))
            ->response()
            ->setStatusCode(201);
    }
}
