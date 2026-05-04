# Tupay API Documentation

**Base URL:** `http://localhost:8000/api`
**Content-Type:** `application/json`
**Accept:** `application/json`

---

## Table of Contents

- [Authentication](#authentication)
  - [POST /login](#post-login)
  - [POST /2fa/verify](#post-2faverify)
- [Swap](#swap)
  - [POST /swap](#post-swap)
- [Ledger](#ledger)
  - [GET /ledger/{wallet\_id}](#get-ledgerwallet_id)
- [Webhooks](#webhooks)
  - [POST /webhooks/settlement](#post-webhookssettlement)
- [Seeded Test Data](#seeded-test-data)
- [Testing 2FA Locally](#testing-2fa-locally)
- [End-to-End Test Flow](#end-to-end-test-flow)
- [Signature Generation](#signature-generation)

---

## Authentication

### POST `/login`

Authenticates a user and returns a Sanctum bearer token.

> **Note:** This token alone does **not** grant access to 2FA-protected endpoints.
> You must call `/2fa/verify` separately to unlock swap/transfer routes.

**Rate limit:** 5 requests / minute per IP

#### Request Body

```json
{
  "email": "test@tupay.com",
  "password": "password"
}
```

#### Responses

**200 OK** — Login successful

```json
{
  "message": "Login successful. Please verify your 2FA code to access protected endpoints.",
  "token": "1|abc123xyz..."
}
```

**401 Unauthorized** — Wrong credentials

```json
{
  "message": "The provided credentials are incorrect."
}
```

**422 Unprocessable Entity** — Validation failure

```json
{
  "message": "The email field is required.",
  "errors": {
    "email": ["The email field is required."]
  }
}
```

**429 Too Many Requests** — Rate limit exceeded

```json
{
  "message": "Too Many Attempts."
}
```

---

### POST `/2fa/verify`

Verifies a TOTP code from an authenticator app (Google Authenticator, Authy).

On success, a server-side Redis flag is set for **15 minutes** that unlocks protected endpoints. The flag is checked on every subsequent request — it cannot be spoofed client-side.

**Rate limit:** 5 requests / minute per IP
**Auth required:** `Authorization: Bearer {token}`

#### Request Body

```json
{
  "code": "123456"
}
```

#### Responses

**200 OK** — 2FA verified

```json
{
  "message": "2FA verified. You may now access protected endpoints for 15 minutes."
}
```

**422 Unprocessable Entity** — Invalid or expired code

```json
{
  "message": "Invalid or expired 2FA code."
}
```

**422 Unprocessable Entity** — 2FA not configured on account

```json
{
  "message": "Two-factor authentication is not configured for this account."
}
```

**401 Unauthorized** — Missing or invalid bearer token

```json
{
  "message": "Unauthenticated."
}
```

**429 Too Many Requests** — Rate limit exceeded

```json
{
  "message": "Too Many Attempts."
}
```

---

## Swap

### POST `/swap`

Exchanges NGN for CNY using the current cached exchange rate.

`amount` is in **kobo** (NGN subunits). `1 NGN = 100 kobo`.

The output CNY amount is in **fen** (CNY subunits). `1 CNY = 100 fen`.

Both ledger entries (debit NGN + credit CNY) are written atomically inside a single database transaction. A Redis atomic lock prevents a user from triggering a second swap before the first completes.

**Rate limit:** 30 requests / minute per user
**Auth required:** `Authorization: Bearer {token}`
**2FA required:** Must have called `/2fa/verify` within the last 15 minutes

#### Request Body

```json
{
  "amount": 100000
}
```

| Field    | Type    | Description                         |
| -------- | ------- | ----------------------------------- |
| `amount` | integer | Amount to swap in kobo (min: 1)     |

#### Responses

**201 Created** — Swap executed successfully

```json
{
  "data": {
    "debit": {
      "id": "018f1234-abcd-7000-8000-000000000001",
      "wallet_id": 1,
      "type": "debit",
      "amount": 100000,
      "balance_after": 9900000,
      "description": "Swap NGN to CNY",
      "reference": "debit_018f1234-abcd-7000-8000-000000000001",
      "status": "completed",
      "metadata": {
        "swap_reference": "018f1234-abcd-7000-8000-000000000099",
        "swap_rate": "0.00520000",
        "from_currency": "NGN",
        "to_currency": "CNY"
      },
      "created_at": "2026-05-04T10:00:00+00:00"
    },
    "credit": {
      "id": "018f1234-abcd-7000-8000-000000000002",
      "wallet_id": 2,
      "type": "credit",
      "amount": 520,
      "balance_after": 520,
      "description": "Swap NGN to CNY",
      "reference": "credit_018f1234-abcd-7000-8000-000000000099",
      "status": "completed",
      "metadata": {
        "swap_reference": "018f1234-abcd-7000-8000-000000000099",
        "swap_rate": "0.00520000",
        "from_currency": "NGN",
        "to_currency": "CNY"
      },
      "created_at": "2026-05-04T10:00:00+00:00"
    },
    "rate": "0.00520000",
    "amount_out": 520
  }
}
```

**403 Forbidden** — 2FA session expired or not verified

```json
{
  "message": "2FA verification required."
}
```

**409 Conflict** — A swap is already in progress for this user

```json
{
  "message": "A swap is already in progress. Please wait and try again."
}
```

**422 Unprocessable Entity** — Insufficient funds

```json
{
  "message": "Insufficient funds in NGN wallet."
}
```

**422 Unprocessable Entity** — Validation failure

```json
{
  "message": "The amount field is required.",
  "errors": {
    "amount": ["The amount field is required."]
  }
}
```

**401 Unauthorized** — Missing or invalid bearer token

```json
{
  "message": "Unauthenticated."
}
```

---

## Ledger

### GET `/ledger/{wallet_id}`

Returns paginated transaction history for a wallet, ordered by most recent first (15 per page).

Only the authenticated user's own wallets are accessible. Attempting to access another user's wallet returns 404.

**Rate limit:** 30 requests / minute per user
**Auth required:** `Authorization: Bearer {token}`

#### Path Parameters

| Parameter   | Type    | Description       |
| ----------- | ------- | ----------------- |
| `wallet_id` | integer | ID of the wallet  |

#### Query Parameters

| Parameter | Type    | Description              |
| --------- | ------- | ------------------------ |
| `page`    | integer | Page number (default: 1) |

#### Example Request

```
GET /api/ledger/1?page=1
Authorization: Bearer {token}
```

#### Responses

**200 OK** — Transaction list

```json
{
  "data": [
    {
      "id": "018f1234-abcd-7000-8000-000000000001",
      "wallet_id": 1,
      "type": "debit",
      "amount": 100000,
      "balance_after": 9900000,
      "description": "Swap NGN to CNY",
      "reference": "debit_018f1234-abcd-7000-8000-000000000001",
      "status": "completed",
      "metadata": {
        "swap_reference": "018f1234-abcd-7000-8000-000000000099",
        "swap_rate": "0.00520000"
      },
      "created_at": "2026-05-04T10:00:00+00:00"
    }
  ],
  "links": {
    "first": "http://localhost:8000/api/ledger/1?page=1",
    "last":  "http://localhost:8000/api/ledger/1?page=3",
    "prev":  null,
    "next":  "http://localhost:8000/api/ledger/1?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 3,
    "per_page": 15,
    "to": 15,
    "total": 42
  }
}
```

**404 Not Found** — Wallet not found or belongs to another user

```json
{
  "message": "Wallet not found."
}
```

**401 Unauthorized** — Missing or invalid bearer token

```json
{
  "message": "Unauthenticated."
}
```

---

## Webhooks

### POST `/webhooks/settlement`

Receives a payout confirmation from a third-party settlement partner.

- **Idempotent** — sending the same `provider_reference` multiple times is safe; only the first call credits the wallet.
- **Asynchronous** — the endpoint returns immediately (202) and dispatches a background job to update the ledger. Requires the queue worker to be running.

**Rate limit:** 60 requests / minute per IP
**Signature required:** `X-Tupay-Signature` header

#### Headers

| Header                | Description                                                        |
| --------------------- | ------------------------------------------------------------------ |
| `X-Tupay-Signature`   | `hash_hmac('sha256', rawRequestBody, TUPAY_WEBHOOK_SECRET)`        |

#### Request Body

```json
{
  "provider_reference": "PARTNER-REF-001",
  "status": "completed",
  "amount": 52000,
  "wallet_id": 2
}
```

| Field                | Type    | Description                                                  |
| -------------------- | ------- | ------------------------------------------------------------ |
| `provider_reference` | string  | Unique partner reference — used for idempotency checks       |
| `status`             | string  | `completed`, `failed`, or `pending`                          |
| `amount`             | integer | Amount in fen (CNY subunits)                                 |
| `wallet_id`          | integer | Wallet to credit on `status: completed`                      |

#### Responses

**202 Accepted** — Received and queued for processing

```json
{
  "message": "Webhook received and queued for processing."
}
```

**200 OK** — Already processed (idempotent duplicate)

```json
{
  "message": "Already processed."
}
```

**401 Unauthorized** — Missing signature header

```json
{
  "message": "Missing webhook signature."
}
```

**401 Unauthorized** — Signature mismatch

```json
{
  "message": "Invalid webhook signature."
}
```

**422 Unprocessable Entity** — Validation failure

```json
{
  "message": "The provider reference field is required.",
  "errors": {
    "provider_reference": ["The provider reference field is required."]
  }
}
```

---

## Seeded Test Data

Run `php artisan db:seed` to create the following test fixtures.
The 2FA secret is printed to the console during seeding — use it with Google Authenticator or Authy.

| Field         | Value                      |
| ------------- | -------------------------- |
| Email         | `test@tupay.com`           |
| Password      | `password`                 |
| 2FA Secret    | *(printed to console)*     |

| Wallet     | ID | Currency | Initial Balance              |
| ---------- | -- | -------- | ---------------------------- |
| NGN Wallet | 1  | NGN      | 10,000,000 kobo (100,000 NGN)|
| CNY Wallet | 2  | CNY      | 0 fen                        |

| Exchange Rate | Value      |
| ------------- | ---------- |
| NGN → CNY     | 0.00520000 |

---

## Testing 2FA Locally

TOTP codes rotate every **30 seconds**. You have two options to get a valid code:

### Option 1 — Generate via terminal (no phone needed)

Run this command any time you need a fresh code:

```bash
php otp.php
```

Output:

```
==============================
  2FA Code : 862755
  Valid for : ~30 seconds
==============================
```

Use the code immediately in your request body:

```json
{ "code": "862755" }
```

### Option 2 — Use an authenticator app (permanent)

1. Run `php artisan db:seed` — the 2FA secret is printed to the console
2. Open **Google Authenticator** or **Authy**
3. Add a new account manually using the printed secret
4. The app will generate a fresh code every 30 seconds indefinitely

---

## End-to-End Test Flow

Run these requests in order. Each step depends on the previous one.

### Step 1 — Login

```
POST /api/login
Content-Type: application/json

{
  "email": "test@tupay.com",
  "password": "password"
}
```

Copy the `token` from the response.

### Step 2 — Get a 2FA code

```bash
php otp.php
```

### Step 3 — Verify 2FA

```
POST /api/2fa/verify
Authorization: Bearer {token from step 1}
Content-Type: application/json

{
  "code": "{code from step 2}"
}
```

You now have a 15-minute window to call protected endpoints.

### Step 4 — Execute a Swap

```
POST /api/swap
Authorization: Bearer {token from step 1}
Content-Type: application/json

{
  "amount": 100000
}
```

100,000 kobo = 1,000 NGN. At rate 0.0052, you receive 520 fen (5.20 CNY).

### Step 5 — Check the Ledger

```
GET /api/ledger/1?page=1
Authorization: Bearer {token from step 1}
```

Returns the NGN wallet transaction history. Use wallet ID `2` for CNY.

### Step 6 — Send a Settlement Webhook

Generate the signature first:

```bash
php -r "
  \$body = json_encode(['provider_reference'=>'PARTNER-REF-001','status'=>'completed','amount'=>52000,'wallet_id'=>2]);
  echo hash_hmac('sha256', \$body, 'mock-secret-for-testing');
"
```

Then send:

```
POST /api/webhooks/settlement
Content-Type: application/json
X-Tupay-Signature: {signature from above}

{
  "provider_reference": "PARTNER-REF-001",
  "status": "completed",
  "amount": 52000,
  "wallet_id": 2
}
```

Send the same request twice to confirm idempotency — the second call returns `200 Already processed.` without crediting the wallet again.

---

## Signature Generation

### Postman Pre-request Script

The included Postman collection (`tupay-api.postman_collection.json`) handles this automatically.
For manual testing, add this as a pre-request script on the settlement webhook request:

```javascript
const secret = pm.collectionVariables.get('webhook_secret');
const body = pm.request.body.raw;
const signature = CryptoJS.HmacSHA256(body, secret).toString(CryptoJS.enc.Hex);
pm.request.headers.add({ key: 'X-Tupay-Signature', value: signature });
```

### PHP

```php
$signature = hash_hmac('sha256', $rawBody, env('TUPAY_WEBHOOK_SECRET'));
```

### cURL Example

```bash
SECRET="mock-secret-for-testing"
BODY='{"provider_reference":"PARTNER-REF-001","status":"completed","amount":52000,"wallet_id":2}'
SIG=$(echo -n "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $2}')

curl -X POST http://localhost:8000/api/webhooks/settlement \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "X-Tupay-Signature: $SIG" \
  -d "$BODY"
```
