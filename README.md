# Tupay Ledger & Settlement Engine

A production-grade Laravel 12 backend for a cross-border remittance service facilitating trade between Nigeria (NGN) and China (CNY).

---

## Setup Instructions

### Prerequisites

- PHP 8.2+
- MySQL 8.0+
- Redis 7.0+
- Composer

### Installation

```bash
# 1. Clone and install dependencies
composer install

# 2. Copy environment file and configure
cp .env.example .env
php artisan key:generate

# 3. Configure .env — set DB_* and REDIS_* values, then:
php artisan migrate
php artisan db:seed

# 4. Start the queue worker (required for webhook processing)
php artisan queue:work redis --queue=webhooks,default

# 5. Start the dev server
php artisan serve
```

### Environment Variables

| Variable | Description | Default |
|---|---|---|
| `DB_*` | MySQL connection details | — |
| `CACHE_STORE` | Must be `redis` | `redis` |
| `QUEUE_CONNECTION` | Must be `redis` | `redis` |
| `TUPAY_WEBHOOK_SECRET` | Shared secret for HMAC signature verification | `mock-secret-for-testing` |

---

## API Endpoints

| Method | Endpoint | Description | Security |
|---|---|---|---|
| `POST` | `/api/login` | Authenticate and receive Sanctum token | Rate-limited (5/min) |
| `POST` | `/api/2fa/verify` | Verify TOTP code, activate 15-min 2FA session | Rate-limited (5/min) |
| `POST` | `/api/swap` | Exchange NGN for CNY | Sanctum + 2FA required |
| `GET` | `/api/ledger/{wallet_id}` | Paginated transaction history | Sanctum |
| `POST` | `/api/webhooks/settlement` | Third-party RMB payout confirmation | HMAC signature |

---

## Architecture

### Folder Structure

```
app/
├── Actions/              # Single-responsibility business operations
│   ├── Auth/
│   │   ├── LoginAction.php
│   │   └── VerifyTwoFactorAction.php
│   ├── Wallet/
│   │   ├── CreditWalletAction.php
│   │   └── DebitWalletAction.php
│   └── Swap/
│       └── ExecuteSwapAction.php
├── Http/
│   ├── Controllers/Api/  # Thin controllers — validate, call action, return response
│   └── Middleware/       # RequiresTwoFactor, VerifyWebhookSignature
├── Jobs/
│   └── ProcessSettlementWebhook.php
├── Models/               # Eloquent models with typed casts
├── Services/             # Reusable services (LedgerService, ExchangeRateService)
└── Exceptions/           # Domain-specific exceptions
```

### Design Principles

**Thin Controllers** — Controllers only validate the incoming request, delegate to an Action or Service, and format the response. No business logic lives inside a controller.

**Action Classes** — Each `Action` is `final`, has a single `execute()` method, and encapsulates exactly one use-case. They are injected via Laravel's service container. This makes each operation independently testable and easy to reason about.

**Services** — `LedgerService` and `ExchangeRateService` are reusable stateless services injected into Actions. They handle lower-level concerns (BCMath arithmetic, Redis cache) so Actions stay readable.

---

## Concurrency Strategy

Double-spending is prevented by a two-layer defence:

### Layer 1: Redis Atomic Lock (Optimistic Gate)

Before any swap begins, `ExecuteSwapAction` acquires a Redis lock keyed by user ID:

```php
$lock = Cache::lock("swap_lock:{$user->id}", 30); // 30-second TTL
if (!$lock->get()) {
    throw new SwapInProgressException(); // 409 Conflict
}
```

`Cache::lock()` uses Redis `SET NX PX` — a single atomic command. If two concurrent requests arrive simultaneously, exactly one gets the lock and the other receives a 409 immediately. The lock is released in a `finally {}` block to guarantee it is always freed, even on exception.

### Layer 2: Database Pessimistic Lock (Correctness Guarantee)

Inside the DB transaction, `LedgerService` uses `lockForUpdate()`:

```php
$wallet = Wallet::lockForUpdate()->findOrFail($wallet->id);
```

This issues a `SELECT ... FOR UPDATE` — the wallet row is exclusively locked at the database level until the transaction commits. Even if the Redis lock somehow failed, no two concurrent transactions could simultaneously read the same balance and both pass the sufficiency check.

### Double-Spend Prevention — Step by Step

1. Request hits `POST /api/swap`
2. `ExecuteSwapAction` attempts `Cache::lock("swap_lock:{user_id}", 30)`
3. If another request already holds the lock → **409 Conflict** returned immediately
4. Lock acquired; rate fetched from Redis cache
5. `DB::transaction()` opens
6. `SELECT ... FOR UPDATE` on the NGN wallet row — database row is locked
7. BCMath `bccomp()` checks balance >= amount — if not → `InsufficientFundsException`
8. `bcsub()` computes new balance (no floats)
9. Wallet balance updated, debit `Transaction` record written with `balance_after` snapshot
10. `bcmul()` computes CNY amount out
11. `SELECT ... FOR UPDATE` on the CNY wallet row
12. `bcadd()` computes new balance
13. CNY wallet updated, credit `Transaction` record written
14. DB transaction commits atomically — both entries land or neither does
15. Redis lock released in `finally {}`

---

## 2FA Flow

