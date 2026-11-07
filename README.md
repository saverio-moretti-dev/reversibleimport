# reversibleimport

[![tests](https://github.com/saverio-moretti-dev/reversibleimport/actions/workflows/tests.yml/badge.svg)](https://github.com/saverio-moretti-dev/reversibleimport/actions/workflows/tests.yml)

PrestaShop 8.1 module that updates prices and quantities from a CSV file, with a preview and an undo.

It is a reduced version of the import I use at work, written from scratch for public release. The work version belongs to the company and is not here. I wrote this one in October 2026 to have PHP code I can show, which is why the commit history is short.

Italian guide: [LEGGIMI.md](LEGGIMI.md).

## The problem

PrestaShop's built-in CSV import cannot be undone: if the file was wrong, the previous values are gone. If it fails halfway, part of the catalog is written and part is not.

## What it does

1. **Preview.** Reads the file and shows, field by field, today's value and the new one. Lists every invalid row with its line number, references not found, and rows that already match. Writes nothing.
2. **Confirm.** Writes exactly what the preview showed, in a single transaction, together with a snapshot of the previous values. If one write fails, nothing is left half-done. If a value changed between preview and confirm (an order came in, for example), the import stops and asks for a new preview.
3. **Undo.** From the list of imports, one click puts the fields back to their previous values. A field changed by someone else after the import (a sale, a manual fix) is left as it is and reported: reverting it would erase work the import did not do.

File format: columns `reference; price; quantity` (also accepts `riferimento`, `sku`, `prezzo`, `qta`…), separator `;` or `,`, prices like `12,50`, `1.234,56` or `1,234.56`. Prices exclude VAT, as in the back office.

## Limits

- Single shop. Only products without combinations: for products with combinations the quantity is the sum of the variants and has to be handled separately. A reference shared by several products is skipped.
- Price and quantity only.
- Writes with direct SQL instead of `Product::save()`, so it does not rewrite the whole product or fire hooks inside a transaction that can still be rolled back. The `actionUpdateQuantity` hook fires after the commit, once per product. Other modules listening to product saves are not notified of price changes.
- **Status:** version 0.1.0. Tested on PrestaShop 8.1.7 with the demo products (see below). Uploading the file from the back office page has not been tested in a browser yet: the page is only checked at template level.

## How it is built

```
src/CsvReader.php                    reads and validates the file
src/Planner.php                      preview: file vs catalog
src/Importer.php                     transactional write and undo
src/CatalogRepository.php            what the logic needs from the catalog
src/PrestaShopCatalogRepository.php  the PrestaShop catalog (Db, pSQL, int casts)
controllers/admin/                   back office page (Catalog > Import reversibile)
tests/                               tests against an in-memory catalog that simulates transactions and failures
```

The logic does not depend on PrestaShop: it talks to an interface. That way it can be tested without a shop, including the "failure on the third write" case.

Code written mostly with Claude Code; the logic, the cases to test and the verification are mine.

## Tests

```
composer install
vendor/bin/phpunit
```

Last run (7 November 2026): 21 tests, 48 assertions, green on PHP 8.1 and PHP 8.5. GitHub Actions runs the same tests on both versions at every push. The unit tests do not need PrestaShop; the real-shop run below uses PHP 8.1, like PrestaShop 8.1.

### Run on a real PrestaShop

`tests/integration/run.php` runs inside a test shop (never in production: it writes to the catalog). It uses the demo products and checks 19 things: tab and tables created, preview writes nothing, price written to `product` and `product_shop`, quantity to `stock_available`, snapshot, undo with a sale in between (the sale is kept), import blocked when the preview is stale, back office page. Result on PrestaShop 8.1.7, PHP 8.1, MariaDB: 19/19 (26 October 2026). Uninstalling leaves no tables behind. The row lock in the undo (7 November) is covered by the unit tests and has not been re-run on the real shop yet.

```
php modules/reversibleimport/tests/integration/run.php
```

The real-shop run found two bugs the in-memory tests could not see:
- `Db::getRow()` appends `LIMIT 1` to the query: after a `FOR UPDATE` the query is no longer valid;
- PrestaShop caches the quantity it reads for the rest of the request: after a direct SQL write the cache has to be cleared, otherwise modules notified by the hook read the old value.

## Installation

Copy the folder to `modules/reversibleimport` and install the module from the back office. Uninstalling deletes the import history.

MIT license.
