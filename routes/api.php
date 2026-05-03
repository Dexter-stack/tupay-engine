<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LedgerController;
use App\Http\Controllers\Api\SwapController;
use App\Http\Controllers\Api\TwoFactorController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Middleware\RequiresTwoFactor;
use App\Http\Middleware\VerifyWebhookSignature;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:api_auth');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/2fa/verify', [TwoFactorController::class, 'verify'])
        ->middleware('throttle:api_auth');

    Route::middleware(['throttle:api_financial', RequiresTwoFactor::class])->group(function () {
        Route::post('/swap', [SwapController::class, 'swap']);
    });

    Route::get('/ledger/{wallet_id}', [LedgerController::class, 'index'])
        ->middleware('throttle:api_financial');
});

Route::post('/webhooks/settlement', [WebhookController::class, 'settlement'])
    ->middleware(['throttle:api_webhook', VerifyWebhookSignature::class]);
