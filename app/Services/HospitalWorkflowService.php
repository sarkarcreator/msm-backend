<?php

namespace App\Services;

use App\Models\HospitalBill;
use App\Models\HospitalBillItem;
use App\Models\HospitalOrder;
use App\Models\HospitalPrescription;
use App\Models\HospitalTask;
use App\Models\LabReport;
use App\Models\Notification;
use App\Models\Patient;
use App\Models\Product;
use App\Models\RadiologyReport;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HospitalWorkflowService
{
    private array $statusFlow = [
        'Waiting' => ['Doctor Checked'],
        'Doctor Checked' => ['Sent To Reception'],
        'Sent To Reception' => ['Under Treatment'],
        'Under Treatment' => ['Treatment Completed'],
        'Treatment Completed' => ['Closed'],
        'Closed' => [],
    ];

    public function createVisit(array $payload, User $actor): array
    {
        $this->assertHospitalTenant($actor);

        return DB::transaction(function () use ($payload, $actor) {
            $wasExisting = ! empty($payload['uuid']) && Patient::where('license_uuid', $actor->license_uuid)
                ->where('uuid', $payload['uuid'])
                ->exists();
            $patient = $this->createPatient($payload, $actor);
            if ($wasExisting) {
                $this->clearWorkflow($patient);
            }
            $billItems = [];

            foreach ($this->structuredList($payload['prescription_items'] ?? $payload['medicine'] ?? []) as $item) {
                $prescription = $this->createPrescription($patient, $item, $actor);
                $billItems[] = [
                    'item_type' => 'Medicine',
                    'description' => $prescription->medicine_name,
                    'quantity' => 1,
                    'amount' => (float) ($item['amount'] ?? 0),
                ];
            }

            foreach ($this->structuredList($payload['doctor_orders'] ?? []) as $item) {
                $order = $this->createOrder($patient, $item, $actor);
                $this->createTaskForOrder($patient, $order, $actor);
                $billItems[] = [
                    'item_type' => $order->order_type,
                    'description' => $order->order_name,
                    'quantity' => 1,
                    'amount' => (float) $order->charges,
                ];
            }

            $fee = (float) ($patient->registration_fee ?: $patient->fee ?: 0);
            if ($fee > 0) {
                array_unshift($billItems, [
                    'item_type' => 'Doctor Fee',
                    'description' => 'Consultation / Registration Fee',
                    'quantity' => 1,
                    'amount' => $fee,
                ]);
            }

            $bill = $this->createBill($patient, $billItems, $actor);

            return [
                'patient' => $patient->fresh(),
                'prescriptions' => HospitalPrescription::where('patient_uuid', $patient->uuid)->get(),
                'orders' => HospitalOrder::where('patient_uuid', $patient->uuid)->get(),
                'tasks' => HospitalTask::where('patient_uuid', $patient->uuid)->get(),
                'lab_reports' => LabReport::where('patient_uuid', $patient->uuid)->get(),
                'radiology_reports' => RadiologyReport::where('patient_uuid', $patient->uuid)->get(),
                'bill' => $bill?->fresh(),
                'bill_items' => $bill ? HospitalBillItem::where('bill_uuid', $bill->uuid)->get() : [],
            ];
        }, 3);
    }

    public function transitionPatient(Patient $patient, string $nextStatus, User $actor): Patient
    {
        $this->assertHospitalTenant($actor);
        $this->assertTenantRecord($patient, $actor);

        $current = $patient->status ?: 'Waiting';
        if ($current === $nextStatus) {
            return $patient;
        }

        if (! in_array($nextStatus, $this->statusFlow[$current] ?? [], true)) {
            throw ValidationException::withMessages([
                'status' => "Invalid status transition from {$current} to {$nextStatus}.",
            ]);
        }

        $patient->update([
            'status' => $nextStatus,
            'revision' => ((int) ($patient->revision ?? 1)) + 1,
        ]);

        return $patient->fresh();
    }

    public function recalculateBill(HospitalBill $bill, User $actor, ?float $paid = null): HospitalBill
    {
        $this->assertHospitalTenant($actor);
        $this->assertTenantRecord($bill, $actor);

        return DB::transaction(function () use ($bill, $paid) {
            $lines = HospitalBillItem::where('bill_uuid', $bill->uuid)->get();
            $totals = $this->totals($lines->map(fn ($line) => [
                'item_type' => $line->item_type,
                'amount' => (float) $line->amount,
            ])->all());
            $paidAmount = $paid ?? (float) $bill->paid;

            $bill->update(array_merge($totals, [
                'paid' => $paidAmount,
                'paid_amount' => $paidAmount,
                'balance' => max(0, $totals['grand_total'] - $paidAmount),
                'status' => max(0, $totals['grand_total'] - $paidAmount) <= 0 ? 'Paid' : 'Pending',
                'revision' => ((int) ($bill->revision ?? 1)) + 1,
            ]));

            return $bill->fresh();
        }, 3);
    }

    public function saveBillPayment(HospitalBill $bill, User $actor, array $payload): array
    {
        $this->assertHospitalTenant($actor);
        $this->assertTenantRecord($bill, $actor);

        return DB::transaction(function () use ($bill, $actor, $payload) {
            $bill = HospitalBill::where('uuid', $bill->uuid)->lockForUpdate()->firstOrFail();
            $lines = HospitalBillItem::where('bill_uuid', $bill->uuid)->lockForUpdate()->get();
            $totals = $this->totals($lines->map(fn ($line) => [
                'item_type' => $line->item_type,
                'amount' => (float) $line->amount,
            ])->all());
            $paidAmount = min(max(0, (float) ($payload['paid_amount'] ?? $payload['paid'] ?? $totals['grand_total'])), $totals['grand_total']);
            $balance = max(0, $totals['grand_total'] - $paidAmount);
            $now = now();

            $bill->update(array_merge($totals, [
                'paid' => $paidAmount,
                'paid_amount' => $paidAmount,
                'payment_method' => $payload['payment_method'] ?? 'Cash',
                'received_by' => $actor->name,
                'received_at' => $now,
                'completion_time' => $balance <= 0 ? $now : null,
                'balance' => $balance,
                'status' => $balance <= 0 ? 'Paid' : 'Pending',
                'revision' => ((int) ($bill->revision ?? 1)) + 1,
            ]));

            $patient = Patient::where('license_uuid', $actor->license_uuid)
                ->where('uuid', $bill->patient_uuid)
                ->lockForUpdate()
                ->first();

            if ($patient && $balance <= 0) {
                $patient->update([
                    'status' => 'Closed',
                    'revision' => ((int) ($patient->revision ?? 1)) + 1,
                ]);
                $this->notify($actor, 'Patient fully treated. Payment received.', $patient, [
                    'token_number' => $patient->token_number,
                    'patient_name' => $patient->patient_name,
                    'doctor_name' => $patient->doctor_name,
                    'grand_total' => $totals['grand_total'],
                    'paid_amount' => $paidAmount,
                    'payment_method' => $payload['payment_method'] ?? 'Cash',
                    'completion_time' => $now->toISOString(),
                ]);
                $this->audit($actor, 'case_closed', 'patients', $patient->uuid, $patient->fresh()->toArray());
            }

            $this->audit($actor, 'bill_paid', 'hospital_bills', $bill->uuid, $bill->fresh()->toArray());

            return [
                'bill' => $bill->fresh(),
                'patient' => $patient?->fresh(),
                'notifications' => Notification::where('license_uuid', $actor->license_uuid)->latest('updated_at')->limit(10)->get(),
            ];
        }, 3);
    }

    public function completePrescription(HospitalPrescription $prescription, User $actor): HospitalPrescription
    {
        $this->assertHospitalTenant($actor);
        $this->assertTenantRecord($prescription, $actor);

        return DB::transaction(function () use ($prescription, $actor) {
            if ($prescription->status === 'Completed') {
                return $prescription;
            }

            $product = Product::where('license_uuid', $prescription->license_uuid)
                ->where(function ($query) use ($prescription) {
                    $query->where('uuid', $prescription->medicine_uuid)
                        ->orWhere('product_name', $prescription->medicine_name);
                })
                ->lockForUpdate()
                ->first();

            if ($product) {
                $quantity = max(1, (int) $prescription->days);
                $product->update([
                    'quantity' => max(0, (int) $product->quantity - $quantity),
                    'revision' => ((int) ($product->revision ?? 1)) + 1,
                ]);
            }

            $prescription->update([
                'status' => 'Completed',
                'dispensed_at' => now(),
                'dispensed_by' => $actor->name,
                'dispensed_quantity' => max(1, (int) $prescription->days),
                'revision' => ((int) ($prescription->revision ?? 1)) + 1,
            ]);

            $bill = HospitalBill::where('license_uuid', $prescription->license_uuid)
                ->where('patient_uuid', $prescription->patient_uuid)
                ->whereNotIn('status', ['Closed', 'Cancelled'])
                ->first();
            if ($bill) {
                $this->recalculateBill($bill, $actor);
            }

            $this->audit($actor, 'medicine_dispensed', 'hospital_prescriptions', $prescription->uuid, $prescription->fresh()->toArray());

            return $prescription->fresh();
        }, 3);
    }

    public function completeLabReport(LabReport $report, User $actor, array $payload = []): LabReport
    {
        $this->assertHospitalTenant($actor);
        $this->assertTenantRecord($report, $actor);

        $report->update([
            'status' => 'Completed',
            'doctor_review_status' => 'Pending Review',
            'result' => $payload['result'] ?? $report->result,
            'remarks' => $payload['remarks'] ?? $report->remarks,
            'technician_name' => $payload['technician_name'] ?? $actor->name,
            'attachment_url' => $payload['attachment_url'] ?? $payload['file_url'] ?? $report->attachment_url ?? $report->file_url,
            'completed_at' => now(),
            'revision' => ((int) ($report->revision ?? 1)) + 1,
        ]);

        $this->audit($actor, 'lab_completed', 'lab_reports', $report->uuid, $report->fresh()->toArray());

        return $report->fresh();
    }

    public function completeRadiologyReport(RadiologyReport $report, User $actor, array $payload = []): RadiologyReport
    {
        $this->assertHospitalTenant($actor);
        $this->assertTenantRecord($report, $actor);

        $report->update([
            'status' => 'Completed',
            'doctor_review_status' => 'Pending Review',
            'report_text' => $payload['report_text'] ?? $payload['report'] ?? $report->report_text ?? $report->report,
            'findings' => $payload['findings'] ?? $report->findings,
            'impression' => $payload['impression'] ?? $report->impression,
            'radiologist_name' => $payload['radiologist_name'] ?? $actor->name,
            'attachment_url' => $payload['attachment_url'] ?? $payload['report_url'] ?? $report->attachment_url ?? $report->report_url,
            'completed_at' => now(),
            'revision' => ((int) ($report->revision ?? 1)) + 1,
        ]);

        $this->audit($actor, 'radiology_completed', 'radiology_reports', $report->uuid, $report->fresh()->toArray());

        return $report->fresh();
    }

    public function reviewReport(string $type, string $uuid, User $actor, string $status): LabReport|RadiologyReport
    {
        $this->assertHospitalTenant($actor);

        if (! in_array($status, ['Reviewed', 'Need Repeat', 'Need Follow Up'], true)) {
            throw ValidationException::withMessages(['doctor_review_status' => 'Invalid review status.']);
        }

        $model = $type === 'radiology' ? RadiologyReport::class : LabReport::class;
        $report = $model::where('uuid', $uuid)->orWhere('id', $uuid)->firstOrFail();
        $this->assertTenantRecord($report, $actor);

        $report->update([
            'doctor_review_status' => $status,
            'status' => $status === 'Reviewed' ? 'Reviewed' : $report->status,
            'revision' => ((int) ($report->revision ?? 1)) + 1,
        ]);

        $this->audit($actor, "{$type}_reviewed", $type === 'radiology' ? 'radiology_reports' : 'lab_reports', $report->uuid, $report->fresh()->toArray());

        return $report->fresh();
    }

    private function createPatient(array $payload, User $actor): Patient
    {
        $visitDate = $payload['visit_date'] ?? now()->toDateString();
        $existing = ! empty($payload['uuid'])
            ? Patient::where('license_uuid', $actor->license_uuid)->where('uuid', $payload['uuid'])->first()
            : null;
        $data = [
            'uuid' => $payload['uuid'] ?? (string) Str::uuid(),
            'license_uuid' => $actor->license_uuid,
            'business_type' => $actor->business_type,
            'token_number' => $existing?->token_number ?: $this->nextToken($actor->license_uuid, $visitDate),
            'mr_number' => $payload['mr_number'] ?? $existing?->mr_number ?? $this->nextMrNumber($actor->license_uuid),
            'patient_name' => $payload['patient_name'] ?? $payload['name'] ?? null,
            'guardian_name' => $payload['guardian_name'] ?? null,
            'phone' => $payload['phone'] ?? null,
            'cnic' => $payload['cnic'] ?? null,
            'gender' => $payload['gender'] ?? null,
            'age' => (int) ($payload['age'] ?? 0),
            'address' => $payload['address'] ?? null,
            'emergency_contact' => $payload['emergency_contact'] ?? null,
            'blood_group' => $payload['blood_group'] ?? null,
            'visit_type' => $payload['visit_type'] ?? 'OPD',
            'department' => $payload['department'] ?? null,
            'doctor_name' => $payload['doctor_name'] ?? $actor->name,
            'assistant_name' => $payload['assistant_name'] ?? null,
            'symptoms' => $payload['symptoms'] ?? null,
            'diagnosis' => $payload['diagnosis'] ?? null,
            'clinical_notes' => $payload['clinical_notes'] ?? null,
            'vitals' => $payload['vitals'] ?? null,
            'blood_pressure' => $payload['blood_pressure'] ?? null,
            'sugar_level' => $payload['sugar_level'] ?? null,
            'temperature' => $payload['temperature'] ?? null,
            'weight' => $payload['weight'] ?? null,
            'registration_fee' => (float) ($payload['registration_fee'] ?? $payload['fee'] ?? 0),
            'fee' => (float) ($payload['fee'] ?? $payload['registration_fee'] ?? 0),
            'status' => 'Sent To Reception',
            'visit_date' => $visitDate,
            'next_visit' => $payload['next_visit'] ?? null,
            'notes' => $payload['notes'] ?? null,
            'medicine_days' => (int) ($payload['medicine_days'] ?? 0),
            'revision' => ((int) ($existing?->revision ?? 0)) + 1,
        ];

        if ($existing) {
            $existing->update($data);
            return $existing->fresh();
        }

        return Patient::create($data);
    }

    private function createPrescription(Patient $patient, array $item, User $actor): HospitalPrescription
    {
        return HospitalPrescription::create([
            'uuid' => $item['uuid'] ?? (string) Str::uuid(),
            'license_uuid' => $actor->license_uuid,
            'business_type' => $actor->business_type,
            'patient_uuid' => $patient->uuid,
            'token_number' => $patient->token_number,
            'patient_name' => $patient->patient_name,
            'doctor_name' => $patient->doctor_name,
            'medicine_uuid' => $item['medicine_uuid'] ?? null,
            'medicine_name' => $item['medicine'] ?? $item['medicine_name'] ?? $item['name'] ?? 'Medicine',
            'morning' => (int) ($item['morning'] ?? 0),
            'afternoon' => (int) ($item['afternoon'] ?? 0),
            'evening' => (int) ($item['evening'] ?? 0),
            'night' => (int) ($item['night'] ?? 0),
            'days' => (int) ($item['days'] ?? 1),
            'status' => 'Pending',
        ]);
    }

    private function createOrder(Patient $patient, array $item, User $actor): HospitalOrder
    {
        $name = $item['order_name'] ?? $item['name'] ?? $item['type'] ?? 'Procedure';
        $type = $this->orderType($item['order_type'] ?? $name);

        return HospitalOrder::create([
            'uuid' => $item['uuid'] ?? (string) Str::uuid(),
            'license_uuid' => $actor->license_uuid,
            'business_type' => $actor->business_type,
            'patient_uuid' => $patient->uuid,
            'token_number' => $patient->token_number,
            'patient_name' => $patient->patient_name,
            'doctor_name' => $patient->doctor_name,
            'order_type' => $type,
            'order_name' => $name,
            'charges' => (float) ($item['charges'] ?? $this->defaultOrderCharge($type)),
            'status' => 'Pending',
            'doctor_review_status' => 'Not Required',
            'notes' => $item['notes'] ?? null,
        ]);
    }

    private function createTaskForOrder(Patient $patient, HospitalOrder $order, User $actor): void
    {
        $base = [
            'uuid' => (string) Str::uuid(),
            'license_uuid' => $actor->license_uuid,
            'business_type' => $actor->business_type,
            'patient_uuid' => $patient->uuid,
            'token_number' => $patient->token_number,
            'order_uuid' => $order->uuid,
            'patient_name' => $patient->patient_name,
            'status' => 'Pending',
            'doctor_review_status' => in_array($order->order_type, ['Lab', 'Radiology'], true) ? 'Pending Result' : 'Not Required',
        ];

        if ($order->order_type === 'Lab') {
            LabReport::create(array_merge($base, ['test_name' => $order->order_name]));
            return;
        }

        if ($order->order_type === 'Radiology') {
            RadiologyReport::create(array_merge($base, ['study_type' => $order->order_name]));
            return;
        }

        HospitalTask::create(array_merge($base, [
            'task_type' => $order->order_type,
            'task_name' => $order->order_name,
            'quantity' => 1,
            'assigned_role' => $this->taskRole($order->order_type),
        ]));
    }

    private function createBill(Patient $patient, array $items, User $actor): ?HospitalBill
    {
        $existing = HospitalBill::where('license_uuid', $actor->license_uuid)
            ->where('patient_uuid', $patient->uuid)
            ->whereNotIn('status', ['Closed', 'Cancelled'])
            ->first();

        if ($existing) {
            throw ValidationException::withMessages(['patient_uuid' => 'This patient already has an active bill.']);
        }

        if (! $items) {
            return null;
        }

        $totals = $this->totals($items);
        $bill = HospitalBill::create(array_merge($totals, [
            'uuid' => (string) Str::uuid(),
            'license_uuid' => $actor->license_uuid,
            'business_type' => $actor->business_type,
            'patient_uuid' => $patient->uuid,
            'token_number' => $patient->token_number,
            'bill_number' => 'HBL-' . $patient->token_number,
            'active_bill_key' => $actor->license_uuid . ':' . $patient->uuid,
            'patient_name' => $patient->patient_name,
            'paid' => 0,
            'balance' => $totals['grand_total'],
            'status' => 'Pending',
        ]));

        foreach ($items as $item) {
            HospitalBillItem::create([
                'uuid' => (string) Str::uuid(),
                'license_uuid' => $actor->license_uuid,
                'business_type' => $actor->business_type,
                'bill_uuid' => $bill->uuid,
                'patient_uuid' => $patient->uuid,
                'token_number' => $patient->token_number,
                'item_type' => $item['item_type'],
                'description' => $item['description'],
                'quantity' => (int) ($item['quantity'] ?? 1),
                'amount' => (float) ($item['amount'] ?? 0),
            ]);
        }

        return $bill;
    }

    private function nextToken(string $licenseUuid, string $visitDate): string
    {
        $date = \Carbon\Carbon::parse($visitDate)->format('ymd');
        DB::select('SELECT GET_LOCK(?, 10) AS acquired', ["hospital-token-{$licenseUuid}-{$date}"]);

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
            DB::select('SELECT RELEASE_LOCK(?)', ["hospital-token-{$licenseUuid}-{$date}"]);
        }
    }

    private function nextMrNumber(string $licenseUuid): string
    {
        $count = Patient::withTrashed()->where('license_uuid', $licenseUuid)->lockForUpdate()->count() + 1;
        return 'MR-' . str_pad((string) $count, 5, '0', STR_PAD_LEFT);
    }

    private function totals(array $items): array
    {
        $sumType = fn (string $type) => collect($items)->where('item_type', $type)->sum(fn ($item) => (float) ($item['amount'] ?? 0));
        $procedure = collect($items)
            ->reject(fn ($item) => in_array($item['item_type'], ['Doctor Fee', 'Medicine', 'Injection', 'Lab', 'Radiology'], true))
            ->sum(fn ($item) => (float) ($item['amount'] ?? 0));

        return [
            'doctor_fee' => $sumType('Doctor Fee'),
            'medicine_charges' => $sumType('Medicine'),
            'injection_charges' => $sumType('Injection'),
            'lab_charges' => $sumType('Lab'),
            'radiology_charges' => $sumType('Radiology'),
            'procedure_charges' => $procedure,
            'grand_total' => collect($items)->sum(fn ($item) => (float) ($item['amount'] ?? 0)),
        ];
    }

    private function structuredList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value));
        }

        if (! $value) {
            return [];
        }

        $decoded = json_decode((string) $value, true);
        if (is_array($decoded)) {
            return array_values(array_filter($decoded));
        }

        return collect(preg_split('/\r?\n/', (string) $value))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->map(fn ($name) => ['name' => $name, 'medicine' => $name, 'order_name' => $name])
            ->values()
            ->all();
    }

    private function orderType(string $value): string
    {
        $text = strtolower($value);
        if (str_contains($text, 'lab') || str_contains($text, 'cbc') || str_contains($text, 'blood') || str_contains($text, 'urine')) {
            return 'Lab';
        }
        if (str_contains($text, 'x-ray') || str_contains($text, 'xray') || str_contains($text, 'ultrasound') || str_contains($text, 'ct') || str_contains($text, 'mri')) {
            return 'Radiology';
        }
        if (str_contains($text, 'injection') || str_contains($text, 'iv') || str_contains($text, 'drip')) {
            return 'Injection';
        }
        if (str_contains($text, 'admission')) {
            return 'Admission';
        }
        return 'Procedure';
    }

    private function defaultOrderCharge(string $type): float
    {
        return ['Lab' => 800, 'Radiology' => 1500, 'Injection' => 300, 'Admission' => 0, 'Procedure' => 500][$type] ?? 0;
    }

    private function taskRole(string $type): string
    {
        return ['Lab' => 'Lab Technician', 'Radiology' => 'X-Ray Technician', 'Injection' => 'Nurse', 'Admission' => 'Receptionist'][$type] ?? 'Assistant';
    }

    private function clearWorkflow(Patient $patient): void
    {
        HospitalBill::where('patient_uuid', $patient->uuid)->update(['active_bill_key' => null]);
        foreach ([
            HospitalPrescription::class,
            HospitalOrder::class,
            HospitalTask::class,
            LabReport::class,
            RadiologyReport::class,
            HospitalBill::class,
            HospitalBillItem::class,
        ] as $model) {
            $model::where('patient_uuid', $patient->uuid)->delete();
        }
    }

    private function assertHospitalTenant(User $actor): void
    {
        if (! $actor->license_uuid || $actor->business_type !== 'Hospital') {
            throw ValidationException::withMessages(['license_uuid' => 'Hospital tenant scope is required.']);
        }
    }

    private function assertTenantRecord($record, User $actor): void
    {
        if (($record->license_uuid ?? null) !== $actor->license_uuid) {
            throw ValidationException::withMessages(['license_uuid' => 'This record does not belong to the active tenant.']);
        }
    }

    private function notify(User $actor, string $message, Patient $patient, array $metadata): void
    {
        Notification::create([
            'uuid' => (string) Str::uuid(),
            'license_uuid' => $actor->license_uuid,
            'business_type' => $actor->business_type,
            'title' => 'Patient Completed',
            'message' => $message,
            'type' => 'Hospital',
            'status' => 'Active',
            'metadata' => $metadata,
        ]);
    }

    private function audit(User $actor, string $action, string $entity, string $uuid, array $payload): void
    {
        AuditLog::create([
            'uuid' => (string) Str::uuid(),
            'license_uuid' => $actor->license_uuid,
            'business_type' => $actor->business_type,
            'user_name' => $actor->name,
            'action' => $action,
            'entity' => $entity,
            'entity_uuid' => $uuid,
            'details' => "{$action} {$entity}",
            'metadata' => $payload,
        ]);
    }
}
