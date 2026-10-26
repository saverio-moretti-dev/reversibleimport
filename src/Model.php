<?php

declare(strict_types=1);

namespace ReversibleImport;

final class Row
{
    public function __construct(
        public readonly int $line,
        public readonly string $reference,
        public readonly ?float $price,
        public readonly ?int $quantity,
    ) {
    }
}

final class RowError
{
    public function __construct(
        public readonly int $line,
        public readonly string $message,
    ) {
    }
}

final class ReadResult
{
    /**
     * @param Row[] $rows
     * @param RowError[] $errors
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $errors,
    ) {
    }
}

/** Stato attuale di un prodotto nel catalogo. */
final class Product
{
    public function __construct(
        public readonly int $id,
        public readonly string $reference,
        public readonly float $price,
        public readonly int $quantity,
    ) {
    }
}

/** Un campo da cambiare: valore di prima e valore nuovo. */
final class Change
{
    public const PRICE = 'price';
    public const QUANTITY = 'quantity';

    public function __construct(
        public readonly int $productId,
        public readonly string $reference,
        public readonly string $field,
        public readonly float|int $before,
        public readonly float|int $after,
    ) {
    }
}

final class Plan
{
    /**
     * @param Change[] $changes
     * @param string[] $unknownReferences riferimenti del file non trovati nel catalogo, o non univoci
     * @param int $unchanged righe già allineate, niente da scrivere
     */
    public function __construct(
        public readonly array $changes,
        public readonly array $unknownReferences,
        public readonly int $unchanged,
    ) {
    }
}

/** Esito di un ritorno indietro. */
final class UndoResult
{
    /**
     * @param Change[] $restored campi riportati al valore di prima
     * @param Change[] $skipped campi cambiati da qualcun altro dopo l'import: lasciati come sono
     */
    public function __construct(
        public readonly array $restored,
        public readonly array $skipped,
    ) {
    }
}

/** Il catalogo è cambiato dopo l'anteprima: l'import non parte, si rifà l'anteprima. */
final class StalePlanException extends \RuntimeException
{
    public function __construct(public readonly Change $change)
    {
        parent::__construct(sprintf(
            'Il %s di «%s» è cambiato dopo l\'anteprima: nessuna modifica scritta, rifai l\'anteprima.',
            $change->field === Change::PRICE ? 'prezzo' : 'quantità',
            $change->reference,
        ));
    }
}
