<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('master_catalogs')) {
            Schema::create('master_catalogs', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('name')->index();
                $table->string('business_type')->default('General Store')->index();
                $table->string('category')->nullable()->index();
                $table->string('brand')->nullable()->index();
                $table->string('type')->nullable();
                $table->string('unit')->nullable();
                $table->text('notes')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('master_catalogs');
    }
};
