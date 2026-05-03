<?php

namespace App\Actions\Wallet;

use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\LedgerService;

final class DebitWalletAction
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function execute(
        Wallet $wallet,
        int $amountInSubunits,
        string $description,
        string $reference,
        array $metadata = []
    ): Transaction {
        return $this->ledger->debit($wallet, $amountInSubunits, $description, $reference, $metadata);
    }
}
