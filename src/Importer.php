<?php

declare(strict_types=1);

namespace ReversibleImport;

/**
 * Scrive un piano e lo riporta indietro.
 *
 * Scrittura: fotografia dei valori di prima e modifiche nella stessa
 * transazione. Se una sola scrittura fallisce non resta niente a metà, né
 * nel catalogo né nella fotografia.
 *
 * Ritorno indietro: un campo torna al valore di prima solo se oggi vale
 * ancora quello scritto dall'import. Se nel frattempo qualcuno l'ha cambiato
 * (un ordine ha scalato la quantità, un collega ha corretto il prezzo) il
 * campo resta com'è e finisce nell'elenco dei saltati: riportarlo indietro
 * cancellerebbe un lavoro che l'import non ha fatto.
 */
final class Importer
{
    private const PRICE_EPSILON = 0.000001;

    public function __construct(private readonly CatalogRepository $catalog)
    {
    }

    /** @return int numero dell'import, da usare per il ritorno indietro */
    public function apply(Plan $plan, string $fileName): int
    {
        if ($plan->changes === []) {
            throw new \InvalidArgumentException('Il piano non contiene modifiche.');
        }

        $this->catalog->begin();
        try {
            $batchId = $this->catalog->createBatch($fileName, count($plan->changes));
            foreach ($plan->changes as $change) {
                // Fra l'anteprima e la conferma possono passare minuti: se il valore
                // non è più quello mostrato, l'anteprima non è più vera.
                $current = $this->catalog->currentValues($change->productId);
                if ($current === null || !$this->same($change->field, $current[$change->field], $change->before)) {
                    throw new StalePlanException($change);
                }
                $this->catalog->saveSnapshot($batchId, $change);
                $this->write($change->productId, $change->field, $change->after);
            }
            $this->catalog->commit();
        } catch (\Throwable $e) {
            $this->catalog->rollback();
            throw $e;
        }

        return $batchId;
    }

    public function undo(int $batchId): UndoResult
    {
        if ($this->catalog->isUndone($batchId)) {
            throw new \RuntimeException("L'import $batchId è già stato riportato indietro.");
        }

        $restored = [];
        $skipped = [];
        $this->catalog->begin();
        try {
            foreach ($this->catalog->loadSnapshot($batchId) as $change) {
                $current = $this->catalog->currentValues($change->productId);
                if ($current === null || !$this->same($change->field, $current[$change->field], $change->after)) {
                    $skipped[] = $change;
                    continue;
                }
                $this->write($change->productId, $change->field, $change->before);
                $restored[] = $change;
            }
            $this->catalog->markUndone($batchId);
            $this->catalog->commit();
        } catch (\Throwable $e) {
            $this->catalog->rollback();
            throw $e;
        }

        return new UndoResult($restored, $skipped);
    }

    private function write(int $productId, string $field, float|int $value): void
    {
        match ($field) {
            Change::PRICE => $this->catalog->setPrice($productId, (float) $value),
            Change::QUANTITY => $this->catalog->setQuantity($productId, (int) $value),
        };
    }

    private function same(string $field, float|int $a, float|int $b): bool
    {
        return $field === Change::PRICE ? abs($a - $b) <= self::PRICE_EPSILON : (int) $a === (int) $b;
    }
}
