<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Terminal;
use App\Services\AuditService;
use App\Services\PaymentCodeService;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MerchantPaymentController extends Controller
{
    public function __construct(
        protected PaymentCodeService $codeService,
        protected TransactionService $transactionService,
        protected AuditService       $auditService,
    ) {}

    /**
     * Process a Nia-Code payment at a merchant terminal.
     *
     * Two-step flow (replaces the old 3-phase push-to-approve):
     *   1. PaymentCodeService::validate() — verifies code in Redis, locks it,
     *      moves transaction to 'processing', attaches terminal + amount
     *   2. TransactionService::processPayment() — debits consumer wallet,
     *      credits merchant wallet, writes ledger entries, completes transaction
     *
     * Both steps happen in a single HTTP request — no push notification needed.
     * The consumer shows the code; the terminal submits it with the amount.
     * Settlement is instant.
     *
     * Authentication:
     *   The terminal authenticates using its terminal_code + terminal_secret.
     *   Both are pre-configured on the device during onboarding.
     *   terminal_secret is verified here before any payment logic runs.
     */
    public function process(Request $request): JsonResponse
    {
        $request->validate([
            'nialink_code'    => ['required', 'string', 'size:6'],
            'terminal_code'   => ['required', 'string', 'exists:terminals,terminal_code'],
            'terminal_secret' => ['required', 'string'],
            'amount'          => ['required', 'numeric', 'min:1', 'max:1000000'],
        ]);

        // ── Step 0: Authenticate the terminal ────────────────────────────────

        $terminal = Terminal::where('terminal_code', $request->terminal_code)
            ->with('merchant.wallet')
            ->first();

        // Verify the terminal secret — hashed with bcrypt in the DB
        if (! \Illuminate\Support\Facades\Hash::check($request->terminal_secret, $terminal->terminal_secret)) {
            $this->auditService->logSecurity('terminal.auth_failed', [
                'terminal_code' => $request->terminal_code,
            ]);
            return response()->json([
                'status'  => 'error',
                'message' => 'Invalid terminal credentials.',
            ], 401);
        }

        // ── Step 1: Validate the Nia-Code ─────────────────────────────────────

        try {
            $transaction = $this->codeService->validate(
                $request->nialink_code,
                $terminal,
                (float) $request->amount,
            );
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 400);
        }

        // ── Step 2: Settle the payment ────────────────────────────────────────

        try {
            $completed = $this->transactionService->processPayment($transaction);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 400);
        }

        // ── Step 3: Respond to the terminal ───────────────────────────────────

        return response()->json([
            'status'    => 'success',
            'message'   => 'Payment completed.',
            'reference' => $completed->reference,
            'amount'    => number_format((float) $completed->amount, 2),
            'fee'       => number_format((float) $completed->fee, 2),
            'net'       => number_format($completed->netAmount(), 2),
            'merchant'  => $terminal->merchant->business_name,
            'timestamp' => $completed->updated_at->toIso8601String(),
        ]);
    }

    /**
     * Check the status of a transaction by reference.
     *
     * The terminal polls this after submitting a payment if it loses
     * connectivity mid-request. Returns the current status so the
     * cashier knows whether to retry or show success.
     *
     * This replaces the old TerminalController::checkStatus() method.
     */
    public function status(Request $request, string $reference): JsonResponse
    {
        $request->validate([
            'terminal_code'   => ['required', 'string', 'exists:terminals,terminal_code'],
            'terminal_secret' => ['required', 'string'],
        ]);

        $terminal = Terminal::where('terminal_code', $request->terminal_code)->first();

        if (! \Illuminate\Support\Facades\Hash::check($request->terminal_secret, $terminal->terminal_secret)) {
            return response()->json(['message' => 'Invalid terminal credentials.'], 401);
        }

        $transaction = \App\Models\Transaction::where('reference', $reference)
            ->where('terminal_id', $terminal->id)
            ->first();

        if (! $transaction) {
            return response()->json(['message' => 'Transaction not found.'], 404);
        }

        return response()->json([
            'reference'    => $transaction->reference,
            'status'       => $transaction->status,
            'amount'       => number_format((float) $transaction->amount, 2),
            'fee'          => number_format((float) $transaction->fee, 2),
            'is_finalized' => in_array($transaction->status, ['completed', 'failed', 'expired']),
            'timestamp'    => $transaction->updated_at->toIso8601String(),
        ]);
    }
}
