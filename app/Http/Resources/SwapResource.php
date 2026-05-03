<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SwapResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'debit' => new TransactionResource($this->resource['debit']),
            'credit' => new TransactionResource($this->resource['credit']),
            'rate' => $this->resource['rate'],
            'amount_out' => $this->resource['amount_out'],
        ];
    }
}
