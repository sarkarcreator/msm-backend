<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'deleted_at')) {
            Schema::table('users', fn (Blueprint $table) => $table->softDeletes());
        }

        if (! Schema::hasTable('manual_repair_receipts')) {
            Schema::create('manual_repair_receipts', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('receipt_number')->nullable()->index();
                $table->date('date')->nullable();
                $table->string('customer_name')->nullable();
                $table->string('phone')->nullable();
                $table->string('device_name')->nullable();
                $table->string('imei')->nullable();
                $table->text('problem')->nullable();
                $table->decimal('repair_charges', 14, 2)->default(0);
                $table->decimal('advance_payment', 14, 2)->default(0);
                $table->decimal('remaining_amount', 14, 2)->default(0);
                $table->string('technician')->nullable();
                $table->date('delivery_date')->nullable();
                $table->string('template')->default('Thermal Receipt');
                $table->string('status')->default('Received');
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('licenses')) {
            Schema::create('licenses', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('license_key')->unique();
                $table->string('activation_code')->nullable();
                $table->string('owner_name')->nullable();
                $table->string('device_id')->nullable()->index();
                $table->string('type')->default('1 Month');
                $table->string('status')->default('Active');
                $table->boolean('trial')->default(false);
                $table->string('expiry_date')->nullable();
                $table->timestamp('activated_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        foreach ([
            ['repair_jobs', 'delivery_date', 'date'],
            ['repairs', 'delivery_date', 'date'],
            ['notifications', 'priority', 'string'],
            ['audit_logs', 'details', 'string'],
            ['settings', 'description', 'string'],
        ] as [$table, $column, $type]) {
            $this->addColumnIfMissing($table, $column, $type);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_repair_receipts');
        Schema::dropIfExists('licenses');
    }

    private function addColumnIfMissing(string $tableName, string $column, string $type = 'string'): void
    {
        if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, $column)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($column, $type) {
            $type === 'date' ? $table->date($column)->nullable() : $table->string($column)->nullable();
        });
    }
};
