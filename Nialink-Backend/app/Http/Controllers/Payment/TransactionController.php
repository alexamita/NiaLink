<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AuditService;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function __construct(
        protected TransactionService $transactionService,
        protected AuditService       $auditService,
    ) {}

    /**
     * Get the authenticated user's transaction history.
     * Paginated — 20 per page.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $transactions = Transaction::where('user_id', $user->id)
            ->orWhere('sender_id', $user->id)
            ->orWhere('receiver_id', $user->id)
            ->with([
                'merchant:id,business_name',
                'sender:id,name,phone_number',
                'receiver:id,name,phone_number',
                'terminal:id,name,terminal_code',
            ])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($transactions);
    }

    /**
     * Get a single transaction by reference.
     * User must be the sender, receiver, or initiator.
     */
    public function show(Request $request, string $reference): JsonResponse
    {
        $user = $request->user();

        $transaction = Transaction::where('reference', $reference)
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->orWhere('sender_id', $user->id)
                    ->orWhere('receiver_id', $user->id);
            })
            ->with([
                'merchant:id,business_name,category',
                'sender:id,name,phone_number',
                'receiver:id,name,phone_number',
                'terminal:id,name,location_note',
                'ledgerEntries',
            ])
            ->first();

        if (! $transaction) {
            return response()->json(['message' => 'Transaction not found.'], 404);
        }

        return response()->json($transaction);
    }

    /**
     * Initiate a P2P transfer to another consumer.
     *
     * The recipient is identified by phone number — the user never
     * types a wallet ID or account number.
     */
    public function transfer(Request $request): JsonResponse
    {
        $request->validate([
            'phone_number' => ['required', 'string', 'exists:users,phone_number'],
            'amount'       => ['required', 'numeric', 'min:10'],
            'description'  => ['nullable', 'string', 'max:100'],
        ]);

        $sender   = $request->user();
        $receiver = User::where('phone_number', $request->phone_number)->first();

        if ($sender->id === $receiver->id) {
            return response()->json([
                'message' => 'You cannot transfer money to yourself.',
            ], 422);
        }

        if ($receiver->status !== 'active') {
            return response()->json([
                'message' => 'The recipient account is not active.',
            ], 422);
        }

        if (! $receiver->wallet || $receiver->wallet->isFrozen()) {
            return response()->json([
                'message' => 'The recipient wallet is not available.',
            ], 422);
        }

        try {
            $transaction = $this->transactionService->processTransfer(
                $sender,
                $receiver,
                (float) $request->amount,
            );
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 400);
        }

        return response()->json([
            'status'    => 'success',
            'message'   => 'KES ' . number_format((float) $request->amount, 2) .
                'sent to' . $receiver->name . '.',
            'reference' => $transaction->reference,
            'amount'    => number_format((float) $transaction->amount, 2),
            'recipient' => [
                'name'         => $receiver->name,
                'phone_number' => $receiver->phone_number,
            ],
        ]);
    }
}
