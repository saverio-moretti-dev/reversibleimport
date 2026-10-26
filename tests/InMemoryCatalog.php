<?php

declare(strict_types=1);

namespace ReversibleImport\Tests;

use ReversibleImport\CatalogRepository;
use ReversibleImport\Change;
use ReversibleImport\Product;

/**
 * Catalogo finto per i test. La transazione è una copia dello stato presa da
 * begin() e rimessa a posto da rollback(), come farebbe il database.
 * failOnWrite simula un guasto alla N-esima scrittura.
 */
final class InMemoryCatalog implements CatalogRepository
{
    /** @var array<int,Product> */
    public array $products = [];
    /** @var array<int,array{file:string,undone:bool}> */
    public array $batches = [];
    /** @var array<int,Change[]> */
    public array $snapshots = [];
    public ?int $failOnWrite = null;

    private int $writes = 0;
    private ?array $saved = null;

    public function add(int $id, string $reference, float $price, int $quantity): self
    {
        $this->products[$id] = new Product($id, $reference, $price, $quantity);

        return $this;
    }

    public function findByReferences(array $references): array
    {
        $found = [];
        foreach ($this->products as $product) {
            if (in_array($product->reference, $references, true)) {
                $found[$product->reference] = $product;
            }
        }

        return $found;
    }

    public function begin(): void
    {
        $this->saved = [$this->products, $this->batches, $this->snapshots];
    }

    public function commit(): void
    {
        $this->saved = null;
    }

    public function rollback(): void
    {
        [$this->products, $this->batches, $this->snapshots] = $this->saved;
        $this->saved = null;
    }

    public function setPrice(int $productId, float $price): void
    {
        $this->countWrite();
        $p = $this->products[$productId];
        $this->products[$productId] = new Product($p->id, $p->reference, $price, $p->quantity);
    }

    public function setQuantity(int $productId, int $quantity): void
    {
        $this->countWrite();
        $p = $this->products[$productId];
        $this->products[$productId] = new Product($p->id, $p->reference, $p->price, $quantity);
    }

    public function createBatch(string $fileName, int $changeCount): int
    {
        $id = count($this->batches) + 1;
        $this->batches[$id] = ['file' => $fileName, 'undone' => false];

        return $id;
    }

    public function saveSnapshot(int $batchId, Change $change): void
    {
        $this->snapshots[$batchId][] = $change;
    }

    public function loadSnapshot(int $batchId): array
    {
        return $this->snapshots[$batchId] ?? [];
    }

    public function currentValues(int $productId): ?array
    {
        $p = $this->products[$productId] ?? null;

        return $p ? ['price' => $p->price, 'quantity' => $p->quantity] : null;
    }

    public function markUndone(int $batchId): void
    {
        $this->batches[$batchId]['undone'] = true;
    }

    public function isUndone(int $batchId): bool
    {
        return $this->batches[$batchId]['undone'] ?? false;
    }

    private function countWrite(): void
    {
        if (++$this->writes === $this->failOnWrite) {
            throw new \RuntimeException('guasto simulato');
        }
    }
}
