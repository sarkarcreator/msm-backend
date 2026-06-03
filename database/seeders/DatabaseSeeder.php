<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Super Admin', 'Admin', 'Manager', 'Cashier', 'Technician'] as $name) {
            Role::firstOrCreate(['name' => $name], ['uuid' => Str::uuid()]);
        }

        User::firstOrCreate(
            ['email' => 'admin@dsh.local'],
            ['uuid' => Str::uuid(), 'name' => 'DSH Admin', 'password' => 'password', 'role_id' => Role::where('name', 'Super Admin')->value('id')]
        );

        foreach (['shop_name' => 'Digital Solutions Hub', 'currency' => 'PKR', 'invoice_template' => 'standard'] as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['uuid' => Str::uuid(), 'value' => $value]);
        }

        $this->call(MedicineSeeder::class);
    }
}
