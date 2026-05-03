<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessSettlementWebhook;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function settlement(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'provider_reference' => ['required', 'string'],
            'status' => ['required', 'string', 'in:completed,failed,pending'],
            'amount' => ['required', 'integer', 'min:0'],
            'wallet_id' => ['required', 'integer'],
        ]);

        // Idempotency guard — early return if already processed
        $alreadyProcessed = Transaction::where('status', 'completed')
            ->whereJsonContains('metadata->provider_reference', $payload['provider_reference'])
            ->exists();

        if ($alreadyProcessed) {
            return response()->json(['message' => 'Already processed.'], 200);
        }

        ProcessSettlementWebhook::dispatch($payload)->onQueue('webhooks');

        return response()->json(['message' => 'Webhook received and queued for processing.'], 202);
    }
}
