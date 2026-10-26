<?php
/**
 * Prova su un PrestaShop vero, con i prodotti demo. Da lanciare con la CLI
 * dentro un negozio di prova (mai in produzione: scrive sul catalogo):
 *
 *   php modules/reversibleimport/tests/integration/run.php
 *
 * Esce con codice 1 al primo controllo fallito.
 */
require dirname(__DIR__, 4) . '/config/config.inc.php';

use ReversibleImport\CsvReader;
use ReversibleImport\Importer;
use ReversibleImport\Planner;
use ReversibleImport\PrestaShopCatalogRepository;
use ReversibleImport\StalePlanException;

if (!Module::isInstalled('reversibleimport')) {
    fwrite(STDERR, "modulo non installato\n");
    exit(1);
}
Module::getInstanceByName('reversibleimport'); // carica le classi in src/

$failures = 0;
function check(bool $ok, string $what): void
{
    global $failures;
    echo ($ok ? 'OK   ' : 'FAIL ') . $what . "\n";
    $failures += $ok ? 0 : 1;
}

$db = Db::getInstance();
$shopId = (int) Configuration::get('PS_SHOP_DEFAULT');
$p = _DB_PREFIX_;

check((int) Tab::getIdFromClassName('AdminReversibleImport') > 0, 'scheda nel back office creata');
check(count($db->executeS("SHOW TABLES LIKE '{$p}reversibleimport_%'")) === 2, 'tabelle create');

// Due prodotti demo senza combinazioni, con riferimento unico.
$demo = $db->executeS("SELECT p.id_product, p.reference FROM {$p}product p
    WHERE p.reference <> '' AND NOT EXISTS (SELECT 1 FROM {$p}product_attribute pa WHERE pa.id_product = p.id_product)
    GROUP BY p.reference HAVING COUNT(*) = 1 ORDER BY p.id_product LIMIT 2");
check(count($demo) === 2, 'trovati 2 prodotti demo senza combinazioni');
[$a, $b] = $demo;

$values = function (int $id) use ($db, $p, $shopId): array {
    return [
        (float) $db->getValue("SELECT price FROM {$p}product_shop WHERE id_product = $id AND id_shop = $shopId"),
        (float) $db->getValue("SELECT price FROM {$p}product WHERE id_product = $id"),
        (int) $db->getValue("SELECT quantity FROM {$p}stock_available WHERE id_product = $id AND id_product_attribute = 0 AND id_shop = $shopId"),
    ];
};
$beforeA = $values((int) $a['id_product']);
$beforeB = $values((int) $b['id_product']);

// Un riferimento con combinazioni deve risultare «non trovato».
$withCombination = (string) $db->getValue("SELECT p.reference FROM {$p}product p
    INNER JOIN {$p}product_attribute pa ON pa.id_product = p.id_product WHERE p.reference <> ''");

$repo = new PrestaShopCatalogRepository($shopId, 1);
$csv = "riferimento;prezzo;quantità\n{$a['reference']};123,45;77\n{$b['reference']};;5\n{$withCombination};1;1\nNON-ESISTE;1;1\n";
$read = (new CsvReader())->read($csv);
$plan = (new Planner($repo))->plan($read->rows);

check(count($plan->changes) === 3, 'anteprima: 3 campi da cambiare (prezzo+quantità A, quantità B)');
check(in_array('NON-ESISTE', $plan->unknownReferences, true), 'anteprima: riferimento inesistente segnalato');
check($withCombination === '' || in_array($withCombination, $plan->unknownReferences, true), 'anteprima: prodotto con combinazioni escluso');
check($values((int) $a['id_product']) === $beforeA, 'anteprima: niente scritto');

$importer = new Importer($repo);
$batch = $importer->apply($plan, 'prova.csv');
$afterA = $values((int) $a['id_product']);
check($afterA === [123.45, 123.45, 77], 'import: prezzo (product e product_shop) e quantità scritti');
check($values((int) $b['id_product'])[2] === 5, 'import: quantità B scritta');
check((int) StockAvailable::getQuantityAvailableByProduct((int) $a['id_product'], 0, $shopId) === 77, 'import: PrestaShop legge già la quantità nuova (cache pulita)');
check((int) $db->getValue("SELECT COUNT(*) FROM {$p}reversibleimport_snapshot WHERE id_batch = $batch") === 3, 'import: fotografia di 3 campi');

// Una vendita dopo l'import: il ritorno indietro non deve cancellarla.
StockAvailable::setQuantity((int) $b['id_product'], 0, 4, $shopId);
$undo = $importer->undo($batch);
check(count($undo->restored) === 2 && count($undo->skipped) === 1, 'ritorno indietro: 2 campi riportati, 1 saltato');
check($values((int) $a['id_product']) === $beforeA, 'ritorno indietro: A come prima');
check($values((int) $b['id_product'])[2] === 4, 'ritorno indietro: la vendita su B resta');

// Catalogo cambiato dopo l'anteprima: l'import non scrive niente.
$plan = (new Planner($repo))->plan((new CsvReader())->read("sku;prezzo;qta\n{$a['reference']};50;50\n")->rows);
StockAvailable::setQuantity((int) $a['id_product'], 0, $beforeA[2] + 1, $shopId);
try {
    $importer->apply($plan, 'vecchia-anteprima.csv');
    check(false, 'anteprima superata: import bloccato');
} catch (StalePlanException $e) {
    check(true, 'anteprima superata: import bloccato');
}
check($values((int) $a['id_product'])[0] === $beforeA[0], 'anteprima superata: prezzo non toccato');
check((int) $db->getValue("SELECT COUNT(*) FROM {$p}reversibleimport_batch WHERE file_name = 'vecchia-anteprima.csv'") === 0, 'anteprima superata: nessun import registrato');

StockAvailable::setQuantity((int) $a['id_product'], 0, $beforeA[2], $shopId);
StockAvailable::setQuantity((int) $b['id_product'], 0, $beforeB[2], $shopId);

// La pagina del back office: il template si compila con un'anteprima e con la storia vere.
$plan = (new Planner($repo))->plan($read->rows);
$smarty = Context::getContext()->smarty;
$smarty->assign([
    'action' => 'index.php?controller=AdminReversibleImport',
    'preview' => [
        'file_name' => 'prova.csv',
        'errors' => (new CsvReader())->read("sku;prezzo\nX;abc\n")->errors,
        'unknown' => $plan->unknownReferences,
        'unchanged' => $plan->unchanged,
        'change_count' => count($plan->changes),
        'changes' => $plan->changes,
        'plan_token' => str_repeat('a', 32),
    ],
    'batches' => $repo->listBatches(),
]);
$html = $smarty->fetch(_PS_MODULE_DIR_ . 'reversibleimport/views/templates/admin/main.tpl');
check(str_contains($html, '123.45') && str_contains($html, 'Torna indietro') === false && str_contains($html, 'riportato indietro il'), 'pagina: anteprima e storia mostrate');
check(str_contains($html, 'Riga 2: prezzo non valido'), 'pagina: errori con numero di riga');

echo $failures === 0 ? "\nTUTTO OK\n" : "\n$failures CONTROLLI FALLITI\n";
exit($failures === 0 ? 0 : 1);
