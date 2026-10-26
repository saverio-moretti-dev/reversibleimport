<?php

declare(strict_types=1);

namespace ReversibleImport\Tests;

use PHPUnit\Framework\TestCase;
use ReversibleImport\Change;
use ReversibleImport\CsvReader;
use ReversibleImport\Importer;
use ReversibleImport\Planner;
use ReversibleImport\StalePlanException;

final class ImporterTest extends TestCase
{
    private InMemoryCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = (new InMemoryCatalog())
            ->add(1, 'A1', 10.0, 5)
            ->add(2, 'B2', 20.0, 0)
            ->add(3, 'C3', 30.0, 7);
    }

    private function plan(string $csv)
    {
        return (new Planner($this->catalog))->plan((new CsvReader())->read($csv)->rows);
    }

    public function testAnteprimaElencaSoloICampiCheCambiano(): void
    {
        $plan = $this->plan("sku;prezzo;qta\nA1;10;6\nB2;20;0\nZZ;1;1\n");

        self::assertCount(1, $plan->changes);
        self::assertSame(Change::QUANTITY, $plan->changes[0]->field);
        self::assertSame([5, 6], [$plan->changes[0]->before, $plan->changes[0]->after]);
        self::assertSame(['ZZ'], $plan->unknownReferences);
        self::assertSame(1, $plan->unchanged);
    }

    public function testAnteprimaNonScriveNiente(): void
    {
        $prima = $this->catalog->products;
        $this->plan("sku;prezzo\nA1;99\n");

        self::assertEquals($prima, $this->catalog->products);
    }

    public function testImportPoiRitornoIndietroRiportaTuttoComePrima(): void
    {
        $prima = $this->catalog->products;
        $importer = new Importer($this->catalog);

        $batch = $importer->apply($this->plan("sku;prezzo;qta\nA1;11,5;1\nC3;29;7\n"), 'listino.csv');
        self::assertSame(11.5, $this->catalog->products[1]->price);
        self::assertSame(29.0, $this->catalog->products[3]->price);

        $esito = $importer->undo($batch);
        self::assertCount(3, $esito->restored);
        self::assertSame([], $esito->skipped);
        self::assertEquals($prima, $this->catalog->products);
    }

    public function testGuastoAMetaNonLasciaNienteAMeta(): void
    {
        $prima = $this->catalog->products;
        $this->catalog->failOnWrite = 3;

        try {
            (new Importer($this->catalog))->apply($this->plan("sku;prezzo\nA1;1\nB2;2\nC3;3\n"), 'x.csv');
            self::fail('il guasto doveva arrivare');
        } catch (\RuntimeException $e) {
            self::assertSame('guasto simulato', $e->getMessage());
        }

        self::assertEquals($prima, $this->catalog->products);
        self::assertSame([], $this->catalog->batches, 'nemmeno la fotografia resta');
        self::assertSame([], $this->catalog->snapshots);
    }

    public function testCatalogoCambiatoDopoLAnteprimaBloccaLImport(): void
    {
        $plan = $this->plan("sku;qta\nA1;9\nB2;9\n");
        $this->catalog->setQuantity(2, 4); // un ordine arriva mentre l'utente guarda l'anteprima

        $this->expectException(StalePlanException::class);
        $this->expectExceptionMessageMatches('/B2/');
        try {
            (new Importer($this->catalog))->apply($plan, 'x.csv');
        } finally {
            self::assertSame(5, $this->catalog->products[1]->quantity, 'A1 non è stato scritto');
        }
    }

    public function testRitornoIndietroSaltaICampiCambiatiDaAltri(): void
    {
        $importer = new Importer($this->catalog);
        $batch = $importer->apply($this->plan("sku;prezzo;qta\nA1;12;10\n"), 'x.csv');

        $this->catalog->setQuantity(1, 8); // dopo l'import un ordine scala 2 pezzi

        $esito = $importer->undo($batch);
        self::assertSame([Change::PRICE], array_map(fn ($c) => $c->field, $esito->restored));
        self::assertSame([Change::QUANTITY], array_map(fn ($c) => $c->field, $esito->skipped));
        self::assertSame(10.0, $this->catalog->products[1]->price, 'il prezzo torna com\'era');
        self::assertSame(8, $this->catalog->products[1]->quantity, 'la vendita non viene cancellata');
    }

    public function testDueRitorniIndietroSullaStessoImportNo(): void
    {
        $importer = new Importer($this->catalog);
        $batch = $importer->apply($this->plan("sku;prezzo\nA1;12\n"), 'x.csv');
        $importer->undo($batch);

        $this->expectExceptionMessageMatches('/già stato riportato indietro/');
        $importer->undo($batch);
    }

    public function testPianoVuotoRifiutato(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Importer($this->catalog))->apply($this->plan("sku;prezzo\nA1;10\n"), 'x.csv');
    }
}
