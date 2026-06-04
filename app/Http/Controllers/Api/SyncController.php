<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SyncQueue;
use App\Services\SyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class SyncController extends Controller
{
    public function push(Request $request, SyncService $sync)
    {
        $payload = $request->validate([
            'device_id' => ['required', 'string', 'max:120'],
            'operations' => ['required', 'array'],
            'operations.*.uuid' => ['required', 'string'],
            'operations.*.entity' => ['required', 'string'],
            'operations.*.action' => ['required', 'in:create,update,delete,force_delete'],
            'operations.*.data' => ['nullable', 'array'],
            'operations.*.client_updated_at' => ['nullable', 'date'],
        ]);

        return ['results' => $sync->apply($payload['device_id'], $payload['operations'], $request->user())];
    }

    public function pull(Request $request)
    {
        $payload = $request->validate([
            'since' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $query = SyncQueue::where('created_at', '>', $payload['since'] ?? now()->subYear())
            ->oldest();

        $user = $request->user();
        $role = optional($user?->role)->name;

        if ($role !== 'Super Admin') {
            abort_unless($user?->license_uuid, 403, 'Tenant scope is required.');

            if (Schema::hasColumn('sync_queue', 'license_uuid')) {
                $query->where('license_uuid', $user->license_uuid);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        return [
            'server_time' => now()->toISOString(),
            'operations' => $query->limit((int) ($payload['limit'] ?? 1000))->get(),
        ];
    }
}
