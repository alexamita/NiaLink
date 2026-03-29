<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaymentCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NiaCodeController extends Controller
{
    public function __construct(
        protected PaymentCodeService $codeService,
    ) {}

    /**
     * Generate a new 6-digit Nia-Code.
     * Cancels any existing active code for this user first.
     * Code lives in Redis only — never stored in the database.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'amount' => ['nullable', 'numeric', 'min:1'],
        ]);

        try {
            $result = $this->codeService->generate(
                $request->user(),
                $request->amount ? (float) $request->amount : null,
            );
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 400);
        }

        return response()->json([
            'status' => 'success',
            'data'   => $result,
        ]);
    }

    /**
     * Cancel the user's currently active Nia-Code.
     * Called when the user closes the payment screen without completing.
     */
    public function cancel(Request $request): JsonResponse
    {
        $this->codeService->cancelExistingCode($request->user());

        return response()->json([
            'status'  => 'success',
            'message' => 'Payment code cancelled.',
        ]);
    }
}
