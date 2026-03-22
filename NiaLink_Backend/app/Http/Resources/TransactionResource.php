<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'amount' => number_format((float) $this->amount, 2),
            'fee' => number_format((float) $this->fee, 2),

            // SECURITY LOGIC:
            // 1. If user is an Admin, show the code.
            // 2. If transaction is PENDING, show the code (User needs to read it to the merchant).
            // 3. Otherwise, return null or masked.
            'nialink_code' => $this->shouldShowCode() ? $this->nialink_code : null,

            'status' => strtoupper($this->status),
            'merchant_name' => $this->merchant->business_name ?? 'N/A',
            'timestamp' => $this->created_at->toDateTimeString(),
        ];
    }

    /**
     * Determine if the current user/context is authorized to see the raw code.
     */
    private function shouldShowCode(): bool
    {
        // Always show to Admins
        if (Gate::allows('admin-access')) {
            return true;
        }

        // Only show to the User/Merchant if it's still active/pending
        // Once completed, failed, or processing, the code is "burned" and hidden.
        return $this->status === 'pending';
    }
}
