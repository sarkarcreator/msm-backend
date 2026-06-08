<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        $this->createTenantTable('trader_companies', function (Blueprint $table) {
            $table->uuid('company_uuid')->nullable()->index();
            $table->string('company_name')->index();
            $table->string('contact_person')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('status')->default('Active')->index();
            $table->unique(['license_uuid', 'company_name'], 'trader_companies_license_name_unique');
        });

        $this->createTenantTable('trader_brands', function (Blueprint $table) {
            $table->uuid('brand_uuid')->nullable()->index();
            $table->uuid('company_uuid')->nullable()->index();
            $table->string('company_name')->nullable()->index();
            $table->string('brand_name')->index();
            $table->text('description')->nullable();
            $table->unique(['license_uuid', 'brand_name'], 'trader_brands_license_name_unique');
        });

        $this->createTenantTable('trader_territories', function (Blueprint $table) {
            $table->uuid('territory_uuid')->nullable()->index();
            $table->string('territory_name')->index();
            $table->string('city')->nullable()->index();
            $table->string('area')->nullable()->index();
            $table->text('notes')->nullable();
            $table->unique(['license_uuid', 'territory_name'], 'trader_territories_license_name_unique');
        });

        $this->createTenantTable('trader_routes', function (Blueprint $table) {
            $table->uuid('route_uuid')->nullable()->index();
            $table->uuid('territory_uuid')->nullable()->index();
            $table->string('territory_name')->nullable()->index();
            $table->string('route_name')->index();
            $table->string('route_code')->nullable()->index();
            $table->unique(['license_uuid', 'route_name'], 'trader_routes_license_name_unique');
        });

        $this->createTenantTable('trader_salesmen', function (Blueprint $table) {
            $table->uuid('salesman_uuid')->nullable()->index();
            $table->string('name')->index();
            $table->string('phone')->nullable();
            $table->uuid('territory_uuid')->nullable()->index();
            $table->string('territory_name')->nullable()->index();
            $table->uuid('route_uuid')->nullable()->index();
            $table->string('route_name')->nullable()->index();
            $table->string('commission_type')->default('Percentage');
            $table->decimal('commission_value', 14, 2)->default(0);
            $table->string('status')->default('Active')->index();
            $table->unique(['license_uuid', 'name', 'phone'], 'trader_salesmen_license_name_phone_unique');
        });

        $this->createTenantTable('trader_retailers', function (Blueprint $table) {
            $table->uuid('retailer_uuid')->nullable()->index();
            $table->string('shop_name')->index();
            $table->string('owner_name')->nullable()->index();
            $table->string('phone')->nullable()->index();
            $table->text('address')->nullable();
            $table->uuid('territory_uuid')->nullable()->index();
            $table->string('territory_name')->nullable()->index();
            $table->uuid('route_uuid')->nullable()->index();
            $table->string('route_name')->nullable()->index();
            $table->decimal('credit_limit', 14, 2)->default(0);
            $table->decimal('balance', 14, 2)->default(0);
            $table->unique(['license_uuid', 'shop_name', 'phone'], 'trader_retailers_license_shop_phone_unique');
        });

        $this->createTenantTable('trader_delivery_challans', function (Blueprint $table) {
            $table->uuid('challan_uuid')->nullable()->index();
            $table->string('challan_number')->index();
            $table->uuid('retailer_uuid')->nullable()->index();
            $table->string('retailer_name')->nullable()->index();
            $table->uuid('salesman_uuid')->nullable()->index();
            $table->string('salesman_name')->nullable()->index();
            $table->string('vehicle_number')->nullable()->index();
            $table->date('date')->nullable()->index();
            $table->string('status')->default('Draft')->index();
            $table->unique(['license_uuid', 'challan_number'], 'trader_challans_license_number_unique');
        });

        $this->createTenantTable('trader_recoveries', function (Blueprint $table) {
            $table->uuid('recovery_uuid')->nullable()->index();
            $table->uuid('retailer_uuid')->index();
            $table->string('retailer_name')->nullable()->index();
            $table->uuid('salesman_uuid')->nullable()->index();
            $table->string('salesman_name')->nullable()->index();
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('payment_method')->default('Cash');
            $table->date('date')->nullable()->index();
            $table->text('notes')->nullable();
        });

        $this->createTenantTable('trader_salesman_ledgers', function (Blueprint $table) {
            $table->uuid('salesman_uuid')->nullable()->index();
            $table->string('salesman_name')->nullable()->index();
            $table->string('type')->index();
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
            $table->decimal('commission', 14, 2)->default(0);
            $table->string('reference')->nullable()->index();
            $table->timestamp('entry_at')->nullable()->index();
        });

        $this->createTenantTable('trader_distributor_ledgers', function (Blueprint $table) {
            $table->string('type')->index();
            $table->string('description')->nullable();
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
            $table->string('reference')->nullable()->index();
            $table->timestamp('entry_at')->nullable()->index();
        });

        $this->addSalesColumns();
        $this->addSaleItemColumns();
        $this->addProductColumns();
        $this->seedStarterCompanies();
    }

    public function down(): void
    {
        $this->dropSalesColumns();
        $this->dropSaleItemColumns();
        $this->dropProductColumns();
        foreach ([
            'trader_distributor_ledgers', 'trader_salesman_ledgers', 'trader_recoveries',
            'trader_delivery_challans', 'trader_retailers', 'trader_salesmen',
            'trader_routes', 'trader_territories', 'trader_brands', 'trader_companies',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function createTenantTable(string $name, callable $fields): void
    {
        if (Schema::hasTable($name)) {
            return;
        }

        Schema::create($name, function (Blueprint $table) use ($fields) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('license_uuid')->index();
            $table->string('business_type')->default('Traders')->index();
            $fields($table);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('quarantined_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    private function addSalesColumns(): void
    {
        if (! Schema::hasTable('sales')) {
            return;
        }

        Schema::table('sales', function (Blueprint $table) {
            foreach ([
                'retailer_uuid', 'retailer_name', 'salesman_uuid', 'salesman_name',
                'territory_uuid', 'territory_name', 'route_uuid', 'route_name',
                'challan_uuid', 'vehicle_number',
            ] as $column) {
                if (! Schema::hasColumn('sales', $column)) {
                    $table->string($column)->nullable()->index();
                }
            }
            if (! Schema::hasColumn('sales', 'commission_amount')) {
                $table->decimal('commission_amount', 14, 2)->default(0);
            }
        });
    }

    private function dropSalesColumns(): void
    {
        if (! Schema::hasTable('sales')) {
            return;
        }

        Schema::table('sales', function (Blueprint $table) {
            foreach ([
                'retailer_uuid', 'retailer_name', 'salesman_uuid', 'salesman_name',
                'territory_uuid', 'territory_name', 'route_uuid', 'route_name',
                'challan_uuid', 'vehicle_number', 'commission_amount',
            ] as $column) {
                if (Schema::hasColumn('sales', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function addSaleItemColumns(): void
    {
        if (! Schema::hasTable('sale_items')) {
            return;
        }

        Schema::table('sale_items', function (Blueprint $table) {
            foreach (['brand', 'company_name'] as $column) {
                if (! Schema::hasColumn('sale_items', $column)) {
                    $table->string($column)->nullable()->index();
                }
            }
            if (! Schema::hasColumn('sale_items', 'total')) {
                $table->decimal('total', 14, 2)->default(0);
            }
        });
    }

    private function dropSaleItemColumns(): void
    {
        if (! Schema::hasTable('sale_items')) {
            return;
        }

        Schema::table('sale_items', function (Blueprint $table) {
            foreach (['brand', 'company_name', 'total'] as $column) {
                if (Schema::hasColumn('sale_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function addProductColumns(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'company_name')) {
                $table->string('company_name')->nullable()->index();
            }
            if (! Schema::hasColumn('products', 'company_uuid')) {
                $table->string('company_uuid')->nullable()->index();
            }
        });
    }

    private function dropProductColumns(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            foreach (['company_name', 'company_uuid'] as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function seedStarterCompanies(): void
    {
        if (! Schema::hasTable('master_catalogs')) {
            return;
        }
        $now = now();
        foreach (['Coca Cola', 'Pepsi', 'Nestle', 'Gourmet', 'Olpers', 'Shezan', 'Mitchells', 'National Foods'] as $company) {
            // Starter company names are also available in the shared catalog for quick import.
            DB::table('master_catalogs')->updateOrInsert(
                ['business_type' => 'Traders', 'category' => 'Company', 'name' => $company],
                [
                    'uuid' => (string) Str::uuid(),
                    'name' => $company,
                    'product_name' => $company,
                    'business_type' => 'Traders',
                    'category' => 'Company',
                    'brand' => $company,
                    'type' => 'Starter Company',
                    'unit' => 'Single Unit',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }
};
