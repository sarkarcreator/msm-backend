<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('hospital_bills')) {
            Schema::table('hospital_bills', function (Blueprint $table) {
                foreach ([
                    ['paid_amount', 'decimal'],
                    ['payment_method', 'string'],
                    ['received_by', 'string'],
                    ['received_at', 'timestamp'],
                    ['completion_time', 'timestamp'],
                ] as [$column, $type]) {
                    if (Schema::hasColumn('hospital_bills', $column)) {
                        continue;
                    }
                    match ($type) {
                        'decimal' => $table->decimal($column, 14, 2)->default(0),
                        'timestamp' => $table->timestamp($column)->nullable()->index(),
                        default => $table->string($column)->nullable(),
                    };
                }
            });
        }

        if (Schema::hasTable('audit_logs')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                if (! Schema::hasColumn('audit_logs', 'license_uuid')) {
                    $table->uuid('license_uuid')->nullable()->index();
                }
                if (! Schema::hasColumn('audit_logs', 'business_type')) {
                    $table->string('business_type')->nullable()->index();
                }
            });
        }

        if (Schema::hasTable('hospital_prescriptions')) {
            Schema::table('hospital_prescriptions', function (Blueprint $table) {
                if (! Schema::hasColumn('hospital_prescriptions', 'dispensed_quantity')) {
                    $table->unsignedInteger('dispensed_quantity')->default(0);
                }
            });
        }

        if (Schema::hasTable('lab_reports')) {
            Schema::table('lab_reports', function (Blueprint $table) {
                $this->stringColumn($table, 'lab_reports', 'technician_name');
                $this->timestampColumn($table, 'lab_reports', 'completed_at');
                $this->stringColumn($table, 'lab_reports', 'attachment_url');
                $this->textColumn($table, 'lab_reports', 'remarks');
            });
        }

        if (Schema::hasTable('radiology_reports')) {
            Schema::table('radiology_reports', function (Blueprint $table) {
                $this->textColumn($table, 'radiology_reports', 'report_text');
                $this->textColumn($table, 'radiology_reports', 'findings');
                $this->textColumn($table, 'radiology_reports', 'impression');
                $this->stringColumn($table, 'radiology_reports', 'radiologist_name');
                $this->timestampColumn($table, 'radiology_reports', 'completed_at');
                $this->stringColumn($table, 'radiology_reports', 'attachment_url');
            });
        }
    }

    public function down(): void
    {
        $this->dropColumns('radiology_reports', ['report_text', 'findings', 'impression', 'radiologist_name', 'completed_at', 'attachment_url']);
        $this->dropColumns('lab_reports', ['technician_name', 'completed_at', 'attachment_url', 'remarks']);
        $this->dropColumns('hospital_prescriptions', ['dispensed_quantity']);
        $this->dropColumns('audit_logs', ['license_uuid', 'business_type']);
        $this->dropColumns('hospital_bills', ['paid_amount', 'payment_method', 'received_by', 'received_at', 'completion_time']);
    }

    private function textColumn(Blueprint $table, string $tableName, string $column): void
    {
        if (! Schema::hasColumn($tableName, $column)) {
            $table->text($column)->nullable();
        }
    }

    private function stringColumn(Blueprint $table, string $tableName, string $column): void
    {
        if (! Schema::hasColumn($tableName, $column)) {
            $table->string($column)->nullable();
        }
    }

    private function timestampColumn(Blueprint $table, string $tableName, string $column): void
    {
        if (! Schema::hasColumn($tableName, $column)) {
            $table->timestamp($column)->nullable()->index();
        }
    }

    private function dropColumns(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table, $columns) {
            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column)) {
                    $blueprint->dropColumn($column);
                }
            }
        });
    }
};
