<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Merchant;
use App\Models\Terminal;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ManagementController
 *
 * High-privilege controller for NiaLink internal administrators.
 * All routes in this controller are protected by:
 *   - auth:sanctum  → valid Bearer token required
 *   - can:admin-access → user_role must equal 'admin' (Gate defined in AppServiceProvider)
 *
 * Registered routes (routes/api.php):
 *   GET    /api/admin/stats
 *   GET    /api/admin/merchants
 *   POST   /api/admin/merchants/{id}/approve
 *   POST   /api/admin/merchants/{id}/suspend
 *   GET    /api/admin/transactions
 *   GET    /api/admin/audit
 *   GET    /api/admin/wallets
 *   GET    /api/admin/terminals
 *   POST   /api/admin/terminals/{id}/lock
 *   POST   /api/admin/terminals/{id}/unlock
 */
class ManagementController extends Controller
{
    // ─────────────────────────────────────────────────────────────
    //  DASHBOARD STATS
    //  GET /api/admin/stats
    //  Powers the 4 summary cards at the top of the dashboard.
    // ─────────────────────────────────────────────────────────────

    public function dashboard()
    {
        return response()->json([
            // Sum of every wallet balance in the system (users + merchants).
            // This is the total "float" — all digital money NiaLink holds in trust.
            'total_liquidity' => (float) Wallet::sum('balance'),

            // Sum of the fee column on every completed transaction.
            // NiaLink charges 1% per transaction — this is cumulative revenue.
            'total_revenue' => (float) Transaction::where('status', 'completed')->sum('fee'),

            // Total transaction value in the last 24 hours (completed only).
            // Used to measure system velocity and detect unusual spikes.
            'volume_24h' => (float) Transaction::where('status', 'completed')
                ->where('created_at', '>=', now()->subDay())
                ->sum('amount'),

            // Number of merchants waiting for admin KYC review.
            // Drives the badge on the sidebar and the KYC queue panel.
            'pending_merchants' => Merchant::where('status', 'pending')->count(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  MERCHANT LIST
    //  GET /api/admin/merchants?status=pending|active|suspended
    //  Powers the Merchants table. Accepts optional status filter.
    // ─────────────────────────────────────────────────────────────

    public function listMerchants(Request $request)
    {
        $query = Merchant::with(['wallet', 'terminals'])
            ->orderBy('created_at', 'desc');

        // Optional filter: ?status=pending, ?status=active, ?status=suspended
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return response()->json($query->get());
    }

    // ─────────────────────────────────────────────────────────────
    //  MERCHANT APPROVAL (KYC)
    //  POST /api/admin/merchants/{id}/approve
    //  Activates a pending merchant and provisions their KES wallet.
    // ─────────────────────────────────────────────────────────────

    public function approveMerchant(Request $request, $id)
    {
        $merchant = Merchant::findOrFail($id);

        return DB::transaction(function () use ($merchant) {

            $merchant->update([
                'status'      => 'active',
                'verified_at' => now(),
            ]);

            // Provision a KES wallet if one does not exist yet.
            // firstOrCreate ensures this is idempotent — calling approve
            // twice does not create duplicate wallets.
            $merchant->wallet()->firstOrCreate(
                [
                    'walletable_id'   => $merchant->id,
                    'walletable_type' => Merchant::class,
                    'currency'        => 'KES',
                ],
                [
                    'balance' => 0.00,
                    'status'  => 'active',
                ]
            );

            // Write an immutable audit trail entry.
            AuditLog::create([
                'user_id'       => auth()->id(),
                'action'        => 'merchant_approved',
                'resource_type' => Merchant::class,
                'resource_id'   => $merchant->id,
                'metadata'      => ['merchant_code' => $merchant->merchant_code],
                'ip_address'    => request()->ip(),
            ]);

            return response()->json([
                'message'  => "Merchant {$merchant->business_name} is now live.",
                'merchant' => $merchant->fresh(['wallet', 'terminals']),
            ]);
        });
    }

    // ─────────────────────────────────────────────────────────────
    //  MERCHANT SUSPENSION (Kill-Switch)
    //  POST /api/admin/merchants/{id}/suspend
    //  Instantly suspends a merchant. All their terminals become
    //  non-operational via Terminal::isOperational(), which checks
    //  the parent merchant status — no individual terminal updates needed.
    // ─────────────────────────────────────────────────────────────

    public function suspendMerchant($id)
    {
        $merchant = Merchant::findOrFail($id);

        $merchant->update(['status' => 'suspended']);

        AuditLog::create([
            'user_id'       => auth()->id(),
            'action'        => 'merchant_suspended',
            'resource_type' => Merchant::class,
            'resource_id'   => $merchant->id,
            'metadata'      => ['merchant_code' => $merchant->merchant_code],
            'ip_address'    => request()->ip(),
        ]);

        return response()->json([
            'message' => "Merchant {$merchant->business_name} has been suspended.",
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  TRANSACTION LEDGER
    //  GET /api/admin/transactions?status=completed|failed|pending&type=p2m|p2p
    //  Powers the Transactions table. Accepts optional status and type filters.
    // ─────────────────────────────────────────────────────────────

    public function listTransactions(Request $request)
    {
        $query = Transaction::with(['user', 'merchant'])
            ->orderBy('created_at', 'desc')
            ->limit(200); // Hard cap — paginate this in a future iteration

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }

        return response()->json($query->get());
    }

    // ─────────────────────────────────────────────────────────────
    //  AUDIT LOG
    //  GET /api/admin/audit
    //  Powers the Audit Log table. Returns the 200 most recent entries.
    //  Note: user_id uses onDelete('set null') so logs survive account deletion.
    // ─────────────────────────────────────────────────────────────

    public function listAuditLogs()
    {
        return response()->json(
            AuditLog::orderBy('created_at', 'desc')->limit(200)->get()
        );
    }

    // ─────────────────────────────────────────────────────────────
    //  LIQUIDITY / WALLET OVERVIEW
    //  GET /api/admin/wallets
    //  Powers the Liquidity Monitor view. Returns total float and
    //  a full breakdown of every wallet in the system.
    // ─────────────────────────────────────────────────────────────

    public function listWallets()
    {
        return response()->json([
            'total_liquidity' => (float) Wallet::sum('balance'),
            'active_liquidity' => (float) Wallet::where('status', 'active')->sum('balance'),
            'frozen_liquidity' => (float) Wallet::where('status', '!=', 'active')->sum('balance'),
            'wallets' => Wallet::orderBy('balance', 'desc')->get(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  TERMINAL LIST
    //  GET /api/admin/terminals
    //  Powers the Terminals table. Loads the parent merchant so the
    //  table can display the business name alongside each terminal.
    // ─────────────────────────────────────────────────────────────

    public function listTerminals()
    {
        return response()->json(
            Terminal::with('merchant')
                ->orderBy('status', 'asc') // active terminals first
                ->orderBy('last_active_at', 'desc')
                ->get()
        );
    }

    // ─────────────────────────────────────────────────────────────
    //  TERMINAL LOCK
    //  POST /api/admin/terminals/{id}/lock
    //  Locks a specific terminal without touching the merchant account
    //  or any other terminals. Useful for suspicious activity on one till.
    // ─────────────────────────────────────────────────────────────

    public function lockTerminal($id)
    {
        $terminal = Terminal::findOrFail($id);
        $terminal->update(['status' => 'locked']);

        AuditLog::create([
            'user_id'       => auth()->id(),
            'action'        => 'terminal_locked',
            'resource_type' => Terminal::class,
            'resource_id'   => $terminal->id,
            'metadata'      => [
                'terminal_code' => $terminal->terminal_code,
                'merchant_id'   => $terminal->merchant_id,
            ],
            'ip_address'    => request()->ip(),
        ]);

        return response()->json([
            'message'  => "Terminal {$terminal->terminal_code} has been locked.",
            'terminal' => $terminal->fresh(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  TERMINAL UNLOCK
    //  POST /api/admin/terminals/{id}/unlock
    //  Reactivates a locked or inactive terminal.
    //  The terminal is only truly operational if the parent merchant
    //  is also active — Terminal::isOperational() enforces this.
    // ─────────────────────────────────────────────────────────────

    public function unlockTerminal($id)
    {
        $terminal = Terminal::findOrFail($id);
        $terminal->update(['status' => 'active']);

        AuditLog::create([
            'user_id'       => auth()->id(),
            'action'        => 'terminal_unlocked',
            'resource_type' => Terminal::class,
            'resource_id'   => $terminal->id,
            'metadata'      => [
                'terminal_code' => $terminal->terminal_code,
                'merchant_id'   => $terminal->merchant_id,
            ],
            'ip_address'    => request()->ip(),
        ]);

        return response()->json([
            'message'  => "Terminal {$terminal->terminal_code} is now active.",
            'terminal' => $terminal->fresh(['merchant']),
        ]);
    }
}
