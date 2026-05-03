<?php

namespace App\Services;

use App\Exceptions\InsufficientFundsException;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

class LedgerService
{
    public function credit(
        Wallet $wallet,
        int $amountInSubunits,
        string $description,
        string $reference,
        array $metadata = []
    ): Transaction {
        return DB::transaction(function () use ($wallet, $amountInSubunits, $description, $reference, $metadata) {
            $wallet = Wallet::lockForUpdate()->findOrFail($wallet->id);

            $newBalance = (int) bcadd((string) $wallet->balance, (string) $amountInSubunits, 0);

            $wallet->balance = $newBalance;
            $wallet->save();

            return Transaction::create([
                'wallet_id' => $wallet->id,
                'type' => 'credit',
                'amount' => $amountInSubunits,
                'balance_after' => $newBalance,
                'description' => $description,
                'reference' => $reference,
                'metadata' => $metadata,
                'status' => 'completed',
            ]);
        });
    }

    public function debit(
        Wallet $wallet,
        int $amountInSubunits,
        string $description,
        string $reference,
        array $metadata = []
    ): Transaction {
        return DB::transaction(function () use ($wallet, $amountInSubunits, $description, $reference, $metadata) {
            $wallet = Wallet::lockForUpdate()->findOrFail($wallet->id);

            if (bccomp((string) $wallet->balance, (string) $amountInSubunits, 0) < 0) {
                throw new InsufficientFundsException($wallet->currency);
            }

            $newBalance = (int) bcsub((string) $wallet->balance, (string) $amountInSubunits, 0);

            $wallet->balance = $newBalance;
            $wallet->save();

            return Transaction::create([
                'wallet_id' => $wallet->id,
                'type' => 'debit',
                'amount' => $amountInSubunits,
                'balance_after' => $newBalance,
                'description' => $description,
                'reference' => $reference,
                'metadata' => $metadata,
                'status' => 'completed',
            ]);
        });
    }
}
