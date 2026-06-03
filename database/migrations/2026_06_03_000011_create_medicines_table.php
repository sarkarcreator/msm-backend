<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('medicines')) {
            return;
        }

        Schema::create('medicines', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('brand_name')->index();
            $table->string('generic_name')->nullable()->index();
            $table->text('composition')->nullable();
            $table->string('strength')->nullable();
            $table->string('dosage_form')->nullable()->index();
            $table->string('therapeutic_class')->nullable()->index();
            $table->string('manufacturer')->nullable()->index();
            $table->string('distributor')->nullable()->index();
            $table->string('registration_no')->nullable()->index();
            $table->string('barcode')->nullable()->unique();
            $table->string('pack_size')->nullable();
            $table->string('category')->nullable()->index();
            $table->decimal('purchase_price', 14, 2)->default(0);
            $table->decimal('sale_price', 14, 2)->default(0);
            $table->decimal('mrp', 14, 2)->default(0);
            $table->decimal('tax_percentage', 8, 2)->default(0);
            $table->unsignedInteger('reorder_level')->default(0);
            $table->boolean('batch_tracking')->default(true);
            $table->boolean('expiry_tracking')->default(true);
            $table->string('status')->default('Active')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->fullText(['brand_name', 'generic_name', 'composition', 'manufacturer'], 'medicines_fulltext_search');
            $table->index(['status', 'category']);
            $table->index(['status', 'manufacturer']);
            $table->index(['generic_name', 'strength']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medicines');
    }
};
