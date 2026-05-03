<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LedgerController extends Controller
{
    public function index(Request $request, int $wallet_id): AnonymousResourceCollection|JsonResponse
    {
        $wallet = Wallet::find($wallet_id);

        if (! $wallet || $wallet->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Wallet not found.'], 404);
        }

        $transactions = $wallet->transactions()
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return TransactionResource::collection($transactions);
    }
}
