<?php

namespace App\Actions\Swap;

use App\Exceptions\InsufficientFundsException;
use App\Exceptions\SwapInProgressException;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ExchangeRateService;
use App\Services\LedgerService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ExecuteSwapAction
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly ExchangeRateService $rates,
    ) {}

    /**
     * @return array{debit: Transaction, credit: Transaction, rate: string, amount_out: int}
     *
     * @throws SwapInProgressException
     * @throws InsufficientFundsException
     */
    public function execute(User $user, int $amountInKobo, string $from = 'NGN', string $to = 'CNY', array $auditContext = []): array
    {
        $lock = Cache::lock("swap_lock:{$user->id}", 30);

        if (! $lock->get()) {
            throw new SwapInProgressException;
        }

        try {
            $rate = $this->rates->getRate($from, $to);

            $amountOut = (int) bcmul((string) $amountInKobo, $rate, 0);

            $swapReference = (string) Str::uuid();
            $metadata = array_merge([
                'swap_reference' => $swapReference,
                'swap_rate' => $rate,
                'from_currency' => $from,
                'to_currency' => $to,
            ], $auditContext);

            [$debitTx, $creditTx] = DB::transaction(function () use ($user, $amountInKobo, $amountOut, $from, $to, $swapReference, $metadata) {
                $fromWallet = $user->wallets()->where('currency', $from)->lockForUpdate()->firstOrFail();
                $toWallet = $user->wallets()->where('currency', $to)->lockForUpdate()->firstOrFail();

                $debit = $this->ledger->debit(
                    $fromWallet,
                    $amountInKobo,
                    "Swap {$from} to {$to}",
                    "debit_{$swapReference}",
                    $metadata,
                );

                $credit = $this->ledger->credit(
                    $toWallet,
                    $amountOut,
                    "Swap {$from} to {$to}",
                    "credit_{$swapReference}",
                    $metadata,
                );

                return [$debit, $credit];
            });

            return [
                'debit' => $debitTx,
                'credit' => $creditTx,
                'rate' => $rate,
                'amount_out' => $amountOut,
            ];
        } finally {
            $lock->release();
        }
    }
}
