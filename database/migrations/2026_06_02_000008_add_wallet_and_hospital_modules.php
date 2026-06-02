<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('mobile_wallet_transactions')) {
            Schema::create('mobile_wallet_transactions', function (Blueprint $table) {
                $this->base($table);
                $table->string('provider')->nullable();
                $table->string('type')->nullable();
                $table->string('customer_name')->nullable();
                $table->string('phone')->nullable();
                $table->decimal('amount', 14, 2)->default(0);
                $table->decimal('fee', 14, 2)->default(0);
                $table->decimal('net_amount', 14, 2)->default(0);
                $table->string('reference_number')->nullable()->index();
                $table->string('status')->default('Completed');
                $table->timestamp('transacted_at')->nullable();
                $table->text('notes')->nullable();
            });
        }

        if (! Schema::hasTable('patients')) {
            Schema::create('patients', function (Blueprint $table) {
                $this->base($table);
                $table->string('patient_name')->nullable()->index();
                $table->string('phone')->nullable()->index();
                $table->integer('age')->default(0);
                $table->string('gender')->nullable();
                $table->string('cnic')->nullable();
                $table->string('doctor_name')->nullable()->index();
                $table->string('assistant_name')->nullable();
                $table->text('symptoms')->nullable();
                $table->text('diagnosis')->nullable();
                $table->text('medicine')->nullable();
                $table->decimal('fee', 14, 2)->default(0);
                $table->string('status')->default('Waiting');
                $table->date('visit_date')->nullable();
                $table->date('next_visit')->nullable();
                $table->text('notes')->nullable();
            });
        }

        if (! Schema::hasTable('assistants')) {
            Schema::create('assistants', function (Blueprint $table) {
                $this->base($table);
                $table->string('name')->nullable()->index();
                $table->string('phone')->nullable();
                $table->string('role')->default('Compounder');
                $table->string('doctor_name')->nullable()->index();
                $table->string('shift')->nullable();
                $table->decimal('salary', 14, 2)->default(0);
                $table->string('status')->default('Active');
                $table->text('notes')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assistants');
        Schema::dropIfExists('patients');
        Schema::dropIfExists('mobile_wallet_transactions');
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
