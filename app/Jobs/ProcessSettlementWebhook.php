<?php

namespace App\Jobs;

use App\Events\UserNotified;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\LedgerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessSettlementWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public function __construct(private readonly array $payload) {}

    public function handle(LedgerService $ledger): void
    {
        $providerReference = $this->payload['provider_reference'] ?? null;
        $status = $this->payload['status'] ?? null;
        $amount = (int) ($this->payload['amount'] ?? 0);
        $walletId = $this->payload['wallet_id'] ?? null;

        if (! $providerReference || ! $walletId) {
            Log::warning('Settlement webhook missing required fields.', $this->payload);

            return;
        }

        // Double-check idempotency inside the job
        $alreadyProcessed = Transaction::where('status', 'completed')
            ->whereJsonContains('metadata->provider_reference', $providerReference)
            ->exists();

        if ($alreadyProcessed) {
            Log::info("Settlement webhook already processed: {$providerReference}");

            return;
        }

        $wallet = Wallet::find($walletId);

        if (! $wallet) {
            Log::error("Wallet not found for settlement webhook: {$walletId}");

            return;
        }

        if ($status === 'completed' && $amount > 0) {
            $reference = 'settlement_'.Str::uuid();

            $transaction = $ledger->credit(
                $wallet,
                $amount,
                'RMB payout settlement confirmed',
                $reference,
                [
                    'provider_reference' => $providerReference,
                    'webhook_payload' => $this->payload,
                ],
            );

            // Update any pending transaction with this provider_reference
            Transaction::where('status', 'pending')
                ->whereJsonContains('metadata->provider_reference', $providerReference)
                ->update(['status' => 'completed']);

            UserNotified::dispatch($transaction);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Settlement webhook job failed.', [
            'payload' => $this->payload,
            'error' => $exception->getMessage(),
        ]);
    }
}
