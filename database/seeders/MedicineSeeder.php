<?php

namespace Database\Seeders;

use App\Models\Medicine;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class MedicineSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['Panadol', 'Paracetamol', 'Paracetamol', '500mg', 'Tablet', 'Analgesic', 'GSK', 'Pain Relief', 2.5, 3.5, 4],
            ['Brufen', 'Ibuprofen', 'Ibuprofen', '400mg', 'Tablet', 'NSAID', 'Abbott', 'Pain Relief', 8, 10, 12],
            ['Augmentin', 'Amoxicillin + Clavulanate', 'Amoxicillin/Clavulanate', '625mg', 'Tablet', 'Antibiotic', 'GSK', 'Antibiotic', 90, 110, 125],
            ['Flagyl', 'Metronidazole', 'Metronidazole', '400mg', 'Tablet', 'Antiprotozoal', 'Sanofi', 'Antibiotic', 6, 8, 10],
            ['Risek', 'Omeprazole', 'Omeprazole', '20mg', 'Capsule', 'PPI', 'Getz Pharma', 'Gastro', 10, 12, 14],
        ];

        foreach ($rows as [$brand, $generic, $composition, $strength, $form, $class, $manufacturer, $category, $purchase, $sale, $mrp]) {
            Medicine::updateOrCreate(
                ['brand_name' => $brand, 'generic_name' => $generic, 'strength' => $strength],
                [
                    'uuid' => Medicine::where('brand_name', $brand)->where('generic_name', $generic)->value('uuid') ?: (string) Str::uuid(),
                    'composition' => $composition,
                    'strength' => $strength,
                    'dosage_form' => $form,
                    'therapeutic_class' => $class,
                    'manufacturer' => $manufacturer,
                    'category' => $category,
                    'purchase_price' => $purchase,
                    'sale_price' => $sale,
                    'mrp' => $mrp,
                    'tax_percentage' => 0,
                    'reorder_level' => 10,
                    'batch_tracking' => true,
                    'expiry_tracking' => true,
                    'status' => 'Active',
                ]
            );
        }
    }
}
