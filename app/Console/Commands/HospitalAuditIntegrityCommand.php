<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HospitalAuditIntegrityCommand extends Command
{
    protected $signature = 'hospital:audit-integrity
        {--json : Output JSON report}
        {--repair : Soft-delete orphan bill items and recalculate mismatched bills}';

    protected $description = 'Audit hospital workflow integrity and optionally repair safe billing inconsistencies.';

    public function handle(): int
    {
        $report = [
            'generated_at' => now()->toISOString(),
            'orphan_prescriptions' => $this->orphans('hospital_prescriptions'),
            'orphan_orders' => $this->orphans('hospital_orders'),
            'orphan_tasks' => $this->orphans('hospital_tasks'),
            'orphan_lab_reports' => $this->orphans('lab_reports'),
            'orphan_radiology_reports' => $this->orphans('radiology_reports'),
            'orphan_bills' => $this->orphans('hospital_bills'),
            'orphan_bill_items' => $this->billItemOrphans(),
            'duplicate_tokens' => $this->duplicates('patients', ['license_uuid', 'token_number']),
            'duplicate_active_bills' => $this->duplicateActiveBills(),
            'billing_mismatches' => $this->billingMismatches(),
        ];

        $repairReport = null;
        if ($this->option('repair')) {
            $repairReport = $this->repair($report);
            $report['repair'] = $repairReport;
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->info('Hospital Integrity Audit');
        foreach ($report as $key => $value) {
            if ($key === 'generated_at') {
                $this->line("Generated At: {$value}");
                continue;
            }
            $count = is_countable($value) ? count($value) : 0;
            $this->line(str_replace('_', ' ', ucfirst($key)) . ": {$count}");
            if ($count > 0) {
                $this->table(array_keys((array) $value[0]), array_map(fn ($row) => (array) $row, $value));
            }
        }

        if ($repairReport) {
            $this->info('Repair Summary');
            foreach ($repairReport as $key => $value) {
                $this->line(str_replace('_', ' ', ucfirst($key)) . ": {$value}");
            }
        }

        return self::SUCCESS;
    }

    private function orphans(string $table): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        return DB::table($table)
            ->leftJoin('patients', "{$table}.patient_uuid", '=', 'patients.uuid')
            ->whereNull('patients.uuid')
            ->whereNull("{$table}.deleted_at")
            ->select("{$table}.uuid", "{$table}.license_uuid", "{$table}.patient_uuid", "{$table}.token_number")
            ->limit(200)
            ->get()
            ->all();
    }

    private function billItemOrphans(): array
    {
        if (! Schema::hasTable('hospital_bill_items')) {
            return [];
        }

        return DB::table('hospital_bill_items')
            ->leftJoin('hospital_bills', 'hospital_bill_items.bill_uuid', '=', 'hospital_bills.uuid')
            ->whereNull('hospital_bills.uuid')
            ->whereNull('hospital_bill_items.deleted_at')
            ->select('hospital_bill_items.uuid', 'hospital_bill_items.license_uuid', 'hospital_bill_items.bill_uuid', 'hospital_bill_items.patient_uuid')
            ->limit(200)
            ->get()
            ->all();
    }

    private function duplicates(string $table, array $columns): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        return DB::table($table)
            ->select(array_merge($columns, [DB::raw('COUNT(*) as total')]))
            ->whereNull('deleted_at')
            ->whereNotNull($columns[1])
            ->groupBy($columns)
            ->havingRaw('COUNT(*) > 1')
            ->limit(200)
            ->get()
            ->all();
    }

    private function duplicateActiveBills(): array
    {
        if (! Schema::hasTable('hospital_bills')) {
            return [];
        }

        return DB::table('hospital_bills')
            ->select('license_uuid', 'patient_uuid', DB::raw('COUNT(*) as total'))
            ->whereNull('deleted_at')
            ->whereNotIn('status', ['Closed', 'Cancelled'])
            ->groupBy('license_uuid', 'patient_uuid')
            ->havingRaw('COUNT(*) > 1')
            ->limit(200)
            ->get()
            ->all();
    }

    private function billingMismatches(): array
    {
        if (! Schema::hasTable('hospital_bills') || ! Schema::hasTable('hospital_bill_items')) {
            return [];
        }

        return DB::table('hospital_bills')
            ->leftJoin('hospital_bill_items', 'hospital_bills.uuid', '=', 'hospital_bill_items.bill_uuid')
            ->whereNull('hospital_bills.deleted_at')
            ->select(
                'hospital_bills.uuid',
                'hospital_bills.license_uuid',
                'hospital_bills.patient_uuid',
                'hospital_bills.grand_total',
                DB::raw('COALESCE(SUM(hospital_bill_items.amount),0) as item_total')
            )
            ->groupBy('hospital_bills.uuid', 'hospital_bills.license_uuid', 'hospital_bills.patient_uuid', 'hospital_bills.grand_total')
            ->havingRaw('ABS(hospital_bills.grand_total - COALESCE(SUM(hospital_bill_items.amount),0)) > 0.01')
            ->limit(200)
            ->get()
            ->all();
    }

    private function repair(array $report): array
    {
        return DB::transaction(function () use ($report) {
            $orphanBillItemUuids = collect($report['orphan_bill_items'] ?? [])
                ->pluck('uuid')
                ->filter()
                ->values();

            $orphanBillItemsRepaired = 0;
            if ($orphanBillItemUuids->isNotEmpty()) {
                $orphanBillItemsRepaired = DB::table('hospital_bill_items')
                    ->whereIn('uuid', $orphanBillItemUuids)
                    ->whereNull('deleted_at')
                    ->update([
                        'deleted_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            $billingMismatchesRepaired = 0;
            foreach ($report['billing_mismatches'] ?? [] as $mismatch) {
                $billUuid = $mismatch->uuid ?? null;
                if (! $billUuid) {
                    continue;
                }

                $lines = DB::table('hospital_bill_items')
                    ->where('bill_uuid', $billUuid)
                    ->whereNull('deleted_at')
                    ->select('item_type', 'amount')
                    ->get();

                $totals = $this->totals($lines);
                $paid = (float) DB::table('hospital_bills')
                    ->where('uuid', $billUuid)
                    ->value('paid');
                $balance = max(0, $totals['grand_total'] - $paid);

                $billingMismatchesRepaired += DB::table('hospital_bills')
                    ->where('uuid', $billUuid)
                    ->whereNull('deleted_at')
                    ->update(array_merge($totals, [
                        'balance' => $balance,
                        'status' => $balance <= 0 ? 'Paid' : 'Pending',
                        'updated_at' => now(),
                    ]));
            }

            return [
                'orphan_bill_items_repaired' => $orphanBillItemsRepaired,
                'billing_mismatches_repaired' => $billingMismatchesRepaired,
            ];
        });
    }

    private function totals($items): array
    {
        $items = collect($items);
        $sumType = fn (string $type) => $items
            ->where('item_type', $type)
            ->sum(fn ($item) => (float) ($item->amount ?? 0));
        $procedure = $items
            ->reject(fn ($item) => in_array($item->item_type ?? '', ['Doctor Fee', 'Medicine', 'Injection', 'Lab', 'Radiology'], true))
            ->sum(fn ($item) => (float) ($item->amount ?? 0));

        return [
            'doctor_fee' => $sumType('Doctor Fee'),
            'medicine_charges' => $sumType('Medicine'),
            'injection_charges' => $sumType('Injection'),
            'lab_charges' => $sumType('Lab'),
            'radiology_charges' => $sumType('Radiology'),
            'procedure_charges' => $procedure,
            'grand_total' => $items->sum(fn ($item) => (float) ($item->amount ?? 0)),
        ];
    }
}
