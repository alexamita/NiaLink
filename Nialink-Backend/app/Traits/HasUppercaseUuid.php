<?php

namespace App\Traits;

use Illuminate\Support\Str;

trait HasUppercaseUuid
{
    public function initializeHasUppercaseUuid(): void
    {
        $this->keyType    = 'string';
        $this->incrementing = false;
    }

    public static function bootHasUppercaseUuid(): void
    {
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = strtoupper(Str::uuid()->toString());
            }
        });
    }

    /**
     * Always return the ID in uppercase regardless of how PostgreSQL stored it.
     */
    public function getIdAttribute($value): ?string
    {
        return $value ? strtoupper($value) : null;
    }
}
