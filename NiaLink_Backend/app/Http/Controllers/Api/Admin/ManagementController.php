<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Wallet;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * High-privilege controller for NiaLink internal administrators.
 */
class ManagementController extends Controller
{
    /**
     * Dashboard Overview: Total system health and liquidity.
     */
    public function dashboard()
    {
        return response()->json([
            // Sum of all user and merchant balances
            'total_liquidity' => Wallet::sum('balance'),

            // NiaLink's total revenue (sum of all transaction fees)
            'total_revenue' => Transaction::where('status', 'completed')->sum('fee'),

            // Transaction velocity for the last 24 hours
            'volume_24h' => Transaction::where('status', 'completed')
                ->where('created_at', '>=', now()->subDay())
                ->sum('amount'),

            'pending_merchants' => Merchant::where('status', 'pending')->count(),
        ]);
    }

    /**
     * KYC Approval: Move a merchant from 'pending' to 'active'.
     */
    public function approveMerchant(Request $request, $id)
    {
        $merchant = Merchant::findOrFail($id);

        return DB::transaction(function () use ($merchant) {
            $merchant->update([
                'status' => 'active',
                'verified_at' => now(),
            ]);

            // Automatically provision a wallet if one doesn't exist
            $merchant->wallet()->firstOrCreate([
                'currency' => 'KES',
                'balance' => 0.00,
                'status' => 'active',
            ]);

            // Log the administrative action for security auditing
            \App\Models\AuditLog::create([
                'user_id' => auth()->id(),
                'action' => 'merchant_approved',
                'resource_type' => Merchant::class,
                'resource_id' => $merchant->id,
                'metadata' => ['merchant_code' => $merchant->merchant_code],
                'ip_address' => Request::ip(),
            ]);

            return response()->json(['message' => "Merchant {$merchant->business_name} is now live."]);
        });
    }

    /**
     * Kill-Switch: Immediately suspend a merchant and their terminals.
     */
    public function suspendMerchant($id)
    {
        $merchant = Merchant::findOrFail($id);

        $merchant->update(['status' => 'suspended']);

        // Terminals check merchant status via isOperational() helper we built in the Model.

        return response()->json(['message' => 'Merchant suspended successfully.']);
    }
}
