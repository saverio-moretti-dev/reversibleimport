<?php

declare(strict_types=1);

namespace ReversibleImport\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReversibleImport\CsvReader;

final class CsvReaderTest extends TestCase
{
    public function testExcelItalianoConPuntoEVirgolaEBom(): void
    {
        $result = (new CsvReader())->read("\xEF\xBB\xBFRiferimento;Prezzo;Quantità\r\nA1;12,50;3\r\nB2;1.234,56;0\r\n");

        self::assertSame([], $result->errors);
        self::assertCount(2, $result->rows);
        self::assertSame(12.5, $result->rows[0]->price);
        self::assertSame(1234.56, $result->rows[1]->price);
        self::assertSame(0, $result->rows[1]->quantity);
    }

    public function testVirgolaComeSeparatoreEIntestazioniInglesi(): void
    {
        $result = (new CsvReader())->read("reference,price\nA1,\"1,234.56\"\n");

        self::assertSame(1234.56, $result->rows[0]->price);
        self::assertNull($result->rows[0]->quantity);
    }

    public function testRaccoglieTuttiGliErroriConIlNumeroDiRiga(): void
    {
        $csv = "sku;prezzo;qta\nA1;abc;1\n;10;1\nA3;10;2,5\nA4;;\nA1;9;9\nA6;5;5\n";
        $result = (new CsvReader())->read($csv);

        self::assertSame([2, 3, 4, 5, 6], array_map(fn ($e) => $e->line, $result->errors));
        self::assertCount(1, $result->rows, 'le righe buone passano comunque');
        self::assertSame('A6', $result->rows[0]->reference);
    }

    public function testRiferimentoDoppioSegnalaLaPrimaRiga(): void
    {
        $result = (new CsvReader())->read("sku;prezzo\nA1;1\nA2;2\nA1;3\n");

        self::assertStringContainsString('riga 2', $result->errors[0]->message);
    }

    #[DataProvider('intestazioniSbagliate')]
    public function testIntestazioneSenzaColonneUtili(string $csv, string $atteso): void
    {
        $result = (new CsvReader())->read($csv);

        self::assertSame([], $result->rows);
        self::assertStringContainsString($atteso, $result->errors[0]->message);
    }

    public static function intestazioniSbagliate(): array
    {
        return [
            'file vuoto' => ['', 'vuoto'],
            'senza riferimento' => ["prezzo;qta\n1;1\n", 'reference'],
            'senza prezzo né quantità' => ["sku;nome\nA1;x\n", 'almeno una colonna'],
        ];
    }

    #[DataProvider('prezziNonValidi')]
    public function testPrezzoNonValido(string $prezzo): void
    {
        $result = (new CsvReader())->read("sku;prezzo\nA1;$prezzo\n");

        self::assertCount(1, $result->errors);
    }

    public static function prezziNonValidi(): array
    {
        return [['-5'], ['12,50,3'], ['1.2.3'], ['dieci'], ['1e3']];
    }
}
