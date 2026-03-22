# 🇰🇪 NiaLink: Digital Payment Ecosystem

> **Modern, Secure, and Lightning-Fast Payments for Africa**

NiaLink is a secure, high-performance digital payment ecosystem designed to power fast and reliable wallet-based transactions across Africa. Built using Laravel, PostgreSQL, and Redis, the system enables customers to generate short-lived 6-digit payment codes that merchants can redeem instantly — completing transactions in less than a second.

This document provides a complete overview of the system architecture, installation process, API usage, security design, and production roadmap.

---

# Table of Contents

1. Introduction
2. How NiaLink Works (User Perspective)
3. Technical Architecture & Internal Flow
4. Core Components & File Responsibilities
5. Installation Guide
6. Configuration
7. API Usage & Testing Guide
8. Features
9. Security Architecture
10. Dependencies
11. Production Readiness Roadmap
12. Troubleshooting
13. Contributors
14. License

---

# 1. Introduction

NiaLink is designed to make digital payments:

* ⚡ Instant
* 🔐 Secure
* 🧾 Auditable
* 🏦 Financially consistent

The system ensures that money either moves completely or not at all — never partially. This is achieved through atomic database transactions, Redis locking, and strict validation controls.

Core technologies include:

* **Laravel** (Application Framework)
* **PostgreSQL** (Primary database)
* **Redis** (Caching, locking, expiry engine)
* **Predis** (Redis client for PHP)

---

# 2. How NiaLink Works (User Perspective)

Let’s walk through a simple real-world example.

## Scenario: Alex Buying Coffee

### Step 1: Code Generation

Alex opens the NiaLink app and taps **“Generate Code.”**

The system instantly produces a random 6-digit payment code:

```
123456
```

This code is valid for **2 minutes**.

---

### Step 2: Merchant Entry

At checkout, the cashier enters that 6-digit code and the price of the coffee into their shop terminal:

  * The 6-digit code
  * The purchase amount (e.g., 500 KES)

---

### Step 3: Instant Processing

Within less than one second:

  1. The system verifies the code.
  2. Checks if Alex has sufficient balance.
  3. Deducts 500 KES from Alex’s wallet.
  4. Credits 500 KES to the merchant’s wallet.
  5. Sends a success confirmation to both parties.

Transaction complete.

---

# 3. Technical Architecture & Internal Flow

This section explains what happens inside the system.

---

## Step A: Generating the Payment Code

**File:** `app/Services/NiaLinkService.php`
**Method:** `generateCode()`

### Internal Process:

1. A random 6-digit number is generated.
2. The code is saved to:

   * PostgreSQL (for historical tracking)
   * Redis (for fast access and automatic expiration)
3. Redis sets a 2-minute expiration timer.

### Why Dual Storage?

| System     | Purpose                      |
| ---------- | ---------------------------- |
| PostgreSQL | Permanent transaction record |
| Redis      | Speed + expiration handling  |

---

## Step B: Completing the Payment

**File:** `app/Services/NiaLinkService.php`
**Method:** `completePayment()`

### 1. Redis Atomic Lock

Before processing begins:

* A Redis atomic lock is placed on the payment code.
* This prevents double-spending.
* If a merchant clicks “Pay” twice, only one transaction is processed.

---

### 2. Code Validation

The system checks:

* Does the code exist?
* Has it expired?
* Has it already been used?

If validation fails, the request is rejected.

---

### 3. Atomic Money Movement

The payment logic is wrapped inside:

```php
DB::transaction()
```

This guarantees:

* Deduction from user
* Credit to merchant
* Commit only if both succeed
* Automatic rollback if any failure occurs

This ensures strict financial consistency.

---

## Step C: Audit Logging

**Files:**

* `AuditLog.php`
* `audit_logs` table
* `failed_jobs` table

### Recorded Data:

* User ID
* Merchant ID
* Transaction amount
* Timestamp
* IP address
* Status

This allows:

* Fraud investigation
* Compliance reporting
* Dispute resolution

---

# 4. Core Components & File Responsibilities

| File                                   | Responsibility               |
| -------------------------------------- | ---------------------------- |
| `app/Services/NiaLinkService.php`      | Core payment logic           |
| `app/Models/User.php`                  | User wallet & PIN management |
| `database/migrations/`                 | Database schema              |
| `routes/api.php`                       | API endpoint definitions     |
| `app/Providers/AppServiceProvider.php` | Service binding              |
| `.env`                                 | Environment configuration    |

---

# 5. Installation Guide

## Requirements

* PHP 8.2+
* Composer
* PostgreSQL
* Redis
* Predis

---

## Step 1: Clone Repository

```bash
git clone https://github.com/your-username/nialink.git
cd nialink
```

---

## Step 2: Install Dependencies

```bash
composer install
composer require predis/predis
```

---

## Step 3: Configure Environment

```bash
cp .env.example .env
php artisan key:generate
```

---

## Step 4: Update Database & Redis Settings

Edit `.env`:

```
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=nialink
DB_USERNAME=your_username
DB_PASSWORD=your_password

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

---

## Step 5: Run Migrations & Seed Data

```bash
php artisan migrate
php artisan db:seed
```

---

## Step 6: Start the Server

```bash
php artisan serve
```

Application runs at:

```
http://localhost:8000
```

---

# 6. API Usage & Testing Guide

Ensure the server is running and database is seeded.

---

## ✅ Generate Payment Code

**POST**

```
http://localhost:8000/api/generate-code
```

**Body (JSON):**

```json
{
  "user_id": 1
}
```

**Response Example:**

```json
{
  "nialink_code": "123456"
}
```

---

## ✅ Complete Payment

**POST**

```
http://localhost:8000/api/complete-payment
```

**Body (JSON):**

```json
{
  "code": "123456",
  "merchant_code": "778899",
  "amount": 500
}
```

**Expected Result:**

* Success message
* User balance decreases
* Merchant balance increases

---

# 7. Features

* Sub-second transaction processing
* Redis atomic locking
* Double-spend protection
* Atomic database transactions
* Short-lived secure payment codes
* Full audit logging
* Scalable architecture
* Redis-based expiry management

---

# 8. Security Architecture

NiaLink implements multiple layers of security:

1. Redis atomic locks
2. Database transaction wrapping
3. Expiring payment codes
4. Balance verification before deduction
5. Complete audit logging

Planned improvements:

* Bcrypt PIN hashing
* SMS phone verification
* WebSocket-based payment confirmation prompts

---

# 9. Dependencies

* PHP 8.2+
* Laravel Framework
* PostgreSQL
* Redis
* predis/predis
* Composer

---

# 10. Production Readiness Roadmap

To scale NiaLink to real-world production we will use:

## Phone Verification (SMS)

* Integrate SMS gateway (e.g., Africa’s Talking, Twillio)
* OTP verification during registration

## PIN Encryption

* Hash all PINs using Bcrypt
* Remove plaintext PIN storage

## Real-Time Notifications

* Implement Laravel Reverb
* Push confirmation alerts before finalizing payments

## Improved Error Handling

* Structured API error responses
* Clear expiration messages
* Balance-related error clarity

---

# 11. Contributors

NiaLink Engineering Team

---

# 12. License

This project is licensed under the MIT License.

---

# 🌍 NiaLink

**Fast. Secure. Reliable.**

Powering the future of digital payments across Africa.
