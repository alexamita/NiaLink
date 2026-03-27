<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\NiaLinkService;
use Illuminate\Http\Request;


/**
 * Used by the Customer's App to send the approval signal.
 */

class AppApprovalController extends Controller
{
    protected $niaLinkService;

    public function __construct(NiaLinkService $niaLinkService)
    {
        $this->niaLinkService = $niaLinkService;
    }

    /**
     * User clicks 'Approve' and enters their PIN on their phone.
     */
    public function approve(Request $request)
    {
        $request->validate([
            'transaction_id' => 'required|exists:transactions,id',
            'pin'            => 'required|digits:4',
        ]);

        try {
            $result = $this->niaLinkService->confirmPayment(
                $request->transaction_id,
                $request->pin
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Payment completed successfully.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }
}
