<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('patients')) {
            Schema::table('patients', function (Blueprint $table) {
                foreach ([
                    'license_uuid', 'shop_id', 'token_number', 'mr_number', 'guardian_name',
                    'emergency_contact', 'blood_group', 'visit_type', 'department',
                    'blood_pressure', 'sugar_level', 'temperature', 'weight',
                ] as $column) {
                    if (! Schema::hasColumn('patients', $column)) {
                        $table->string($column)->nullable()->index();
                    }
                }
                foreach (['address', 'clinical_notes', 'vitals'] as $column) {
                    if (! Schema::hasColumn('patients', $column)) {
                        $table->text($column)->nullable();
                    }
                }
                if (! Schema::hasColumn('patients', 'registration_fee')) {
                    $table->decimal('registration_fee', 14, 2)->default(0);
                }
            });
        }

        $this->createHospitalPrescriptions();
        $this->createHospitalOrders();
        $this->createHospitalTasks();
        $this->createLabReports();
        $this->createRadiologyReports();
        $this->createHospitalBills();
        $this->createHospitalBillItems();
    }

    public function down(): void
    {
        Schema::dropIfExists('hospital_bill_items');
        Schema::dropIfExists('hospital_bills');
        Schema::dropIfExists('radiology_reports');
        Schema::dropIfExists('lab_reports');
        Schema::dropIfExists('hospital_tasks');
        Schema::dropIfExists('hospital_orders');
        Schema::dropIfExists('hospital_prescriptions');
    }

    private function createHospitalPrescriptions(): void
    {
        if (Schema::hasTable('hospital_prescriptions')) return;
        Schema::create('hospital_prescriptions', function (Blueprint $table) {
            $this->base($table);
            $table->uuid('license_uuid')->nullable()->index();
            $table->uuid('patient_uuid')->index();
            $table->string('patient_name')->nullable()->index();
            $table->string('doctor_name')->nullable()->index();
            $table->uuid('medicine_uuid')->nullable()->index();
            $table->string('medicine_name')->index();
            $table->unsignedTinyInteger('morning')->default(0);
            $table->unsignedTinyInteger('afternoon')->default(0);
            $table->unsignedTinyInteger('evening')->default(0);
            $table->unsignedTinyInteger('night')->default(0);
            $table->unsignedInteger('days')->default(1);
            $table->string('status')->default('Pending')->index();
        });
    }

    private function createHospitalOrders(): void
    {
        if (Schema::hasTable('hospital_orders')) return;
        Schema::create('hospital_orders', function (Blueprint $table) {
            $this->base($table);
            $table->uuid('license_uuid')->nullable()->index();
            $table->uuid('patient_uuid')->index();
            $table->string('patient_name')->nullable()->index();
            $table->string('doctor_name')->nullable()->index();
            $table->string('order_type')->index();
            $table->string('order_name')->index();
            $table->decimal('charges', 14, 2)->default(0);
            $table->string('status')->default('Pending')->index();
            $table->text('notes')->nullable();
        });
    }

    private function createHospitalTasks(): void
    {
        if (Schema::hasTable('hospital_tasks')) return;
        Schema::create('hospital_tasks', function (Blueprint $table) {
            $this->base($table);
            $table->uuid('license_uuid')->nullable()->index();
            $table->uuid('patient_uuid')->index();
            $table->uuid('order_uuid')->nullable()->index();
            $table->string('patient_name')->nullable()->index();
            $table->string('task_type')->index();
            $table->string('task_name')->index();
            $table->unsignedInteger('quantity')->default(1);
            $table->time('task_time')->nullable();
            $table->boolean('morning')->default(false);
            $table->boolean('afternoon')->default(false);
            $table->boolean('evening')->default(false);
            $table->boolean('night')->default(false);
            $table->string('assigned_role')->nullable()->index();
            $table->string('status')->default('Pending')->index();
        });
    }

    private function createLabReports(): void
    {
        if (Schema::hasTable('lab_reports')) return;
        Schema::create('lab_reports', function (Blueprint $table) {
            $this->base($table);
            $table->uuid('license_uuid')->nullable()->index();
            $table->uuid('patient_uuid')->index();
            $table->uuid('order_uuid')->nullable()->index();
            $table->string('patient_name')->nullable()->index();
            $table->string('test_name')->index();
            $table->text('result')->nullable();
            $table->string('file_url')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('Pending')->index();
        });
    }

    private function createRadiologyReports(): void
    {
        if (Schema::hasTable('radiology_reports')) return;
        Schema::create('radiology_reports', function (Blueprint $table) {
            $this->base($table);
            $table->uuid('license_uuid')->nullable()->index();
            $table->uuid('patient_uuid')->index();
            $table->uuid('order_uuid')->nullable()->index();
            $table->string('patient_name')->nullable()->index();
            $table->string('study_type')->index();
            $table->string('image_url')->nullable();
            $table->string('report_url')->nullable();
            $table->text('report')->nullable();
            $table->string('status')->default('Pending')->index();
        });
    }

    private function createHospitalBills(): void
    {
        if (Schema::hasTable('hospital_bills')) return;
        Schema::create('hospital_bills', function (Blueprint $table) {
            $this->base($table);
            $table->uuid('license_uuid')->nullable()->index();
            $table->uuid('patient_uuid')->index();
            $table->string('bill_number')->unique();
            $table->string('patient_name')->nullable()->index();
            $table->decimal('doctor_fee', 14, 2)->default(0);
            $table->decimal('medicine_charges', 14, 2)->default(0);
            $table->decimal('injection_charges', 14, 2)->default(0);
            $table->decimal('lab_charges', 14, 2)->default(0);
            $table->decimal('radiology_charges', 14, 2)->default(0);
            $table->decimal('procedure_charges', 14, 2)->default(0);
            $table->decimal('grand_total', 14, 2)->default(0);
            $table->decimal('paid', 14, 2)->default(0);
            $table->decimal('balance', 14, 2)->default(0);
            $table->string('status')->default('Pending')->index();
        });
    }

    private function createHospitalBillItems(): void
    {
        if (Schema::hasTable('hospital_bill_items')) return;
        Schema::create('hospital_bill_items', function (Blueprint $table) {
            $this->base($table);
            $table->uuid('license_uuid')->nullable()->index();
            $table->uuid('bill_uuid')->index();
            $table->uuid('patient_uuid')->index();
            $table->string('item_type')->index();
            $table->string('description');
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('amount', 14, 2)->default(0);
        });
    }

    private function base(Blueprint $table): void
    {
        $table->id();
        $table->uuid('uuid')->unique();
        $table->json('metadata')->nullable();
        $table->timestamps();
        $table->softDeletes();
    }
};