```
POST /api/login
  -> Returns: { token, message }   (standard Sanctum token, NO 2FA clearance yet)

POST /api/2fa/verify  (Authorization: Bearer {token})
  Body: { "code": "123456" }
  -> Google2FA::verifyKey(secret, code)
  -> On success: Cache::put("2fa_verified:{user_id}", true, 900)
  -> Returns: { message: "2FA verified..." }

POST /api/swap  (Authorization: Bearer {token})
  -> RequiresTwoFactor middleware checks: Cache::has("2fa_verified:{user_id}")
  -> If missing or expired (>15 min): 403 Forbidden
  -> If present: request proceeds to SwapController
```

**Why Redis and not a session flag?** The API is stateless — tokens do not carry session state. Storing the 2FA clearance in Redis with a 900-second TTL gives a time-bounded, server-side gate that cannot be spoofed by the client. The flag is checked on **every** protected request, not once at login.

### Getting a Valid TOTP Code for Testing

TOTP codes rotate every 30 seconds. You have two options:

**Option 1 — Generate via terminal (no phone needed)**

```bash
php otp.php
```

Prints a 6-digit code valid for ~30 seconds. Use it immediately in `POST /api/2fa/verify`.
The script is included in the project root (`otp.php`).

**Option 2 — Authenticator app (permanent)**

Run `php artisan db:seed` — the 2FA secret is printed to the console. Add it manually to Google Authenticator or Authy for a live rotating code.

---

## Webhook Security

### HMAC Signature Verification

`VerifyWebhookSignature` middleware:

```php
$expected = hash_hmac('sha256', $request->getContent(), $secret);
if (!hash_equals($expected, $signature)) { ... }
```

- Uses the **raw request body** — any mutation (JSON re-encoding) would break the signature
- `hash_equals()` performs a **constant-time comparison** — immune to timing attacks that could leak the secret one byte at a time
- The shared secret is stored in `config/services.tupay.webhook_secret` and never hardcoded

### Idempotency Guard

The settlement partner may retry webhooks. Protection is two-layered:

**Layer 1 (Controller — fast path):** Before dispatching the job, query for any completed transaction whose `metadata->provider_reference` matches. If found, return 200 immediately without queuing.

**Layer 2 (Job — safety net):** Inside `ProcessSettlementWebhook::handle()`, the same check runs again before writing anything. This handles the race where two identical webhooks arrive simultaneously and both pass the controller check before either job executes.

---

## Performance

### Redis Exchange Rate Cache

```php
Cache::remember("exchange_rate:{from}_{to}", 60, fn() => ExchangeRate::latest('fetched_at')->value('rate'));
```

- TTL of 60 seconds — at 1000 swap requests/minute, this reduces DB queries from 1000 to 1
- On cache miss, falls back to a hardcoded rate if the DB has no data (safe for demo/test)
- Cache is keyed per currency pair, so NGN->CNY and CNY->NGN are cached independently

### Database Indexes

| Table | Index | Purpose |
|---|---|---|
| `wallets` | `UNIQUE(user_id, currency)` | O(1) wallet lookup per user+currency |
| `transactions` | `INDEX(wallet_id, created_at)` | Efficient paginated ledger — ORDER BY uses the index |
| `transactions` | `UNIQUE(reference)` | Prevents duplicate transaction entries |

### No N+1 Queries

The ledger endpoint uses `$wallet->transactions()->orderBy()->paginate()` — a single query, never loading the wallet's user relationship unnecessarily.

---

## Ledger Design (Double-Entry)

Every balance change writes a `Transaction` record with:
- `type`: `credit` or `debit`
- `amount`: always positive (in subunits — kobo for NGN, fen for CNY)
- `balance_after`: snapshot of the wallet balance immediately after this entry
- `reference`: unique idempotency key
- `metadata`: JSON blob — swap rate, IP address, device ID, provider reference, etc.

A user's true balance can be verified independently by summing their ledger:
```sql
SELECT SUM(CASE WHEN type='credit' THEN amount ELSE -amount END)
FROM transactions WHERE wallet_id = ?
```
This must equal `wallets.balance`. The `balance_after` column gives an auditable running total.

---

## Assumptions

1. **Exchange Rate Source**: Rates are seeded into `exchange_rates` via a DB seeder. In production, a scheduled job (e.g., `php artisan schedule:run`) would call a live FX API and refresh the table, with the Redis cache invalidated accordingly.

2. **Mock Partner Secret**: The webhook shared secret defaults to `mock-secret-for-testing`. In production, set `TUPAY_WEBHOOK_SECRET` in the environment and rotate it via a key management system.

3. **TOTP Library**: `pragmarx/google2fa-laravel` is used. It implements RFC 6238 TOTP. No QR-code enrollment flow is built (the secret is pre-seeded for the test user); a production system would add `POST /api/2fa/setup` and `POST /api/2fa/confirm` endpoints.

4. **Currency Direction**: The swap engine only implements NGN->CNY. The inverse (CNY->NGN) follows the same pattern and would be enabled by passing `currency_from=CNY&currency_to=NGN`.

5. **Subunit Convention**: NGN amounts are in kobo (1 NGN = 100 kobo). CNY amounts are in fen (1 CNY = 100 fen). All arithmetic uses BCMath with scale=0 (integer arithmetic, no rounding errors).
