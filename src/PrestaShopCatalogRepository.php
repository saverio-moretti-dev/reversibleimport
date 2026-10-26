<?php

declare(strict_types=1);

namespace ReversibleImport;

use Cache;
use Db;
use Hook;

/**
 * Il catalogo di PrestaShop 8.1, negozio singolo, prodotti senza combinazioni.
 *
 * Scrive con SQL diretto e non con Product::save(): save() riscrive tutti i
 * campi del prodotto e lancia hook a ogni riga, cioè lento su migliaia di righe
 * e con effetti esterni (sincronizzazioni con i marketplace) dentro una
 * transazione che potrebbe ancora essere annullata. Gli hook sulle quantità
 * partono solo dopo il commit, una volta per prodotto.
 */
final class PrestaShopCatalogRepository implements CatalogRepository
{
    private Db $db;

    /** @var array<int,int> prodotto => quantità scritta, per gli hook dopo il commit */
    private array $pendingQuantityHooks = [];

    public function __construct(private readonly int $shopId, private readonly int $employeeId)
    {
        $this->db = Db::getInstance();
    }

    public function findByReferences(array $references): array
    {
        if ($references === []) {
            return [];
        }
        $in = implode(',', array_map(fn (string $r) => "'" . pSQL($r) . "'", $references));

        // Un riferimento usato da più prodotti è ambiguo: escluso, come se non esistesse.
        // Esclusi anche i prodotti con combinazioni: la loro quantità è la somma delle varianti.
        $rows = $this->db->executeS(
            'SELECT p.id_product, p.reference, ps.price, sa.quantity
            FROM `' . _DB_PREFIX_ . 'product` p
            INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps
                ON ps.id_product = p.id_product AND ps.id_shop = ' . $this->shopId . '
            INNER JOIN `' . _DB_PREFIX_ . 'stock_available` sa
                ON sa.id_product = p.id_product AND sa.id_product_attribute = 0 AND sa.id_shop = ' . $this->shopId . '
            WHERE p.reference IN (' . $in . ')
                AND NOT EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'product_attribute` pa WHERE pa.id_product = p.id_product)
                AND p.reference IN (
                    SELECT reference FROM `' . _DB_PREFIX_ . 'product`
                    WHERE reference IN (' . $in . ')
                    GROUP BY reference HAVING COUNT(*) = 1
                )'
        ) ?: [];

        $products = [];
        foreach ($rows as $row) {
            $products[$row['reference']] = new Product(
                (int) $row['id_product'],
                $row['reference'],
                (float) $row['price'],
                (int) $row['quantity'],
            );
        }

        return $products;
    }

    public function begin(): void
    {
        $this->pendingQuantityHooks = [];
        $this->execute('START TRANSACTION');
    }

    public function commit(): void
    {
        $this->execute('COMMIT');
        foreach ($this->pendingQuantityHooks as $productId => $quantity) {
            Hook::exec('actionUpdateQuantity', [
                'id_product' => $productId,
                'id_product_attribute' => 0,
                'quantity' => $quantity,
            ]);
        }
        $this->pendingQuantityHooks = [];
    }

    public function rollback(): void
    {
        $this->execute('ROLLBACK');
        $this->pendingQuantityHooks = [];
    }

