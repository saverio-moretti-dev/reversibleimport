<?php

declare(strict_types=1);

namespace ReversibleImport;

/**
 * Legge un CSV di aggiornamento: riferimento;prezzo;quantita.
 *
 * Non si ferma al primo errore: raccoglie tutte le righe sbagliate con il
 * numero di riga, così chi prepara il file le corregge in un solo giro.
 */
final class CsvReader
{
    private const REQUIRED = ['reference'];
    private const OPTIONAL = ['price', 'quantity'];

    /** Intestazioni accettate per ogni colonna (minuscolo, senza spazi). */
    private const ALIASES = [
        'reference' => ['reference', 'riferimento', 'sku', 'codice'],
        'price' => ['price', 'prezzo'],
        'quantity' => ['quantity', 'quantita', 'quantità', 'qta', 'stock'],
    ];

    public function read(string $content): ReadResult
    {
        $content = $this->stripBom($content);
        $lines = preg_split('/\r\n|\n|\r/', trim($content));
        if ($lines === false || $lines === [''] || $lines === []) {
            return new ReadResult([], [new RowError(1, 'file vuoto')]);
        }

        $delimiter = $this->detectDelimiter($lines[0]);
        $header = str_getcsv($lines[0], $delimiter, '"', '');
        $columns = $this->mapHeader($header);

        foreach (self::REQUIRED as $required) {
            if (!isset($columns[$required])) {
                return new ReadResult([], [new RowError(1, "manca la colonna «{$required}»")]);
            }
        }
        if (!isset($columns['price']) && !isset($columns['quantity'])) {
            return new ReadResult([], [new RowError(1, 'serve almeno una colonna fra prezzo e quantità')]);
        }

        $rows = [];
        $errors = [];
        $seen = [];
        foreach (array_slice($lines, 1) as $i => $line) {
            $lineNumber = $i + 2;
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, $delimiter, '"', '');
            $reference = trim($cells[$columns['reference']] ?? '');
            if ($reference === '') {
                $errors[] = new RowError($lineNumber, 'riferimento vuoto');
                continue;
            }
            if (isset($seen[$reference])) {
                $errors[] = new RowError($lineNumber, "riferimento «{$reference}» già presente alla riga {$seen[$reference]}");
                continue;
            }
            $seen[$reference] = $lineNumber;

            $price = null;
            if (isset($columns['price'])) {
                $raw = trim($cells[$columns['price']] ?? '');
                if ($raw !== '') {
                    $price = $this->parsePrice($raw);
                    if ($price === null) {
                        $errors[] = new RowError($lineNumber, "prezzo non valido: «{$raw}»");
                        continue;
                    }
                }
            }

            $quantity = null;
            if (isset($columns['quantity'])) {
                $raw = trim($cells[$columns['quantity']] ?? '');
                if ($raw !== '') {
                    if (!preg_match('/^-?\d+$/', $raw)) {
                        $errors[] = new RowError($lineNumber, "quantità non intera: «{$raw}»");
                        continue;
                    }
                    $quantity = (int) $raw;
                }
            }

            if ($price === null && $quantity === null) {
                $errors[] = new RowError($lineNumber, 'né prezzo né quantità: niente da aggiornare');
                continue;
            }
            $rows[] = new Row($lineNumber, $reference, $price, $quantity);
        }

        return new ReadResult($rows, $errors);
    }

    private function stripBom(string $content): string
    {
        return str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content;
    }

    /** Excel italiano salva con «;», altri programmi con «,»: vince chi compare di più nell'intestazione. */
    private function detectDelimiter(string $headerLine): string
    {
        return substr_count($headerLine, ';') >= substr_count($headerLine, ',') ? ';' : ',';
    }

    /** @return array<string,int> nome colonna => posizione */
    private function mapHeader(array $header): array
    {
        $columns = [];
        foreach ($header as $position => $name) {
            $key = mb_strtolower(str_replace(' ', '', trim((string) $name)));
            foreach (array_merge(self::REQUIRED, self::OPTIONAL) as $column) {
                if (in_array($key, self::ALIASES[$column], true) && !isset($columns[$column])) {
                    $columns[$column] = $position;
                }
            }
        }

        return $columns;
    }

    /**
     * Accetta «12,50», «12.50», «1.234,56» e «1,234.56»: il separatore decimale
     * è l'ultimo fra punto e virgola. Prezzi negativi rifiutati.
     */
    private function parsePrice(string $raw): ?float
    {
        $raw = str_replace([' ', '€'], '', $raw);
        if (!preg_match('/^\d[\d.,]*$/', $raw)) {
            return null;
        }
        $lastComma = strrpos($raw, ',');
        $lastDot = strrpos($raw, '.');
        $decimal = ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) ? ',' : '.';
        $thousands = $decimal === ',' ? '.' : ',';
        $normalized = str_replace([$thousands, $decimal], ['', '.'], $raw);
        if (substr_count($normalized, '.') > 1 || !is_numeric($normalized)) {
            return null;
        }

        return round((float) $normalized, 6);
    }
}
