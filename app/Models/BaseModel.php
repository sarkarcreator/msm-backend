<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

abstract class BaseModel extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'metadata' => 'array',
        'payload' => 'array',
        'imei_numbers' => 'array',
        'sold_at' => 'datetime',
        'purchased_at' => 'datetime',
        'spent_at' => 'datetime',
        'due_date' => 'date',
    ];

    /**
     * Apply the authenticated tenant scope to every business-owned model.
     * ResourceController already scopes its generic API; this model-level
     * guard closes gaps in services/controllers that query models directly.
     * Super Admin remains outside tenant scope by design.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function ($builder) {
            $user = Auth::user();
            $model = $builder->getModel();
            $table = $model->getTable();

            if (! $user || ($user->role?->name ?? null) === 'Super Admin') {
                return;
            }

            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'license_uuid')) {
                return;
            }

            // A tenant-scoped table must never fall back to NULL/unscoped rows.
            // ResourceController separately rejects tenant API access without a
            // license; this guard also protects direct model queries.
            $builder->where($model->qualifyColumn('license_uuid'), (string) ($user->license_uuid ?? ''));
        });

        static::creating(function (Model $model) {
            $user = Auth::user();
            if (! $user || ($user->role?->name ?? null) === 'Super Admin') {
                return;
            }

            $table = $model->getTable();
            if (! Schema::hasTable($table)) {
                return;
            }

            if (Schema::hasColumn($table, 'license_uuid')) {
                $model->setAttribute('license_uuid', $user->license_uuid);
            }
            if (Schema::hasColumn($table, 'business_type') && $user->business_type) {
                $model->setAttribute('business_type', $user->business_type);
            }
        });

        static::updating(function (Model $model) {
            $user = Auth::user();
            if (! $user || ($user->role?->name ?? null) === 'Super Admin') {
                return;
            }

            $table = $model->getTable();
            if (! Schema::hasTable($table)) {
                return;
            }

            // Prevent a tenant user from moving an existing record into another
            // tenant by submitting a different license/business value.
            if (Schema::hasColumn($table, 'license_uuid')) {
                $model->setAttribute('license_uuid', $user->license_uuid);
            }
            if (Schema::hasColumn($table, 'business_type') && $user->business_type) {
                $model->setAttribute('business_type', $user->business_type);
            }
        });
    }
}
