# 🇰🇪 NiaLink — Digital Wallet & Payment Ecosystem

[![Laravel](https://img.shields.io/badge/Laravel-11-FF2D20?style=flat&logo=laravel)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?style=flat&logo=php)](https://php.net)
[![Vue.js](https://img.shields.io/badge/Vue.js-3-42B883?style=flat&logo=vue.js)](https://vuejs.org)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-ACID-336791?style=flat&logo=postgresql)](https://postgresql.org)
[![Redis](https://img.shields.io/badge/Redis-Cache%20%26%20Locks-DC382D?style=flat&logo=redis)](https://redis.io)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)

> **Fast. Secure. Reliable. — Powering modern digital payments for Africa.**

NiaLink is a high-performance, secure digital payment ecosystem designed for the Kenyan market. It bridges the gap between physical retail (POS) and social (P2P) payments using a **Customer-First authorization model** — ensuring users always approve transactions on their own device, eliminating payment errors and fraud at the point of sale.

Money either moves completely or not at all. This is enforced through atomic database transactions, Redis locking, pessimistic wallet locking, and strict validation at every layer.

---

## 📋 Table of Contents

1. [Project Vision](#1-project-vision)
2. [How NiaLink Works](#2-how-nialink-works)
3. [Technical Architecture](#3-technical-architecture)
4. [Database Schema](#4-database-schema)
5. [Core Components](#5-core-components)
6. [API Reference](#6-api-reference)
7. [Security Architecture](#7-security-architecture)
8. [Admin Dashboard](#8-admin-dashboard)
9. [Installation Guide](#9-installation-guide)
10. [Tech Stack](#10-tech-stack)
11. [Production Roadmap](#11-production-roadmap)
12. [Contributing](#12-contributing)
13. [License](#13-license)

---

## 1. Project Vision

Kenya's payments landscape is dominated by M-Pesa, yet point-of-sale experiences remain clunky, prone to human error, and increasingly targeted by social engineering fraud. NiaLink takes inspiration from Poland's BLIK system and reimagines it for the African context:

- A customer generates a **short-lived 6-digit Nia-Code** in their app
- The merchant enters the code at their terminal
- The customer **reviews and approves the exact amount on their own device** via PIN
- Money moves instantly — no card skimming, no wrong number, no fraud

The result is a closed-loop digital wallet ecosystem that works for supermarkets, fuel stations, e-commerce checkouts, and everyday peer-to-peer transfers — with sub-second processing and full double-entry auditability.

---

## 2. How NiaLink Works

### 🛒 Scenario: Alex Buying Coffee

**Phase 1 — Code Generation** *(Customer App → `POST /api/nialink/generate`)*

Alex taps **"Generate Code"** in the NiaLink app. The system creates a unique transaction record and stores a 6-digit code in Redis with a 120-second TTL:

```
Nia-Code: 4 7 2 9 1 3   (valid for 2 minutes)
```

**Phase 2 — Merchant Claim** *(POS Terminal → `POST /api/pos/payment/claim`)*

The cashier enters the code and the purchase amount into their terminal. NiaLink acquires a Redis lock on the code, validates it, attaches the merchant and amount to the transaction, and flips its status to `processing`. No money moves yet.

**Phase 3 — Customer Authorization** *(Customer App → `POST /api/nialink/approve`)*

A push notification appears on Alex's phone:

```
💳 Pay KES 500 to Java House Terminal 02?
   [Enter PIN to confirm]
```

Alex enters their 4-digit PIN. The backend verifies the PIN hash, acquires a pessimistic lock on both wallets, checks for sufficient funds, and executes the atomic settlement.

**Phase 4 — Settlement & Webhook**

```
User Wallet   -500.00 KES   (debit)
Merchant Wallet +495.00 KES  (credit, after 1% fee)
NiaLink Fee      +5.00 KES
```

Two `LedgerEntry` rows are written — one debit, one credit. The transaction status becomes `completed`. A signed webhook fires async to the merchant's system. Both parties receive confirmation.

**Terminal Polling** *(POS Terminal → `GET /api/pos/payment/status/{reference}`)*

While the customer is looking at their phone, the POS terminal polls this endpoint until `is_finalized` returns `true`.

---

### 📱 Peer-to-Peer (P2P) Flow

For social transfers:

1. App syncs contacts to identify existing NiaLink users
2. Sender selects a contact — phone and name auto-populated
3. Sender enters amount, confirms with 4-digit PIN
4. Instant settlement — both parties notified in real time

---

## 3. Technical Architecture

### The Three-Phase Payment Engine

All core logic lives in `app/Services/NiaLinkService.php`:

```
─────────────────────────────────────────────────────────────────
 PHASE 1: generateCode($userId)
─────────────────────────────────────────────────────────────────
  • Generate unique 6-digit code (collision-safe loop)
  • Create Transaction record [ status: pending, amount: 0 ]
  • Cache::put("nialink_auth_{code}", $transaction->id, 120s)
  • AuditLog → 'code_generated'

─────────────────────────────────────────────────────────────────
 PHASE 2: completePayment($code, $merchantCode, $amount)
─────────────────────────────────────────────────────────────────
  • Cache::lock("processing_nialink_{code}", 10s)   ← Anti double-spend
  • Validate merchant exists
  • Validate transaction is 'pending' (not expired/used)
  • transaction->update([ merchant_id, amount, status: 'processing' ])
  • Return → triggers Push Notification on user's device

─────────────────────────────────────────────────────────────────
 PHASE 3: confirmPayment($transactionId, $pin)
─────────────────────────────────────────────────────────────────
  • DB::transaction()
    ├── Transaction::lockForUpdate()    ← Pessimistic row lock
    ├── Hash::check($pin, $user->pin_hash)
    └── executeSettlement()
          ├── Wallet::lockForUpdate()   ← Lock both wallets
          ├── Check balance ≥ amount
          ├── fee = amount × 0.01
          ├── userWallet.decrement(amount)
          ├── merchantWallet.increment(amount - fee)
          ├── createLedgerPair()        ← Double-entry rows
          ├── transaction.update([ fee, status: 'completed' ])
          └── Cache::forget("nialink_auth_{code}")
  • dispatchMerchantWebhook()           ← Async, outside transaction
  • AuditLog → 'payment_completed'
```

### Webhook Security

Merchant webhooks are signed with HMAC-SHA256 using the merchant's `api_key`, so merchants can verify the payload authenticity on their end:

```php
'signature' => hash_hmac('sha256', $transaction->reference, $merchant->api_key)
```

---

## 4. Database Schema

NiaLink uses a **double-entry ledger model**. Every settlement creates two `ledger_entries` rows — one debit, one credit — so the total money in the system is always reconcilable. Wallet balances are never directly mutated without a corresponding ledger entry.

### Tables

| Table | Key Columns | Notes |
|---|---|---|
| `users` | `phone_number`, `pin_hash`, `fcm_token`, `device_id`, `kyc_level`, `daily_limit_p2m`, `daily_limit_p2p`, `status` | Customers. `pin_hash` uses Laravel's `hashed` cast. FCM token for push notifications. |
| `merchants` | `user_id`, `business_name`, `merchant_code`, `kra_pin`, `business_license_no`, `api_key`, `webhook_url`, `settlement_bank`, `status` | Business entities. `api_key` hidden from serialization. Webhook URL for IPN. |
| `terminals` | `merchant_id`, `name`, `terminal_code`, `terminal_secret`, `status`, `location_note`, `last_active_at` | Individual POS units. `isOperational()` checks both terminal and parent merchant status. |
| `wallets` | `walletable_id`, `walletable_type`, `balance`, `currency`, `status` | Polymorphic — one wallet per currency per User or Merchant. Unique constraint on `(walletable_id, walletable_type, currency)`. |
| `transactions` | `reference`, `user_id`, `merchant_id`, `terminal_id`, `recipient_id`, `amount`, `fee`, `nialink_code`, `type`, `status` | Lifecycle: `pending` → `processing` → `completed / failed / expired`. `nialink_code` hidden from JSON output. |
| `ledger_entries` | `transaction_id`, `wallet_id`, `amount`, `post_balance`, `entry_type` | Immutable. `amount` is negative for debits, positive for credits. `post_balance` snapshots the wallet balance after the entry. |
| `audit_logs` | `user_id`, `action`, `resource_type`, `resource_id`, `metadata`, `ip_address`, `user_agent` | Behavioural security trail. `user_id` sets null on delete — logs survive account deletion. |
| `personal_access_tokens` | `tokenable` (morph), `token`, `abilities`, `last_used_at`, `expires_at` | Sanctum tokens. Supports both User and Merchant token holders. Token abilities for scoped access. |
| `cache` / `jobs` / `failed_jobs` | — | Laravel internals for Redis cache store and async job queue. |

---

## 5. Core Components

### Repository Structure

```
NiaLink/
├── NiaLink_Backend/                                    # Laravel 11 API
│   ├── app/
│   │   ├── Http/
│   │   │   ├── Controllers/
│   │   │   │   ├── Api/
│   │   │   │   │   ├── Admin/
│   │   │   │   │   │   └── ManagementController.php   # Admin dashboard, KYC approval, merchant suspend
│   │   │   │   │   ├── MerchantPaymentController.php  # POS payment claim (Phase 2)
│   │   │   │   │   ├── NiaCodeController.php          # Nia-Code generation (Phase 1)
│   │   │   │   │   └── TerminalController.php         # POS status polling
│   │   │   │   ├── AppApprovalController.php          # PIN approval (Phase 3)
│   │   │   │   ├── AuthController.php                 # Register, OTP, verify, login, PIN mgmt, logout
│   │   │   │   ├── Controller.php                     # Base controller
│   │   │   │   └── TransactionController.php          # Alt gateway for all 3 phases + TransactionResource
│   │   │   └── Resources/
│   │   │       └── TransactionResource.php            # Shapes transaction JSON; hides code post-settlement
│   │   ├── Models/
│   │   │   ├── AuditLog.php                           # Behavioural security trail (create-only)
│   │   │   ├── LedgerEntry.php                        # Immutable debit/credit rows (create-only)
│   │   │   ├── Merchant.php                           # Business entity, wallet, terminals, transactions
│   │   │   ├── Terminal.php                           # POS unit; isOperational() checks merchant status
│   │   │   ├── Transaction.php                        # Payment lifecycle; isExpired() helper
│   │   │   ├── User.php                               # Customer; wallet, transactions, auditLogs, merchants
│   │   │   └── Wallet.php                             # Polymorphic balance; hasSufficientFunds(), isAvailable()
│   │   ├── Providers/
│   │   │   └── AppServiceProvider.php
│   │   └── Services/
│   │       └── NiaLinkService.php                     # Core engine: all 3 payment phases + settlement
│   ├── database/
│   │   └── migrations/                                # 10 migration files (see schema above)
│   └── routes/
│       ├── api.php                                    # All API route definitions (4 groups)
│       ├── console.php
│       └── web.php
│
└── NiaLink_Frontend/                                   # Blade + Vue.js interface
```

### Key File Responsibilities

| File | What it actually does |
|---|---|
| `NiaLinkService.php` | The entire payment engine: `generateCode()`, `completePayment()`, `confirmPayment()`, `executeSettlement()`, `createLedgerPair()`, `dispatchMerchantWebhook()`, `logActivity()` |
| `NiaCodeController.php` | Thin wrapper — gets `auth()->id()` from Sanctum token, delegates to `generateCode()` |
| `MerchantPaymentController.php` | Validates POS request (code, merchant_code, amount, terminal_id), delegates to `completePayment()` |
| `AppApprovalController.php` | Validates PIN input, delegates to `confirmPayment()`, handles exceptions |
| `TerminalController.php` | Looks up transaction by `reference`, returns status + `is_finalized` flag for POS polling |
| `AuthController.php` | Full auth lifecycle: register → sendOtp → verifyAccount → login → resetPin → changePin → logout |
| `TransactionController.php` | Alternative controller covering all 3 phases, uses `TransactionResource` for shaped responses |
| `TransactionResource.php` | Hides `nialink_code` once transaction leaves `pending` status; always visible to admins via Gate |
| `ManagementController.php` | `dashboard()` aggregates liquidity/revenue/volume/pending merchants; `approveMerchant()` provisions wallet; `suspendMerchant()` kills terminal access |
| `Wallet.php` | `hasSufficientFunds(float $amount)`, `isAvailable()` helpers; polymorphic morph to User or Merchant |
| `Terminal.php` | `isOperational()` — checks both terminal status AND parent merchant status before allowing transactions |
| `Transaction.php` | `isExpired()` — true if status is `pending` and `created_at` is more than 2 minutes ago |
| `LedgerEntry.php` | Immutable. `isCredit()` / `isDebit()` helpers. Only `fillable`, no updates permitted. |
| `AuditLog.php` | Immutable. `user_id` uses `onDelete('set null')` — audit trail survives account deletion. Polymorphic `resource()` accessor. |

---

## 6. API Reference

### Route Groups

```
POST   /api/auth/register                     Public  throttle:6,1
POST   /api/auth/login                        Public  throttle:6,1
POST   /api/auth/send-otp                     Public  throttle:3,1
POST   /api/auth/verify-account               Public  throttle:3,1
POST   /api/auth/reset-pin                    Public  throttle:3,1

POST   /api/user/change-pin                   Auth (Sanctum)
POST   /api/user/logout                       Auth (Sanctum)
GET    /api/user/balance                      Auth (Sanctum)

POST   /api/nialink/generate                  Auth (Sanctum)   ← Phase 1
POST   /api/nialink/approve                   Auth (Sanctum)   ← Phase 3

POST   /api/pos/payment/claim                 Public (terminal secret)  ← Phase 2
GET    /api/pos/payment/status/{reference}    Public (terminal polling)

GET    /api/admin/stats                       Auth + can:admin-access
POST   /api/admin/merchants/{id}/approve      Auth + can:admin-access
```

### Endpoints in Detail

#### Register

```http
POST /api/auth/register
```
```json
{ "name": "Alex Kamau", "phone_number": "0712345678", "pin": "1234", "pin_confirmation": "1234" }
```
Creates a `pending_verification` user and auto-triggers OTP send. Returns OTP in response (`otp_debug` — remove in production).

---

#### Verify Account

```http
POST /api/auth/verify-account
```
```json
{ "phone_number": "0712345678", "otp": "483920" }
```
Validates OTP hash (10-minute expiry). Flips user status to `active`.

---

#### Login

```http
POST /api/auth/login
```
```json
{ "phone_number": "0712345678", "pin": "1234" }
```
Returns a Sanctum Bearer token. Only `active` accounts can log in.

---

#### Generate Nia-Code *(Phase 1)*

```http
POST /api/nialink/generate
Authorization: Bearer {token}
```
```json
// Response
{ "status": "success", "data": { "code": "472913", "expires_in": 120 } }
```

---

#### Merchant Claims Code *(Phase 2)*

```http
POST /api/pos/payment/claim
```
```json
{ "nialink_code": "472913", "merchant_code": "NL-MER-0042", "amount": 500, "terminal_id": "TILL-01" }
```
Acquires Redis lock, validates code, sets transaction to `processing`. Returns `transaction_id` for the approval step.

---

#### Customer Approves with PIN *(Phase 3)*

```http
POST /api/nialink/approve
Authorization: Bearer {token}
```
```json
{ "transaction_id": 88, "pin": "1234" }
```
Verifies PIN hash, executes atomic settlement, writes ledger pair, fires merchant webhook.

---

#### Terminal Polls Status

```http
GET /api/pos/payment/status/{reference}
```
```json
{
  "status": "COMPLETED",
  "reference": "NL-X89J2F",
  "amount": "500.00",
  "is_finalized": true
}
```

---

#### Admin Dashboard

```http
GET /api/admin/stats
Authorization: Bearer {admin-token}
```
```json
{
  "total_liquidity": 4820000.00,
  "total_revenue": 12430.50,
  "volume_24h": 980000.00,
  "pending_merchants": 3
}
```

---

#### Approve Merchant (KYC)

```http
POST /api/admin/merchants/{id}/approve
Authorization: Bearer {admin-token}
```
Sets `status: active`, stamps `verified_at`, auto-provisions a KES wallet if one doesn't exist, and writes an `AuditLog` entry.

---

## 7. Security Architecture

NiaLink layers multiple independent controls so no single compromise can complete a fraudulent transaction.

| Threat | Control |
|---|---|
| Stolen phone number | PIN required on the customer's own device for every authorization |
| Code theft / interception | Codes are single-use, 120s TTL, Redis-managed |
| Double-spending (concurrent claim) | `Cache::lock("processing_nialink_{code}", 10s)` — atomic Redis lock |
| Race condition on settlement | `Wallet::lockForUpdate()` — pessimistic DB row lock on both wallets |
| Partial settlement failure | `DB::transaction()` wraps the entire settlement — full rollback on any error |
| Invalid/expired code | Code validated against Redis before any DB write occurs |
| Brute-force PIN | `throttle:3,1` on auth routes; PIN locked to 4 digits with hash check |
| Brute-force registration | `throttle:6,1` on register/login routes |
| Unauthorized API access | Laravel Sanctum Bearer tokens on all user routes |
| Admin privilege escalation | `can:admin-access` Gate policy on all admin routes |
| Code visible after use | `TransactionResource` hides `nialink_code` once status leaves `pending` |
| Compromised terminal | `Terminal::isOperational()` checks both terminal AND merchant status |
| Merchant account takeover | `suspendMerchant()` freezes all terminals instantly without touching wallet |
| Audit trail tampering | `AuditLog` is create-only; `user_id` uses `onDelete('set null')` — records survive deletion |
| PIN storage | `pin_hash` field uses Laravel `hashed` cast — bcrypt, never plaintext |
| Fake merchant webhooks | Payload signed with `hash_hmac('sha256', $reference, $merchant->api_key)` |

---

## 8. Admin Dashboard

The `ManagementController` (protected by `auth:sanctum` + `can:admin-access`) is the command center for NiaLink operations.

**System Health (`GET /api/admin/stats`)**
- Total liquidity: sum of all wallet balances in the system
- Total revenue: sum of all `fee` columns on completed transactions
- 24h volume: transaction throughput in the last 24 hours
- Pending merchants: KYC queue depth

**Merchant KYC (`POST /api/admin/merchants/{id}/approve`)**
- Flips merchant status to `active`, stamps `verified_at`
- Auto-provisions a KES wallet via `firstOrCreate`
- Writes an audit log entry attributing the approval to the admin

**Merchant Suspension (`suspendMerchant`)**
- Instantly sets merchant status to `suspended`
- All terminals become non-operational via `Terminal::isOperational()` — no individual terminal updates needed

---

## 9. Installation Guide

### Prerequisites

- PHP 8.2+
- Composer
- PostgreSQL
- Redis
- Node.js & npm (for frontend assets)

### Backend Setup

**1. Clone and install dependencies**

```bash
git clone https://github.com/alexamita/NiaLink.git
cd NiaLink/NiaLink_Backend
composer install
composer require predis/predis
```

**2. Configure environment**

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env`:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=nialink
DB_USERNAME=your_username
DB_PASSWORD=your_password

REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

**3. Run migrations and seed**

```bash
php artisan migrate
php artisan db:seed
```

**4. Start the API server**

```bash
php artisan serve
# API available at http://localhost:8000
```

### Frontend Setup

```bash
cd ../NiaLink_Frontend
npm install
npm run dev
```

---

## 10. Tech Stack

| Layer | Technology |
|---|---|
| **Backend Framework** | PHP 8.2+ / Laravel 11 |
| **Frontend** | Blade templates + Vue.js |
| **Database** | PostgreSQL (ACID — all financial writes wrapped in `DB::transaction()`) |
| **Cache / Locks** | Redis via Predis (Nia-Code TTL, atomic distributed locks) |
| **API Auth** | Laravel Sanctum (Bearer tokens with abilities, expiry, last_used_at) |
| **PIN Hashing** | Bcrypt via Laravel `hashed` cast on `pin_hash` |
| **Push Notifications** | Firebase Cloud Messaging (FCM) — `fcm_token` stored on User |
| **Merchant Webhooks** | Async HTTP via `Http::async()->post()`, HMAC-SHA256 signed |

---

## 11. Production Roadmap

### 🔐 Authentication
- [ ] Connect SMS gateway for real OTP delivery (Africa's Talking / Twilio) — stub is marked in `AuthController::sendOtp()`
- [ ] Remove `otp_debug` field from `sendOtp` response
- [ ] KYC level enforcement on `daily_limit_p2m` and `daily_limit_p2p`
- [ ] Biometric auth flow (`biometric_enabled` field already on User model)

### 💳 Payment Features
- [ ] P2P transfer flow (`recipient_id` already on `transactions` table)
- [ ] Transaction history endpoint using `TransactionResource`
- [ ] Wallet top-up / withdrawal flows
- [ ] Code expiry job — sweep `pending` transactions older than 2 minutes to `expired`

### 🔔 Real-Time Notifications
- [ ] Firebase Cloud Messaging (FCM) integration for push-to-approve (`fcm_token` already stored)
- [ ] WebSocket real-time status updates via Laravel Reverb (replaces terminal polling)

### 🏛️ Admin Portal
- [ ] `suspendMerchant` route wired into `api.php`
- [ ] Dispute resolution — manual reversal and refund endpoints
- [ ] Fraud velocity checks — flag users exceeding `daily_transaction_count_limit`
- [ ] Float monitoring — total system liquidity vs. physical cash in trust

### 📈 Scale & Reliability
- [ ] Queue-based audit logging via Laravel Horizon (`jobs` table already migrated)
- [ ] Structured API error responses with error codes
- [ ] Postman collection and Swagger/OpenAPI spec
- [ ] Feature and unit test suite

---

## 12. Contributing

Pull requests are welcome. For significant changes, please open an issue first to discuss your proposal. Ensure all migrations run cleanly before submitting a PR.

---

## 13. License

This project is licensed under the [MIT License](LICENSE).

---

*Built for Kenya. Secured by design.*
