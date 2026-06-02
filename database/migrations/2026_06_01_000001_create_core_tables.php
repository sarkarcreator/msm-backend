<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id(); $table->uuid('uuid')->unique(); $table->string('name')->unique(); $table->json('metadata')->nullable(); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('permissions', function (Blueprint $table) {
            $table->id(); $table->uuid('uuid')->unique(); $table->string('name')->unique(); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary(); $table->mediumText('value'); $table->integer('expiration');
        });
        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary(); $table->string('owner'); $table->integer('expiration');
        });
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary(); $table->foreignId('user_id')->nullable()->index(); $table->string('ip_address', 45)->nullable(); $table->text('user_agent')->nullable(); $table->longText('payload'); $table->integer('last_activity')->index();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id(); $table->uuid('uuid')->unique(); $table->foreignId('role_id')->nullable()->constrained(); $table->string('name'); $table->string('email')->unique(); $table->timestamp('email_verified_at')->nullable(); $table->string('password'); $table->rememberToken(); $table->timestamps();
        });
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id(); $table->morphs('tokenable'); $table->string('name'); $table->string('token', 64)->unique(); $table->text('abilities')->nullable(); $table->timestamp('last_used_at')->nullable(); $table->timestamp('expires_at')->nullable(); $table->timestamps();
        });
        Schema::create('categories', fn (Blueprint $table) => $this->catalog($table, ['name']));
        Schema::create('brands', fn (Blueprint $table) => $this->catalog($table, ['name']));
        Schema::create('suppliers', function (Blueprint $table) { $this->catalog($table, ['supplier_name', 'phone', 'address']); $table->decimal('balance', 14, 2)->default(0); });
        Schema::create('customers', function (Blueprint $table) { $this->catalog($table, ['name', 'phone', 'address', 'cnic', 'notes']); $table->decimal('balance', 14, 2)->default(0); });
        Schema::create('products', function (Blueprint $table) {
            $this->catalog($table, ['product_name', 'brand', 'model', 'category', 'imei_number', 'serial_number', 'barcode', 'warranty', 'status']);
            $table->foreignId('supplier_id')->nullable()->constrained();
            $table->decimal('purchase_price', 14, 2)->default(0);
            $table->decimal('sale_price', 14, 2)->default(0);
            $table->integer('quantity')->default(0);
            $table->integer('low_stock_threshold')->default(3);
        });
        Schema::create('imei_numbers', function (Blueprint $table) { $this->catalog($table, ['imei_number', 'status']); $table->foreignId('product_id')->constrained(); });
        Schema::create('inventory_transactions', function (Blueprint $table) { $this->catalog($table, ['type', 'reason', 'reference']); $table->foreignId('product_id')->constrained(); $table->integer('quantity'); });
        Schema::create('sales', function (Blueprint $table) {
            $this->catalog($table, ['invoice_number', 'payment_type', 'status', 'notes']);
            $table->foreignId('customer_id')->nullable()->constrained();
            $table->decimal('subtotal', 14, 2)->default(0); $table->decimal('discount', 14, 2)->default(0); $table->decimal('tax', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0); $table->decimal('paid', 14, 2)->default(0); $table->decimal('profit', 14, 2)->default(0);
            $table->timestamp('sold_at')->nullable();
        });
        Schema::create('sale_items', function (Blueprint $table) { $this->catalog($table, ['imei_number']); $table->foreignId('sale_id')->constrained(); $table->foreignId('product_id')->constrained(); $table->integer('quantity'); $table->decimal('price', 14, 2); $table->decimal('profit', 14, 2)->default(0); });
        Schema::create('customer_payments', function (Blueprint $table) { $this->catalog($table, ['method', 'notes']); $table->foreignId('customer_id')->constrained(); $table->decimal('amount', 14, 2); });
        Schema::create('credits', function (Blueprint $table) { $this->catalog($table, ['status', 'notes']); $table->foreignId('customer_id')->constrained(); $table->foreignId('sale_id')->nullable()->constrained(); $table->decimal('amount', 14, 2); $table->date('due_date')->nullable(); });
        Schema::create('purchases', function (Blueprint $table) { $this->catalog($table, ['invoice_number', 'status']); $table->foreignId('supplier_id')->constrained(); $table->decimal('total', 14, 2)->default(0); $table->timestamp('purchased_at')->nullable(); });
        Schema::create('purchase_items', function (Blueprint $table) { $this->catalog($table, []); $table->foreignId('purchase_id')->constrained(); $table->foreignId('product_id')->constrained(); $table->integer('quantity'); $table->decimal('purchase_price', 14, 2); });
        Schema::create('expenses', function (Blueprint $table) { $this->catalog($table, ['category', 'description']); $table->decimal('amount', 14, 2); $table->timestamp('spent_at')->nullable(); });
        Schema::create('cashbook', function (Blueprint $table) { $this->catalog($table, ['type', 'description', 'reference']); $table->decimal('debit', 14, 2)->default(0); $table->decimal('credit', 14, 2)->default(0); });
        Schema::create('repair_status', fn (Blueprint $table) => $this->catalog($table, ['name']));
        Schema::create('repair_jobs', function (Blueprint $table) { $this->catalog($table, ['job_number', 'customer_name', 'phone', 'device', 'imei_number', 'status', 'technician_notes', 'delivery_status']); $table->decimal('repair_charges', 14, 2)->default(0); });
        Schema::create('notifications', fn (Blueprint $table) => $this->catalog($table, ['title', 'body', 'type']));
        Schema::create('settings', fn (Blueprint $table) => $this->catalog($table, ['key', 'value']));
        Schema::create('audit_logs', fn (Blueprint $table) => $this->catalog($table, ['user_name', 'action', 'entity', 'entity_uuid']));
        Schema::create('sync_queue', function (Blueprint $table) { $table->id(); $table->uuid('uuid')->index(); $table->string('device_id')->index(); $table->string('entity')->index(); $table->string('action'); $table->json('payload')->nullable(); $table->timestamp('synced_at')->nullable(); $table->timestamps(); });
    }

    public function down(): void
    {
        collect(['sync_queue','audit_logs','settings','notifications','repair_jobs','repair_status','cashbook','expenses','purchase_items','purchases','credits','customer_payments','sale_items','sales','inventory_transactions','imei_numbers','products','customers','suppliers','brands','categories','personal_access_tokens','users','sessions','cache_locks','cache','permissions','roles'])->each(fn ($table) => Schema::dropIfExists($table));
    }

    private function catalog(Blueprint $table, array $strings): void
    {
        $table->id(); $table->uuid('uuid')->unique();
        foreach ($strings as $column) { $table->string($column)->nullable(); }
        $table->json('metadata')->nullable(); $table->timestamps(); $table->softDeletes();
    }
};
