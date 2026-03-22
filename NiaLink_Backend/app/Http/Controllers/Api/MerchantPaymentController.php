<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NiaLinkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Handles the Merchant's request to "claim" a Nia-Code provided by a customer.
 */
class MerchantPaymentController extends Controller
{
    protected $niaLinkService;

    public function __construct(NiaLinkService $niaLinkService)
    {
        $this->niaLinkService = $niaLinkService;
    }

    /**
     * Submit a payment request using a Nia-Code.
     * This is the "Pull" phase of the transaction.
     */
    public function process(Request $request)
    {
        // 1. Validate the incoming POS request
        $validator = Validator::make($request->all(), [
            'nialink_code'  => 'required|string|size:6',
            'merchant_code' => 'required|string|exists:merchants,merchant_code',
            'amount'        => 'required|numeric|min:1',
            'terminal_id'   => 'nullable|exists:terminals,terminal_code',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        // 2. Hand over to Service for atomic processing
        // The Service handles the Double-Entry math and Redis locking.
        $result = $this->niaLinkService->completePayment(
            $request->nialink_code,
            $request->merchant_code,
            $request->amount
        );

        // 3. Respond to the Merchant POS
        if ($result['status'] === 'success') {
            return response()->json([
                'status'  => 'success',
                'message' => 'Payment authorized successfully.',
                'data'    => $result
            ], 200);
        }

        return response()->json($result, 400);
    }
}
