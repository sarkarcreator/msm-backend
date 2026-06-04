<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HospitalBill;
use App\Models\HospitalPrescription;
use App\Models\LabReport;
use App\Models\Patient;
use App\Services\HospitalWorkflowService;
use Illuminate\Http\Request;

class HospitalWorkflowController extends Controller
{
    public function store(Request $request, HospitalWorkflowService $workflow)
    {
        $payload = $request->validate([
            'uuid' => ['nullable', 'uuid'],
            'mr_number' => ['nullable', 'string', 'max:120'],
            'patient_name' => ['required', 'string', 'max:255'],
            'guardian_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:80'],
            'cnic' => ['nullable', 'string', 'max:80'],
            'gender' => ['nullable', 'string', 'max:40'],
            'age' => ['nullable', 'integer', 'min:0', 'max:130'],
            'address' => ['nullable', 'string'],
            'emergency_contact' => ['nullable', 'string', 'max:120'],
            'blood_group' => ['nullable', 'string', 'max:20'],
            'visit_type' => ['nullable', 'string', 'max:80'],
            'department' => ['nullable', 'string', 'max:120'],
            'doctor_name' => ['nullable', 'string', 'max:255'],
            'assistant_name' => ['nullable', 'string', 'max:255'],
            'symptoms' => ['nullable', 'string'],
            'diagnosis' => ['nullable', 'string'],
            'clinical_notes' => ['nullable', 'string'],
            'vitals' => ['nullable', 'string'],
            'blood_pressure' => ['nullable', 'string', 'max:80'],
            'sugar_level' => ['nullable', 'string', 'max:80'],
            'temperature' => ['nullable', 'string', 'max:80'],
            'weight' => ['nullable', 'string', 'max:80'],
            'registration_fee' => ['nullable', 'numeric', 'min:0'],
            'fee' => ['nullable', 'numeric', 'min:0'],
            'visit_date' => ['nullable', 'date'],
            'next_visit' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'medicine_days' => ['nullable', 'integer', 'min:0'],
            'prescription_items' => ['nullable'],
            'doctor_orders' => ['nullable'],
            'medicine' => ['nullable'],
        ]);

        return response($workflow->createVisit($payload, $request->user()), 201);
    }

    public function transitionPatient(Request $request, string $patient, HospitalWorkflowService $workflow)
    {
        $payload = $request->validate([
            'status' => ['required', 'string', 'in:Waiting,Doctor Checked,Sent To Reception,Under Treatment,Treatment Completed,Closed'],
        ]);

        $record = Patient::where('uuid', $patient)->orWhere('id', $patient)->firstOrFail();

        return $workflow->transitionPatient($record, $payload['status'], $request->user());
    }

    public function recalculateBill(Request $request, string $bill, HospitalWorkflowService $workflow)
    {
        $payload = $request->validate([
            'paid' => ['nullable', 'numeric', 'min:0'],
        ]);

        $record = HospitalBill::where('uuid', $bill)->orWhere('id', $bill)->firstOrFail();

        return $workflow->recalculateBill($record, $request->user(), $payload['paid'] ?? null);
    }

    public function completePrescription(Request $request, string $prescription, HospitalWorkflowService $workflow)
    {
        $record = HospitalPrescription::where('uuid', $prescription)->orWhere('id', $prescription)->firstOrFail();

        return $workflow->completePrescription($record, $request->user());
    }

    public function completeLabReport(Request $request, string $report, HospitalWorkflowService $workflow)
    {
        $record = LabReport::where('uuid', $report)->orWhere('id', $report)->firstOrFail();

        return $workflow->completeLabReport($record, $request->user());
    }
}
