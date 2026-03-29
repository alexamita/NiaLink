<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\AmlFlag;
use App\Models\FloatTransaction;
use App\Models\Merchant;
use App\Models\Terminal;
use App\Models\Transaction;
use App\Models\TrustAccountSnapshot;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AuditService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ManagementController extends Controller
{
    public function __construct(
        protected AuditService $auditService,
        protected WalletService $walletService,
    ) {}

    // =========================================================================
    // DASHBOARD
    // =========================================================================

    public function dashboard(): JsonResponse
    {
        return response()->json([
            'total_liquidity'   => (float) Wallet::sum('balance'),
            'total_revenue'     => (float) Transaction::where('status', 'completed')->sum('fee'),
            'volume_24h'        => (float) Transaction::where('status', 'completed')
                                        ->where('created_at', '>=', now()->subDay())
                                        ->sum('amount'),
            'pending_merchants' => Merchant::where('status', 'pending')->count(),
            'open_aml_flags'    => AmlFlag::where('status', 'open')->count(),
            'critical_flags'    => AmlFlag::where('status', 'open')
                                        ->where('severity', 'critical')
                                        ->count(),
            'float_status'      => TrustAccountSnapshot::latest('reconciled_at')
                                        ->first()?->status ?? 'no_reconciliation_yet',
        ]);
    }

    // =========================================================================
    // MERCHANT MANAGEMENT
    // =========================================================================

    public function listMerchants(Request $request): JsonResponse
    {
        $query = Merchant::with(['wallet', 'terminals', 'owner:id,name,email'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return response()->json($query->paginate(50));
    }

    public function approveMerchant(Request $request, string $id): JsonResponse
    {
        $merchant = Merchant::findOrFail($id);

        if ($merchant->status === 'active') {
            return response()->json(['message' => 'Merchant is already active.'], 422);
        }

        DB::transaction(function () use ($merchant) {
            $merchant->update([
                'status'      => 'active',
                'verified_at' => now(),
            ]);

            // Idempotent wallet provision
            $this->walletService->createFor($merchant);

            AuditLog::record('merchant.approved', $merchant, auth()->id(), [
                'merchant_code' => $merchant->merchant_code,
            ]);
        });

        return response()->json([
            'message'  => "{$merchant->business_name} is now live.",
            'merchant' => $merchant->fresh(['wallet', 'terminals']),
        ]);
    }

    public function suspendMerchant(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $merchant = Merchant::findOrFail($id);
        $merchant->update(['status' => 'suspended']);

        AuditLog::record('merchant.suspended', $merchant, auth()->id(), [
            'merchant_code' => $merchant->merchant_code,
            'reason'        => $request->reason,
        ]);

        return response()->json([
            'message' => "{$merchant->business_name} has been suspended.",
        ]);
    }

    public function rejectMerchant(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $merchant = Merchant::findOrFail($id);
        $merchant->update([
            'status'           => 'rejected',
            'rejection_reason' => $request->reason,
        ]);

        AuditLog::record('merchant.rejected', $merchant, auth()->id(), [
            'reason' => $request->reason,
        ]);

        return response()->json([
            'message' => "{$merchant->business_name} has been rejected.",
        ]);
    }

    // =========================================================================
    // MERCHANT PROFILE (for merchant_admin role)
    // =========================================================================

    public function merchantProfile(Request $request): JsonResponse
    {
        $merchant = Merchant::with(['wallet', 'terminals'])
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return response()->json($merchant);
    }

    public function merchantTransactions(Request $request): JsonResponse
    {
        $merchant = Merchant::where('user_id', $request->user()->id)->firstOrFail();

        return response()->json(
            $merchant->transactions()
                ->with(['terminal:id,name,terminal_code'])
                ->orderBy('created_at', 'desc')
                ->paginate(50)
        );
    }

    // =========================================================================
    // TRANSACTION LEDGER
    // =========================================================================

    public function listTransactions(Request $request): JsonResponse
    {
        $query = Transaction::with(['user:id,name,phone_number', 'merchant:id,business_name'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->query('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->query('to'));
        }

        return response()->json($query->paginate(100));
    }

    // =========================================================================
    // AUDIT LOGS
    // =========================================================================

    public function listAuditLogs(Request $request): JsonResponse
    {
        $query = AuditLog::with('user:id,name,email')
            ->orderBy('created_at', 'desc');

        if ($request->filled('action')) {
            $query->where('action', 'like', '%' . $request->query('action') . '%');
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->query('user_id'));
        }

        return response()->json($query->paginate(100));
    }

    // =========================================================================
    // LIQUIDITY / FLOAT MONITOR
    // =========================================================================

    public function listWallets(): JsonResponse
    {
        return response()->json([
            'total_liquidity'  => (float) Wallet::sum('balance'),
            'active_liquidity' => (float) Wallet::where('status', 'active')->sum('balance'),
            'frozen_liquidity' => (float) Wallet::where('status', 'frozen')->sum('balance'),
            'wallet_count'     => Wallet::count(),
            'frozen_count'     => Wallet::where('status', 'frozen')->count(),
            'wallets'          => Wallet::with('walletable')
                                    ->orderBy('balance', 'desc')
                                    ->paginate(100),
        ]);
    }

    public function listFloatTransactions(Request $request): JsonResponse
    {
        return response()->json(
            FloatTransaction::orderBy('created_at', 'desc')->paginate(100)
        );
    }

    public function listReconciliations(): JsonResponse
    {
        return response()->json(
            TrustAccountSnapshot::orderBy('reconciled_at', 'desc')->paginate(30)
        );
    }

    // =========================================================================
    // TERMINAL MANAGEMENT
    // =========================================================================

    public function listTerminals(): JsonResponse
    {
        return response()->json(
            Terminal::with('merchant:id,business_name,status')
                ->orderByRaw("FIELD(status, 'active', 'locked', 'inactive')")
                ->orderBy('last_active_at', 'desc')
                ->paginate(100)
        );
    }

    public function lockTerminal(string $id): JsonResponse
    {
        $terminal = Terminal::findOrFail($id);
        $terminal->update(['status' => 'locked']);

        AuditLog::record('terminal.locked', $terminal, auth()->id(), [
            'terminal_code' => $terminal->terminal_code,
            'merchant_id'   => $terminal->merchant_id,
        ]);

        return response()->json([
            'message'  => "Terminal {$terminal->terminal_code} has been locked.",
            'terminal' => $terminal->fresh(),
        ]);
    }

    public function unlockTerminal(string $id): JsonResponse
    {
        $terminal = Terminal::findOrFail($id);
        $terminal->update(['status' => 'active']);

        AuditLog::record('terminal.unlocked', $terminal, auth()->id(), [
            'terminal_code' => $terminal->terminal_code,
            'merchant_id'   => $terminal->merchant_id,
        ]);

        return response()->json([
            'message'  => "Terminal {$terminal->terminal_code} is now active.",
            'terminal' => $terminal->fresh(['merchant']),
        ]);
    }

    // =========================================================================
    // AML FLAG MANAGEMENT
    // =========================================================================

    public function listAmlFlags(Request $request): JsonResponse
    {
        $query = AmlFlag::with([
                'user:id,name,phone_number',
                'transaction:id,reference,amount,type',
                'reviewer:id,name',
            ])
            ->orderByRaw("FIELD(severity, 'critical', 'high', 'medium', 'low')")
            ->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('severity')) {
            $query->where('severity', $request->query('severity'));
        }

        return response()->json($query->paginate(50));
    }

    public function reviewAmlFlag(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'action'        => ['required', 'in:claim,clear,report'],
            'review_notes'  => ['required_if:action,clear,report', 'string'],
            'frc_reference' => ['required_if:action,report', 'string'],
        ]);

        $flag     = AmlFlag::findOrFail($id);
        $reviewer = $request->user();

        match ($request->action) {
            'claim'  => $flag->claimForReview($reviewer),
            'clear'  => $flag->clear($reviewer, $request->review_notes),
            'report' => $flag->reportToFrc($reviewer, $request->frc_reference, $request->review_notes),
        };

        return response()->json([
            'message' => 'AML flag updated.',
            'flag'    => $flag->fresh(),
        ]);
    }

    // =========================================================================
    // USER MANAGEMENT
    // =========================================================================

    public function listUsers(Request $request): JsonResponse
    {
        $query = User::with('wallet')
            ->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('kyc_level')) {
            $query->where('kyc_level', $request->query('kyc_level'));
        }

        return response()->json($query->paginate(50));
    }

    public function suspendUser(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'reason' => ['required', 'string'],
        ]);

        $user = User::findOrFail($id);
        $user->update(['status' => 'suspended']);

        AuditLog::record('user.suspended', $user, auth()->id(), [
            'reason' => $request->reason,
        ]);

        return response()->json(['message' => "{$user->name} has been suspended."]);
    }

    public function freezeWallet(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'reason' => ['required', 'string'],
        ]);

        $wallet = Wallet::findOrFail($id);
        $this->walletService->freeze($wallet, $request->reason);

        AuditLog::record('wallet.frozen', $wallet, auth()->id(), [
            'reason'       => $request->reason,
            'wallet_owner' => $wallet->walletable_type . ':' . $wallet->walletable_id,
        ]);

        return response()->json(['message' => 'Wallet frozen.']);
    }

    public function unfreezeWallet(string $id): JsonResponse
    {
        $wallet = Wallet::findOrFail($id);
        $this->walletService->unfreeze($wallet);

        AuditLog::record('wallet.unfrozen', $wallet, auth()->id());

        return response()->json(['message' => 'Wallet unfrozen.']);
    }
}
