<?php

namespace App\Http\Controllers\Wallet;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\LimitService;
use App\Services\MpesaService;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __construct(
        protected MpesaService       $mpesaService,
        protected TransactionService $transactionService,
        protected LimitService       $limitService,
        protected AuditService       $auditService,
    ) {}

    /**
     * Get the authenticated consumer's wallet balance and limits.
     */
    public function show(Request $request): JsonResponse
    {
        $user   = $request->user();
        $wallet = $user->wallet;

        if (! $wallet) {
            return response()->json(['message' => 'Wallet not found.'], 404);
        }

        return response()->json([
            'balance'        => number_format((float) $wallet->balance, 2),
            'currency'       => $wallet->currency,
            'status'         => $wallet->status,
            'total_credited' => number_format((float) $wallet->total_credited, 2),
            'total_debited'  => number_format((float) $wallet->total_debited, 2),
            'limits'         => [
                'p2m'             => $this->limitService->resolve($user, 'p2m'),
                'p2p'             => $this->limitService->resolve($user, 'p2p'),
                'atm'             => $this->limitService->resolve($user, 'atm'),
                'count'           => $this->limitService->resolve($user, 'count'),
                'remaining_p2m'   => $this->limitService->remainingToday($user, 'p2m'),
                'remaining_p2p'   => $this->limitService->remainingToday($user, 'p2p'),
                'remaining_atm'   => $this->limitService->remainingToday($user, 'atm'),
            ],
            'last_transaction_at' => $wallet->last_transaction_at?->toIso8601String(),
        ]);
    }

    /**
     * Initiate an M-Pesa STK Push to top up the wallet.
     *
     * Returns 202 Accepted immediately — wallet is credited asynchronously
     * when Safaricom's callback arrives via ProcessMpesaTopUpCallback job.
     *
     * The app should show "Waiting for M-Pesa confirmation..." and either
     * poll GET /wallet or listen for a push notification.
     */
    public function topUp(Request $request): JsonResponse
    {
        $request->validate([
            'amount' => ['required', 'numeric', 'min:10', 'max:150000'],
        ]);

        $user   = $request->user();
        $amount = (float) $request->amount;

        // Check ATM limit — top-ups count against the inbound daily limit
        $check = $this->limitService->check($user, 'atm', $amount);
        if (! $check['allowed']) {
            return response()->json(['message' => $check['reason']], 422);
        }

        try {
            $mpesaTransaction = $this->mpesaService->initiateTopUp($user, $amount);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() ?: 502);
        }

        $this->auditService->log(
            'wallet.topup.initiated',
            $mpesaTransaction,
            ['amount' => $amount],
            $user->id,
        );

        return response()->json([
            'status'     => 'pending',
            'message'    => 'M-Pesa PIN prompt sent to ' . $user->phone_number . '. Enter your PIN to complete.',
            'reference'  => $mpesaTransaction->id,
            'amount'     => number_format($amount, 2),
        ], 202);
    }

    /**
     * Withdraw from the NiaLink wallet to M-Pesa.
     *
     * Debits the wallet immediately so the user cannot spend the funds
     * while the B2C payout is in flight.
     *
     * If the B2C payout fails, ProcessMpesaB2CCallback calls
     * TransactionService::refundWithdrawal() to return the funds.
     */
    public function withdraw(Request $request): JsonResponse
    {
        $request->validate([
            'amount' => ['required', 'numeric', 'min:10'],
        ]);

        $user   = $request->user();
        $amount = (float) $request->amount;

        if ($user->wallet->isFrozen()) {
            return response()->json([
                'message' => 'Your wallet is currently frozen. Contact support.',
            ], 403);
        }

        if (! $user->wallet->hasSufficientFunds($amount)) {
            return response()->json([
                'message' => 'Insufficient balance. Available: KES ' .
                             number_format((float) $user->wallet->balance, 2),
            ], 422);
        }

        // Create the withdrawal transaction and debit wallet
        try {
            $transaction = $this->transactionService->processWithdrawal($user, $amount);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() ?: 400);
        }

        // Initiate B2C payout — if this fails the callback job will refund
        try {
            $mpesaTransaction = $this->mpesaService->initiateB2C($user, $amount, 'withdrawal');

            // Link the M-Pesa transaction to the internal transaction
            $mpesaTransaction->update(['transaction_id' => $transaction->id]);

        } catch (\Exception $e) {
            // B2C initiation failed — refund the wallet immediately
            $this->transactionService->refundWithdrawal($transaction);

            return response()->json([
                'message' => 'M-Pesa payout could not be initiated. Your funds have been returned.',
            ], 502);
        }

        return response()->json([
            'status'    => 'pending',
            'message'   => 'Withdrawal of KES ' . number_format($amount, 2) .
                           ' initiated. You will receive an M-Pesa SMS shortly.',
            'reference' => $transaction->reference,
            'amount'    => number_format($amount, 2),
        ], 202);
    }

    /**
     * Get the consumer's transaction history.
     * Returns all transactions where the user is sender or receiver.
     * Paginated — 20 per page.
     */
    public function history(Request $request): JsonResponse
    {
        $user = $request->user();

        $transactions = $user->transactions()
            ->with(['merchant:id,business_name', 'receiver:id,name', 'sender:id,name'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($transactions);
    }

    /**
     * Get the consumer's M-Pesa top-up history.
     */
    public function topUpHistory(Request $request): JsonResponse
    {
        $history = $request->user()
            ->mpesaTransactions()
            ->where('type', 'topup')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($history);
    }
}
