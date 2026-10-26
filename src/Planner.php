<?php

declare(strict_types=1);

namespace ReversibleImport;

/**
 * Confronta il file con il catalogo e dice cosa cambierebbe, senza scrivere.
 * È l'anteprima che l'utente conferma prima dell'import.
 */
final class Planner
{
    /** Sotto questa differenza due prezzi sono uguali (PrestaShop salva 6 decimali). */
    private const PRICE_EPSILON = 0.000001;

    public function __construct(private readonly CatalogRepository $catalog)
    {
    }

    /** @param Row[] $rows */
    public function plan(array $rows): Plan
    {
        $products = $this->catalog->findByReferences(array_map(fn (Row $r) => $r->reference, $rows));

        $changes = [];
        $unknown = [];
        $unchanged = 0;
        foreach ($rows as $row) {
            $product = $products[$row->reference] ?? null;
            if ($product === null) {
                $unknown[] = $row->reference;
                continue;
            }
            $rowChanges = [];
            if ($row->price !== null && abs($row->price - $product->price) > self::PRICE_EPSILON) {
                $rowChanges[] = new Change($product->id, $product->reference, Change::PRICE, $product->price, $row->price);
            }
            if ($row->quantity !== null && $row->quantity !== $product->quantity) {
                $rowChanges[] = new Change($product->id, $product->reference, Change::QUANTITY, $product->quantity, $row->quantity);
            }
            if ($rowChanges === []) {
                ++$unchanged;
            }
            array_push($changes, ...$rowChanges);
        }

        return new Plan($changes, $unknown, $unchanged);
    }
}
