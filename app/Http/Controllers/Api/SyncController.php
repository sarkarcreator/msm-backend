<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SyncQueue;
use App\Services\SyncService;
use Illuminate\Http\Request;

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
            'operations.*.client_updated_at' => ['required', 'date'],
        ]);

        return ['results' => $sync->apply($payload['device_id'], $payload['operations'], $request->user())];
    }

    public function pull(Request $request)
    {
        return [
            'server_time' => now()->toISOString(),
            'operations' => SyncQueue::where('created_at', '>', $request->query('since', now()->subYear()))
                ->oldest()
                ->limit(1000)
                ->get(),
        ];
    }
}
