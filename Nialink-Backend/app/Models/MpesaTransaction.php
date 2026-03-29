<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use App\Traits\HasUppercaseUuid;

class MpesaTransaction extends Model
{
    use HasFactory, HasUppercaseUuid;

    protected $fillable = [
        'user_id',
        'transaction_id',
        'type',
        'phone_number',
        'amount',
        'currency',
        'checkout_request_id',
        'mpesa_receipt',
        'status',
        'raw_callback',
        'result_code',
        'result_description',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            // raw_callback is stored as JSON — cast to array for easy access
            // in the ProcessMpesaTopUpCallback job without manual json_decode.
            'raw_callback' => 'array',
            'amount'       => 'decimal:2',
            'completed_at' => 'datetime',
            'created_at'   => 'datetime',
            'updated_at'   => 'datetime',
        ];
    }

    // =========
    // BOOT
    // =========

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (MpesaTransaction $mpesa) {
            // UUID is handled by HasUppercaseUuid trait.

            if (empty($mpesa->currency)) {
                $mpesa->currency = 'KES';
            }
        });
    }

    // ================
    // RELATIONSHIPS
    // ================

    /**
     * The NiaLink user this M-Pesa interaction belongs to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The internal transaction created after a successful callback.
     * Null while status = pending — set by ProcessMpesaTopUpCallback job.
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    // ==========
    // HELPERS
    // ==========
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isTimeout(): bool
    {
        return $this->status === 'timeout';
    }

    /**
     * Was this a successful M-Pesa callback?
     * Daraja uses result_code = '0' for success.
     * All other values indicate failure.
     */
    public function isSuccessfulCallback(): bool
    {
        return $this->result_code === '0';
    }

    /**
     * Extract a specific value from the Safaricom callback metadata array.
     * Safaricom returns callback metadata as: [['Name' => 'Amount', 'Value' => 500], ...]
     *
     * Usage:
     *   $mpesa->callbackValue('Amount')        → 500.00
     *   $mpesa->callbackValue('MpesaReceiptNumber') → 'NLJ7RT61SV'
     *   $mpesa->callbackValue('PhoneNumber')   → '254712345678'
     */
    public function callbackValue(string $key): mixed
    {
        if (! $this->raw_callback) {
            return null;
        }

        $items = data_get($this->raw_callback, 'Body.stkCallback.CallbackMetadata.Item', []);

        $item = collect($items)->firstWhere('Name', $key);

        return $item['Value'] ?? null;
    }

    /**
     * Mark this M-Pesa transaction as completed from a successful callback.
     * Called by ProcessMpesaTopUpCallback after wallet is credited.
     */
    public function markCompleted(string $mpesaReceipt, array $rawCallback): void
    {
        $this->update([
            'status'             => 'completed',
            'mpesa_receipt'      => $mpesaReceipt,
            'result_code'        => '0',
            'result_description' => 'The service request is processed successfully.',
            'raw_callback'       => $rawCallback,
            'completed_at'       => now(),
        ]);
    }

    /**
     * Mark this M-Pesa transaction as failed from a callback.
     * Called by ProcessMpesaTopUpCallback when ResultCode != 0.
     */
    public function markFailed(string $resultCode, string $resultDescription, array $rawCallback): void
    {
        $this->update([
            'status'             => 'failed',
            'result_code'        => $resultCode,
            'result_description' => $resultDescription,
            'raw_callback'       => $rawCallback,
        ]);
    }
}
