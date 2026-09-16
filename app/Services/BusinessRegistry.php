<?php

namespace App\Services;

/**
 * Canonical business capability registry.
 *
 * This is intentionally data-only. Authorization remains enforced by
 * SyncAuthorizationService and route/controller policies. The registry gives
 * the frontend and backend one stable vocabulary for business contexts.
 */
class BusinessRegistry
{
    public const GENERAL_STORE = 'general_store';
    public const MOBILE_SHOP = 'mobile_shop';
    public const PHARMACY = 'pharmacy';
    public const HOSPITAL = 'hospital';
    public const TRADERS = 'traders';
    public const ELECTRONICS_STORE = 'electronics_store';
    public const CLOTHING_STORE = 'clothing_store';
    public const HARDWARE_STORE = 'hardware_store';

    public function definitions(): array
    {
        return [
            self::GENERAL_STORE => ['label' => 'Store', 'aliases' => ['General Store', 'Grocery Store', 'Grocery', 'Retail Shop', 'Shopping Mall']],
            self::MOBILE_SHOP => ['label' => 'Mobile Shop', 'aliases' => ['Mobile Shop', 'Mobile']],
            self::PHARMACY => ['label' => 'Pharmacy', 'aliases' => ['Pharmacy']],
            self::HOSPITAL => ['label' => 'Hospital', 'aliases' => ['Hospital']],
            self::TRADERS => ['label' => 'Traders', 'aliases' => ['Traders', 'Trader', 'Distribution']],
            self::ELECTRONICS_STORE => ['label' => 'Electronics Store', 'aliases' => ['Electronics Store', 'Electronics']],
            self::CLOTHING_STORE => ['label' => 'Clothing Store', 'aliases' => ['Clothing Store']],
            self::HARDWARE_STORE => ['label' => 'Hardware Store', 'aliases' => ['Hardware Store']],
        ];
    }

    public function normalize(?string $type): string
    {
        $normalized = strtolower(preg_replace('/[\s-]+/', '_', trim((string) $type)));

        foreach ($this->definitions() as $key => $definition) {
            if ($normalized === $key) {
                return $key;
            }

            foreach ($definition['aliases'] as $alias) {
                $aliasKey = strtolower(preg_replace('/[\s-]+/', '_', trim($alias)));
                if ($normalized === $aliasKey) {
                    return $key;
                }
            }
        }

        return self::GENERAL_STORE;
    }

    public function definition(?string $type): array
    {
        $key = $this->normalize($type);
        return ['id' => $key] + ($this->definitions()[$key] ?? $this->definitions()[self::GENERAL_STORE]);
    }
}
