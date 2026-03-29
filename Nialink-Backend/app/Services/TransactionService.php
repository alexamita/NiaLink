<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\Terminal;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class TransactionService
{
    public function __construct(
        protected WalletService      $walletService,
        protected LimitService       $limitService,
        protected AuditService       $auditService,
        protected AmlService         $amlService,
        protected PaymentCodeService $codeService,
    ) {}

    /**
     * Process a P2M payment — consumer pays merchant via Nia-Code.
     *
     * Called by MerchantPaymentController after PaymentCodeService::validate()
     * has already verified the code and moved the transaction to 'processing'.
     *
     * What this does:
     *   1. Runs AML checks and limit checks
     *   2. Debits consumer wallet (full amount plus 1% fee on top)
     *   3. Credits merchant wallet (full amount, no deduction)
     *   4. Marks transaction as completed
     *   5. Clears the code from Redis
     *   6. Dispatches merchant webhook
     *   7. Writes audit log
     *
     * The fee (1%) stays in the NiaLink float — it is the gap between
     * what the consumer's wallet loses and what the merchant's wallet gains.
     *
     * @throws \Exception if AML blocked, limit exceeded, insufficient funds
     */
    public function processPayment(Transaction $transaction): Transaction
    {
        $user     = $transaction->user;
        $merchant = $transaction->merchant;
        $amount   = (float) $transaction->amount;

        $this->amlService->assertNoBlockingFlags($user);
        $this->amlService->checkTransaction($user, $amount);

        $check = $this->limitService->check($user, 'p2m', $amount);
        if (! $check['allowed']) {
            $this->fail($transaction, $check['reason']);
            throw new \Exception($check['reason'], 422);
        }

        // Fee is charged ON TOP of the payment amount.
        // Sender's wallet is debited amount + fee.
        // Merchant's wallet is credited the full amount.
        // NiaLink's revenue = fee (the gap between total debit and credit).
        $fee        = round($amount * 0.01, 2);
        $totalDebit = round($amount + $fee, 2);

        $check = $this->limitService->check($user, 'p2m', $totalDebit);

        try {
            DB::transaction(function () use ($transaction, $user, $merchant, $amount, $fee, $totalDebit) {

                // Debit sender: full amount + 1% fee
                $this->walletService->debit(
                    $user->wallet,
                    $totalDebit,
                    $transaction,
                    "Payment to {$merchant->business_name} (includes KES {$fee} NiaLink fee)",
                );

                // Credit merchant: full payment amount — no deduction
                $this->walletService->credit(
                    $merchant->wallet,
                    $amount,
                    $transaction,
                    "Payment from customer",
                );

                $transaction->update([
                    'fee'    => $fee,
                    'status' => 'completed',
                ]);
            });
        } catch (\Exception $e) {
            $this->fail($transaction, $e->getMessage());
            throw $e;
        }

        $codeKey = $transaction->payment_code_reference;
        if ($codeKey) {
            $code = str_replace('nialink_auth_', '', $codeKey);
            $this->codeService->consume($code, $user->id);
        }

        $this->auditService->log(
            'transaction.payment.completed',
            $transaction,
            [
                'amount'       => $amount,
                'fee'          => $fee,
                'total_debit'  => $totalDebit,
                'merchant'     => $merchant->business_name,
            ],
            $user->id,
        );

        $this->dispatchWebhook($transaction->fresh());

        return $transaction->fresh();
    }

    /**
     * Process a P2P transfer — consumer sends money to another consumer.
     *
     * No fee on P2P transfers.
     * Both consumers must have active wallets.
     *
     * @throws \Exception if AML blocked, limit exceeded, insufficient funds,
     *                    or sender and receiver are the same user
     */
    public function processTransfer(User $sender, User $receiver, float $amount): Transaction
    {
        if ($sender->id === $receiver->id) {
            throw new \Exception('You cannot transfer money to yourself.', 422);
        }

        $this->amlService->assertNoBlockingFlags($sender);
        $this->amlService->checkTransaction($sender, $amount);

        $check = $this->limitService->check($sender, 'p2p', $amount);
        if (! $check['allowed']) {
            throw new \Exception($check['reason'], 422);
        }

        return DB::transaction(function () use ($sender, $receiver, $amount) {

            $transaction = Transaction::create([
                'user_id'     => $sender->id,
                'sender_id'   => $sender->id,
                'receiver_id' => $receiver->id,
                'reference'   => $this->reference(),
                'amount'      => $amount,
                'fee'         => 0,
                'currency'    => 'KES',
                'type'        => 'p2p',
                'status'      => 'pending',
            ]);

            $this->walletService->debit(
                $sender->wallet,
                $amount,
                $transaction,
                "Transfer to {$receiver->name}",
            );

            $this->walletService->credit(
                $receiver->wallet,
                $amount,
                $transaction,
                "Transfer from {$sender->name}",
            );

            $transaction->update(['status' => 'completed']);

            $this->auditService->log(
                'transaction.transfer.completed',
                $transaction,
                [
                    'amount'   => $amount,
                    'receiver' => $receiver->name,
                ],
                $sender->id,
            );

            return $transaction->fresh();
        });
    }

    /**
     * Record a top-up transaction after M-Pesa callback confirms funds arrived.
     * Called by ProcessMpesaTopUpCallback job — NOT directly by any controller.
     *
     * The wallet credit happens here so the job stays thin.
     * MpesaTransaction row must already be marked completed before calling this.
     */
    public function processTopUp(User $user, float $amount, string $mpesaReceipt): Transaction
    {
        return DB::transaction(function () use ($user, $amount, $mpesaReceipt) {

            $transaction = Transaction::create([
                'user_id'   => $user->id,
                'reference' => $this->reference(),
                'amount'    => $amount,
                'fee'       => 0,
                'currency'  => 'KES',
                'type'      => 'topup',
                'status'    => 'pending',
                'metadata'  => ['mpesa_receipt' => $mpesaReceipt],
            ]);

            $this->walletService->credit(
                $user->wallet,
                $amount,
                $transaction,
                "M-Pesa top-up — receipt {$mpesaReceipt}",
            );

            $transaction->update(['status' => 'completed']);

            $this->auditService->log(
                'transaction.topup.completed',
                $transaction,
                ['amount' => $amount, 'mpesa_receipt' => $mpesaReceipt],
                $user->id,
            );

            return $transaction->fresh();
        });
    }

    /**
     * Record a withdrawal transaction and debit the wallet.
     * Called by WalletController::withdraw() before initiating B2C payout.
     *
     * The wallet is debited immediately so the user cannot spend the funds
     * while the B2C payout is in flight. If B2C fails, ProcessMpesaB2CCallback
     * calls refundWithdrawal() to return the funds.
     */
    public function processWithdrawal(User $user, float $amount): Transaction
    {
        $check = $this->limitService->check($user, 'atm', $amount);
        if (! $check['allowed']) {
            throw new \Exception($check['reason'], 422);
        }

        return DB::transaction(function () use ($user, $amount) {

            $transaction = Transaction::create([
                'user_id'   => $user->id,
                'reference' => $this->reference(),
                'amount'    => $amount,
                'fee'       => 0,
                'currency'  => 'KES',
                'type'      => 'withdrawal',
                'status'    => 'pending',
            ]);

            $this->walletService->debit(
                $user->wallet,
                $amount,
                $transaction,
                "Withdrawal to M-Pesa",
            );

            $this->auditService->log(
                'transaction.withdrawal.initiated',
                $transaction,
                ['amount' => $amount],
                $user->id,
            );

            return $transaction->fresh();
        });
    }

    /**
     * Refund a failed withdrawal back to the user's wallet.
     * Called by ProcessMpesaB2CCallback when B2C payout fails.
     * Creates a compensating credit — never modifies the original debit.
     */
    public function refundWithdrawal(Transaction $transaction): void
    {
        if ($transaction->type !== 'withdrawal') {
            throw new \LogicException('Only withdrawal transactions can be refunded this way.');
        }

        DB::transaction(function () use ($transaction) {

            // Compensating credit — money returns to wallet
            $this->walletService->credit(
                $transaction->user->wallet,
                (float) $transaction->amount,
                $transaction,
                "Withdrawal refund — M-Pesa payout failed",
            );

            $transaction->update([
                'status'         => 'failed',
                'failure_reason' => 'M-Pesa B2C payout failed — funds returned to wallet',
            ]);
        });

        $this->auditService->log(
            'transaction.withdrawal.refunded',
            $transaction,
            ['amount' => $transaction->amount],
            $transaction->user_id,
        );
    }

    /**
     * Mark a transaction as failed without moving any money.
     * Called when a pre-condition check fails before the DB transaction opens.
     */
    public function fail(Transaction $transaction, string $reason): void
    {
        $transaction->update([
            'status'         => 'failed',
            'failure_reason' => $reason,
        ]);
    }

    /**
     * Dispatch the merchant webhook after a completed payment.
     * Fires asynchronously — failure here must not affect the payment result.
     * Signed with HMAC-SHA256 using the merchant's api_key.
     */
    private function dispatchWebhook(Transaction $transaction): void
    {
        $merchant = $transaction->merchant;

        if (! $merchant?->webhook_url || ! $merchant?->api_key) {
            return;
        }

        $payload = [
            'event' => 'payment.completed',
            'data'  => [
                'reference' => $transaction->reference,
                'amount'    => $transaction->amount,
                'fee'       => $transaction->fee,
                'net'       => $transaction->netAmount(),
                'currency'  => $transaction->currency,
                'status'    => 'completed',
                'terminal'  => $transaction->terminal?->terminal_code,
            ],
        ];

        Http::async()->post($merchant->webhook_url, array_merge($payload, [
            'signature' => hash_hmac('sha256', $transaction->reference, $merchant->api_key),
        ]));
    }

    /**
     * Generate a unique transaction reference.
     * Format: NL-YYYYMMDD-XXXXXXXX
     */
    private function reference(): string
    {
        return 'NL-' . now()->format('Ymd') . '-' . strtoupper(Str::random(8));
    }
}
