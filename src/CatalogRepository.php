<?php

declare(strict_types=1);

namespace ReversibleImport;

/**
 * Tutto ciò che la logica chiede al catalogo. In PrestaShop lo implementa
 * PrestaShopCatalogRepository; nei test una versione in memoria.
 */
interface CatalogRepository
{
    /**
     * @param string[] $references
     * @return array<string,Product> riferimento => prodotto (solo quelli trovati)
     */
    public function findByReferences(array $references): array;

    public function begin(): void;

    public function commit(): void;

    public function rollback(): void;

    public function setPrice(int $productId, float $price): void;

    public function setQuantity(int $productId, int $quantity): void;

    /** Registra un import e restituisce il suo numero. */
    public function createBatch(string $fileName, int $changeCount): int;

    /** Salva la fotografia di un campo prima della scrittura. */
    public function saveSnapshot(int $batchId, Change $change): void;

    /** @return Change[] */
    public function loadSnapshot(int $batchId): array;

    /** @return array{price: float, quantity: int}|null */
    public function currentValues(int $productId): ?array;

    public function markUndone(int $batchId): void;

    public function isUndone(int $batchId): bool;
}
