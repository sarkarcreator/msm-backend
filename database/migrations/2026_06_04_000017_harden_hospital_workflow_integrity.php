<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->addColumn('hospital_orders', 'doctor_review_status');
        $this->addColumn('hospital_tasks', 'doctor_review_status');
        $this->addColumn('lab_reports', 'doctor_review_status');
        $this->addColumn('radiology_reports', 'doctor_review_status');

        if (Schema::hasTable('hospital_prescriptions')) {
            Schema::table('hospital_prescriptions', function (Blueprint $table) {
                if (! Schema::hasColumn('hospital_prescriptions', 'dispensed_at')) {
                    $table->timestamp('dispensed_at')->nullable()->index();
                }
                if (! Schema::hasColumn('hospital_prescriptions', 'dispensed_by')) {
                    $table->string('dispensed_by')->nullable();
                }
            });
        }

        if (Schema::hasTable('hospital_bills')) {
            Schema::table('hospital_bills', function (Blueprint $table) {
                if (! Schema::hasColumn('hospital_bills', 'active_bill_key')) {
                    $table->string('active_bill_key')->nullable()->unique();
                }
            });
        }

        $this->index('patients', ['license_uuid', 'visit_date', 'token_number'], 'patients_license_visit_token_index');
        $this->index('hospital_bills', ['license_uuid', 'patient_uuid', 'status'], 'hospital_bills_license_patient_status_index');
        $this->index('hospital_prescriptions', ['license_uuid', 'patient_uuid', 'status'], 'hospital_rx_license_patient_status_index');
        $this->index('hospital_tasks', ['license_uuid', 'patient_uuid', 'status'], 'hospital_tasks_license_patient_status_index');
        $this->index('lab_reports', ['license_uuid', 'patient_uuid', 'status'], 'lab_reports_license_patient_status_index');
        $this->index('radiology_reports', ['license_uuid', 'patient_uuid', 'status'], 'radiology_reports_license_patient_status_index');
    }

    public function down(): void
    {
        $this->dropIndex('radiology_reports', 'radiology_reports_license_patient_status_index');
        $this->dropIndex('lab_reports', 'lab_reports_license_patient_status_index');
        $this->dropIndex('hospital_tasks', 'hospital_tasks_license_patient_status_index');
        $this->dropIndex('hospital_prescriptions', 'hospital_rx_license_patient_status_index');
        $this->dropIndex('hospital_bills', 'hospital_bills_license_patient_status_index');
        $this->dropIndex('patients', 'patients_license_visit_token_index');

        foreach (['hospital_orders', 'hospital_tasks', 'lab_reports', 'radiology_reports'] as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'doctor_review_status')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('doctor_review_status'));
            }
        }

        if (Schema::hasTable('hospital_prescriptions')) {
            Schema::table('hospital_prescriptions', function (Blueprint $table) {
                foreach (['dispensed_at', 'dispensed_by'] as $column) {
                    if (Schema::hasColumn('hospital_prescriptions', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('hospital_bills') && Schema::hasColumn('hospital_bills', 'active_bill_key')) {
            Schema::table('hospital_bills', fn (Blueprint $table) => $table->dropColumn('active_bill_key'));
        }
    }

    private function addColumn(string $tableName, string $column): void
    {
        if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, $column)) {
            return;
        }

        Schema::table($tableName, fn (Blueprint $table) => $table->string($column)->default('Pending')->index());
    }

    private function index(string $tableName, array $columns, string $name): void
    {
        if (! Schema::hasTable($tableName) || $this->hasIndex($tableName, $name)) {
            return;
        }

        Schema::table($tableName, fn (Blueprint $table) => $table->index($columns, $name));
    }

    private function dropIndex(string $tableName, string $name): void
    {
        if (! Schema::hasTable($tableName) || ! $this->hasIndex($tableName, $name)) {
            return;
        }

        Schema::table($tableName, fn (Blueprint $table) => $table->dropIndex($name));
    }

    private function hasIndex(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn ($item) => ($item['name'] ?? '') === $index);
    }
};
