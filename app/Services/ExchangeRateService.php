<?php

namespace App\Services;

use App\Models\ExchangeRate;
use Illuminate\Support\Facades\Cache;

class ExchangeRateService
{
    private const CACHE_TTL = 60;

    private const FALLBACK_RATES = [
        'NGN_CNY' => '0.00520000',
        'CNY_NGN' => '192.30769231',
    ];

    public function getRate(string $from, string $to): string
    {
        $cacheKey = "exchange_rate:{$from}_{$to}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($from, $to) {
            $rate = ExchangeRate::where('from_currency', $from)
                ->where('to_currency', $to)
                ->latest('fetched_at')
                ->value('rate');

            if ($rate !== null) {
                return (string) $rate;
            }

            return self::FALLBACK_RATES["{$from}_{$to}"] ?? '1.00000000';
        });
    }

    public function invalidate(string $from, string $to): void
    {
        Cache::forget("exchange_rate:{$from}_{$to}");
    }
}
