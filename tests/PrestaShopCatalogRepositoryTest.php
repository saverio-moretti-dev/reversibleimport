<?php

declare(strict_types=1);

namespace ReversibleImport\Tests;

use PHPUnit\Framework\TestCase;
use PsLog;
use ReversibleImport\PrestaShopCatalogRepository;
use Shop;

require_once __DIR__ . '/PrestaShopStubs.php';

/**
 * The PrestaShop repository against stand-in Db/Cache/Hook/Shop classes:
 * checks the order of side effects, which the in-memory catalog cannot see.
 */
final class PrestaShopCatalogRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        PsLog::reset();
    }

    public function testQuantityCacheIsClearedOnlyAfterCommit(): void
    {
        $repository = new PrestaShopCatalogRepository(1, 1);
        $repository->begin();
        $repository->setQuantity(7, 3);

        self::assertSame(-1, PsLog::indexOf('CACHE_CLEAN'), 'cache cleared while the transaction can still be rolled back');

        $repository->commit();

        $commit = PsLog::indexOf('SQL COMMIT');
        $clean = PsLog::indexOf('CACHE_CLEAN StockAvailable::getQuantityAvailableByProduct_7-');
        $hook = PsLog::indexOf('HOOK actionUpdateQuantity 7');
        self::assertGreaterThan($commit, $clean);
        self::assertGreaterThan($clean, $hook, 'modules notified by the hook must read the new quantity');
    }

    public function testRollbackClearsNothingAndFiresNoHook(): void
    {
        $repository = new PrestaShopCatalogRepository(1, 1);
        $repository->begin();
        $repository->setQuantity(7, 3);
        $repository->rollback();
        $repository->begin();
        $repository->commit();

        self::assertSame(-1, PsLog::indexOf('CACHE_CLEAN'));
        self::assertSame(-1, PsLog::indexOf('HOOK'));
    }

    public function testMultistoreIsRefusedBeforeAnyWrite(): void
    {
        Shop::$featureActive = true;
        $repository = new PrestaShopCatalogRepository(1, 1);

        try {
            $repository->begin();
            self::fail('begin() accepted a multistore shop');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('multistore', $e->getMessage());
        }
        self::assertSame([], PsLog::$calls, 'nothing may reach the database');
    }

    public function testMultistoreIsRefusedInThePreviewToo(): void
    {
        Shop::$featureActive = true;

        $this->expectException(\RuntimeException::class);
        (new PrestaShopCatalogRepository(1, 1))->findByReferences(['A1']);
    }
}
