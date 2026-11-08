<?php

declare(strict_types=1);

/*
 * Minimal stand-ins for the PrestaShop classes PrestaShopCatalogRepository uses,
 * so its SQL and the order of its side effects can be tested without a shop.
 * Every call is appended to PsLog::$calls in the order it happens.
 */

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}

if (!function_exists('pSQL')) {
    function pSQL(string $value): string
    {
        return addslashes($value);
    }
}

final class PsLog
{
    /** @var string[] */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$calls = [];
        Shop::$featureActive = false;
        Db::$rows = [];
    }

    public static function indexOf(string $prefix): int
    {
        foreach (self::$calls as $i => $call) {
            if (str_starts_with($call, $prefix)) {
                return $i;
            }
        }

        return -1;
    }
}

final class Db
{
    /** @var array<int,array<string,mixed>> rows returned by executeS */
    public static array $rows = [];
    private static ?Db $instance = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function execute(string $sql): bool
    {
        PsLog::$calls[] = 'SQL ' . preg_replace('/\s+/', ' ', trim($sql));

        return true;
    }

    public function executeS(string $sql): array
    {
        PsLog::$calls[] = 'SQL ' . preg_replace('/\s+/', ' ', trim($sql));

        return self::$rows;
    }

    public function insert(string $table, array $data): bool
    {
        PsLog::$calls[] = 'INSERT ' . $table;

        return true;
    }

    public function Insert_ID(): int
    {
        return 1;
    }

    public function getMsgError(): string
    {
        return '';
    }
}

final class Cache
{
    public static function clean(string $key): void
    {
        PsLog::$calls[] = 'CACHE_CLEAN ' . $key;
    }
}

final class Hook
{
    public static function exec(string $name, array $params = []): void
    {
        PsLog::$calls[] = 'HOOK ' . $name . ' ' . $params['id_product'];
    }
}

final class Shop
{
    public static bool $featureActive = false;

    public static function isFeatureActive(): bool
    {
        return self::$featureActive;
    }
}
