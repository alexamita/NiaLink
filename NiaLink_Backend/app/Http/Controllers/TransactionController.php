<?php

namespace App\Http\Controllers;

use App\Services\NiaLinkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Http\Resources\TransactionResource;

/**
 * TransactionController
 * Gateway for code generation, merchant claims, and customer PIN approval.
 */
class TransactionController extends Controller
{
    protected $service;

    /**
     * Inject the service via the constructor so all methods can use it.
     */
    public function __construct(NiaLinkService $service)
    {
        $this->service = $service;
    }

    /**
     * Step 1: Request a new 6-digit payment code (Customer Side).
     * Now uses Auth::id() to ensure users can't generate codes for others.
     */
    public function getNewCode(Request $request)
    {
        $transaction = $this->service->generateCode(Auth::id());

        return response()->json([
            'status' => 'success',
            'data' => new TransactionResource(\App\Models\Transaction::find($transaction['id']))
        ]);
    }

    /**
     * Step 2: Merchant initiates the "Pull" (Merchant Side).
     * This puts the transaction into 'processing' and triggers the user's phone.
     */
    public function claimPayment(Request $request)
    {
        $validated = $request->validate([
            'nialink_code' => 'required|string|size:6',
            'merchant_code' => 'required|string',
            'amount' => 'required|numeric|min:1'
        ]);

        $result = $this->service->completePayment(
            $validated['nialink_code'],
            $validated['merchant_code'],
            $validated['amount']
        );

        return response()->json($result, $result['status'] === 'success' ? 200 : 400);
    }

    /**
     * Step 3: Customer authorizes with 4-digit PIN (Customer Side).
     * This is called by the mobile app after the user sees the push notification.
     */
    public function approveWithPin(Request $request)
    {
        $validated = $request->validate([
            'transaction_id' => 'required|exists:transactions,id',
            'pin' => 'required|string|size:4'
        ]);

        $result = $this->service->confirmPayment(
            $request->transaction_id,
            $request->pin
        );

        if ($result['status'] === 'success') {
            return response()->json([
                'status' => 'success',
                'message' => 'Payment completed.',
                'data' => new TransactionResource(\App\Models\Transaction::find($request->transaction_id))
            ]);
        }

        return response()->json($result, 400);
    }
}
