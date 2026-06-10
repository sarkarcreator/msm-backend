<?php

namespace App\Services;

class BusinessTypeService
{
    private const HOSPITAL_ONLY = [
        'patients', 'assistants', 'hospital-prescriptions', 'hospital-orders', 'hospital-tasks',
        'lab-reports', 'radiology-reports', 'hospital-bills', 'hospital-bill-items',
    ];

    private const REPAIR_ONLY = ['repairs', 'repair-updates', 'manual-repair-receipts'];

    private const MOBILE_SHOP_ONLY = [
        'imei-registry', 'imei-movements', 'warranty-claims', 'sale-returns',
        'sale-return-items', 'purchase-returns', 'purchase-return-items',
    ];

    private const TRADERS_ONLY = [
        'trader-companies', 'trader-brands', 'trader-territories', 'trader-routes',
        'trader-salesmen', 'trader-retailers', 'trader-delivery-challans',
        'trader-recoveries', 'trader-salesman-ledgers', 'trader-distributor-ledgers',
    ];

    public function key(?string $businessType): string
    {
        $normalized = strtolower(str_replace([' ', '-'], '_', trim((string) $businessType)));

        return match ($normalized) {
            'mobile', 'mobile_shop' => 'mobile_shop',
            'hospital' => 'hospital',
            'pharmacy' => 'pharmacy',
            'traders' => 'traders',
            'electronics_store' => 'electronics_store',
            'general_store', 'grocery_store', 'grocery', 'shopping_mall', 'retail_shop' => 'general_store',
            default => 'generic_shop',
        };
    }

    public function allowsResource(?string $businessType, string $resource): bool
    {
        $key = $this->key($businessType ?: 'General Store');

        if ($key !== 'hospital' && in_array($resource, self::HOSPITAL_ONLY, true)) {
            return false;
        }

        if (! in_array($key, ['mobile_shop'], true) && in_array($resource, self::MOBILE_SHOP_ONLY, true)) {
            return false;
        }

        if (! in_array($key, ['mobile_shop', 'electronics_store'], true) && in_array($resource, self::REPAIR_ONLY, true)) {
            return false;
        }

        if ($key !== 'traders' && in_array($resource, self::TRADERS_ONLY, true)) {
            return false;
        }

        return true;
    }
}