    public function setPrice(int $productId, float $price): void
    {
        $value = sprintf('%.6F', $price);
        $this->execute('UPDATE `' . _DB_PREFIX_ . 'product` SET price = ' . $value . ', date_upd = NOW() WHERE id_product = ' . $productId);
        $this->execute('UPDATE `' . _DB_PREFIX_ . 'product_shop` SET price = ' . $value . ', date_upd = NOW()
            WHERE id_product = ' . $productId . ' AND id_shop = ' . $this->shopId);
    }

    public function setQuantity(int $productId, int $quantity): void
    {
        $this->execute('UPDATE `' . _DB_PREFIX_ . 'stock_available` SET quantity = ' . $quantity . '
            WHERE id_product = ' . $productId . ' AND id_product_attribute = 0 AND id_shop = ' . $this->shopId);
        $this->pendingQuantityHooks[$productId] = $quantity;
        // PrestaShop tiene la quantità letta in una cache della richiesta: senza pulirla,
        // chi la legge dopo (anche i moduli avvisati dall'hook) vedrebbe il valore vecchio.
        Cache::clean('StockAvailable::getQuantityAvailableByProduct_' . $productId . '-*');
    }

    public function createBatch(string $fileName, int $changeCount): int
    {
        $this->insert('reversibleimport_batch', [
            'file_name' => pSQL(mb_substr($fileName, 0, 255)),
            'change_count' => $changeCount,
            'id_employee' => $this->employeeId,
            'id_shop' => $this->shopId,
            'date_add' => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->Insert_ID();
    }

    public function saveSnapshot(int $batchId, Change $change): void
    {
        $this->insert('reversibleimport_snapshot', [
            'id_batch' => $batchId,
            'id_product' => $change->productId,
            'reference' => pSQL($change->reference),
            'field' => pSQL($change->field),
            'value_before' => (string) $change->before,
            'value_after' => (string) $change->after,
        ]);
    }

    public function loadSnapshot(int $batchId): array
    {
        $rows = $this->db->executeS(
            'SELECT id_product, reference, field, value_before, value_after
            FROM `' . _DB_PREFIX_ . 'reversibleimport_snapshot`
            WHERE id_batch = ' . $batchId . ' ORDER BY id_snapshot'
        ) ?: [];

        return array_map(function (array $row): Change {
            $cast = $row['field'] === Change::PRICE ? 'floatval' : 'intval';

            return new Change(
                (int) $row['id_product'],
                $row['reference'],
                $row['field'],
                $cast($row['value_before']),
                $cast($row['value_after']),
            );
        }, $rows);
    }

    public function currentValues(int $productId): ?array
    {
        // executeS e non getRow: getRow aggiunge «LIMIT 1» dopo il FOR UPDATE, e la query non è più valida.
        $rows = $this->db->executeS(
            'SELECT ps.price, sa.quantity
            FROM `' . _DB_PREFIX_ . 'product_shop` ps
            INNER JOIN `' . _DB_PREFIX_ . 'stock_available` sa
                ON sa.id_product = ps.id_product AND sa.id_product_attribute = 0 AND sa.id_shop = ps.id_shop
            WHERE ps.id_product = ' . $productId . ' AND ps.id_shop = ' . $this->shopId . '
            FOR UPDATE'
        );
        $row = $rows[0] ?? null;

        return $row ? ['price' => (float) $row['price'], 'quantity' => (int) $row['quantity']] : null;
    }

    public function markUndone(int $batchId): void
    {
        $this->execute('UPDATE `' . _DB_PREFIX_ . 'reversibleimport_batch` SET date_undone = NOW() WHERE id_batch = ' . $batchId);
    }

    public function isUndone(int $batchId): bool
    {
        return (bool) $this->db->getValue(
            'SELECT date_undone IS NOT NULL FROM `' . _DB_PREFIX_ . 'reversibleimport_batch` WHERE id_batch = ' . $batchId
        );
    }

    /** @return array<int,array<string,mixed>> ultimi import, i più recenti prima */
    public function listBatches(int $limit = 50): array
    {
        return $this->db->executeS(
            'SELECT b.id_batch, b.file_name, b.change_count, b.date_add, b.date_undone, e.firstname, e.lastname
            FROM `' . _DB_PREFIX_ . 'reversibleimport_batch` b
            LEFT JOIN `' . _DB_PREFIX_ . 'employee` e ON e.id_employee = b.id_employee
            WHERE b.id_shop = ' . $this->shopId . '
            ORDER BY b.id_batch DESC LIMIT ' . $limit
        ) ?: [];
    }

    private function insert(string $table, array $data): void
    {
        if (!$this->db->insert($table, $data)) {
            throw new \RuntimeException("Scrittura su $table fallita: " . $this->db->getMsgError());
        }
    }

    private function execute(string $sql): void
    {
        if (!$this->db->execute($sql)) {
            throw new \RuntimeException('Query fallita: ' . $this->db->getMsgError());
        }
    }
}
