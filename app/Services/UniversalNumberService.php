<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UniversalNumberService
{
    public function nextTenantSequence(string $modelClass, string $prefix, User $actor, int $pad = 5): string
    {
        $licenseUuid = (string) $actor->license_uuid;
        $lockName = $this->lockName($licenseUuid, $prefix);

        DB::select('SELECT GET_LOCK(?, 10) AS acquired', [$lockName]);

        try {
            $query = in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)
                ? $modelClass::withTrashed()
                : $modelClass::query();
            $count = $query->where('license_uuid', $licenseUuid)->count() + 1;

            return $prefix . '-' . str_pad((string) $count, $pad, '0', STR_PAD_LEFT);
        } finally {
            DB::select('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }

    public function nextHospitalToken(string $licenseUuid, string|\DateTimeInterface|null $visitDate = null): string
    {
        $date = Carbon::parse($visitDate ?: now())->format('ymd');
        $lockName = $this->lockName($licenseUuid, "hospital-token-{$date}");

        DB::select('SELECT GET_LOCK(?, 10) AS acquired', [$lockName]);

        try {
            $latest = Patient::withTrashed()
                ->where('license_uuid', $licenseUuid)
                ->where('token_number', 'like', "H-{$date}-%")
                ->lockForUpdate()
                ->orderByDesc('token_number')
                ->value('token_number');

            $next = $latest ? ((int) substr($latest, -4)) + 1 : 1;

            do {
                $token = 'H-' . $date . '-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
                $exists = Patient::withTrashed()
                    ->where('license_uuid', $licenseUuid)
                    ->where('token_number', $token)
                    ->exists();
                $next++;
            } while ($exists);

            return $token;
        } finally {
            DB::select('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }

    private function lockName(string $licenseUuid, string $purpose): string
    {
        return Str::limit("msm-number-{$licenseUuid}-{$purpose}", 64, '');
    }
}
