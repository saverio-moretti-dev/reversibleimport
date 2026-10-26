<?php
/**
 * Tre passi: carica il file e guarda l'anteprima; conferma; se serve, torna indietro.
 *
 * Fra anteprima e conferma il piano resta in un file temporaneo: si scrive
 * esattamente ciò che l'utente ha visto, e se il catalogo nel frattempo è
 * cambiato l'import si ferma (StalePlanException) invece di scrivere su valori
 * che l'anteprima non mostrava.
 *
 * @license MIT
 */
use ReversibleImport\Change;
use ReversibleImport\CsvReader;
use ReversibleImport\Importer;
use ReversibleImport\Plan;
use ReversibleImport\Planner;
use ReversibleImport\PrestaShopCatalogRepository;
use ReversibleImport\StalePlanException;

class AdminReversibleImportController extends ModuleAdminController
{
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const PREVIEW_ROWS = 200;

    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function initContent(): void
    {
        parent::initContent();

        $repository = $this->repository();
        $view = ['action' => self::$currentIndex . '&token=' . $this->token, 'preview' => null];

        try {
            if (Tools::isSubmit('submitPreview')) {
                $view['preview'] = $this->preview($repository);
            } elseif (Tools::isSubmit('submitApply')) {
                $this->apply($repository);
            } elseif (Tools::isSubmit('submitUndo')) {
                $this->undo($repository);
            }
        } catch (StalePlanException $e) {
            $this->errors[] = $e->getMessage();
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('reversibleimport: ' . $e->getMessage(), 3);
            $this->errors[] = $this->l('Errore, nessuna modifica scritta:') . ' ' . $e->getMessage();
        }

        $view['batches'] = $repository->listBatches();
        $this->context->smarty->assign($view);
        $this->content .= $this->context->smarty->fetch($this->module->getLocalPath() . 'views/templates/admin/main.tpl');
        $this->context->smarty->assign('content', $this->content);
    }

    private function preview(PrestaShopCatalogRepository $repository): array
    {
        $file = $_FILES['csv'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new \RuntimeException($this->l('Nessun file caricato.'));
        }
        if ($file['size'] > self::MAX_BYTES) {
            throw new \RuntimeException($this->l('File oltre 5 MB.'));
        }

        $read = (new CsvReader())->read((string) file_get_contents($file['tmp_name']));
        $plan = (new Planner($repository))->plan($read->rows);
        $token = $plan->changes === [] ? null : $this->savePlan($plan, (string) $file['name']);

        return [
            'file_name' => $file['name'],
            'errors' => $read->errors,
            'unknown' => $plan->unknownReferences,
            'unchanged' => $plan->unchanged,
            'change_count' => count($plan->changes),
            'changes' => array_slice($plan->changes, 0, self::PREVIEW_ROWS),
            'plan_token' => $token,
        ];
    }

    private function apply(PrestaShopCatalogRepository $repository): void
    {
        $this->requireEdit();
        [$plan, $fileName] = $this->loadPlan((string) Tools::getValue('plan_token'));
        $batchId = (new Importer($repository))->apply($plan, $fileName);
        $this->confirmations[] = sprintf($this->l('Import n. %d scritto: %d modifiche.'), $batchId, count($plan->changes));
    }

    private function undo(PrestaShopCatalogRepository $repository): void
    {
        $this->requireEdit();
        $result = (new Importer($repository))->undo((int) Tools::getValue('id_batch'));
        $this->confirmations[] = sprintf($this->l('Riportati indietro %d campi.'), count($result->restored));
        foreach ($result->skipped as $change) {
            $this->warnings[] = sprintf(
                $this->l('%s: %s cambiato dopo l\'import, lasciato com\'è.'),
                $change->reference,
                $change->field === Change::PRICE ? $this->l('prezzo') : $this->l('quantità')
            );
        }
    }

    private function requireEdit(): void
    {
        if (!$this->access('edit')) {
            throw new \RuntimeException($this->l('Non hai il permesso di modificare il catalogo.'));
        }
    }

    private function repository(): PrestaShopCatalogRepository
    {
        return new PrestaShopCatalogRepository((int) $this->context->shop->id, (int) $this->context->employee->id);
    }

    private function planPath(string $token): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            throw new \RuntimeException($this->l('Anteprima non valida, ricarica il file.'));
        }

        return _PS_CACHE_DIR_ . 'reversibleimport/' . (int) $this->context->employee->id . '-' . $token . '.json';
    }

    private function savePlan(Plan $plan, string $fileName): string
    {
        $token = bin2hex(random_bytes(16));
        $path = $this->planPath($token);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0750, true);
        }
        $changes = array_map(fn (Change $c) => [$c->productId, $c->reference, $c->field, $c->before, $c->after], $plan->changes);
        file_put_contents($path, json_encode(['file' => $fileName, 'changes' => $changes], JSON_THROW_ON_ERROR));

        return $token;
    }

    /** @return array{0: Plan, 1: string} Il file si cancella subito: una conferma, un import. */
    private function loadPlan(string $token): array
    {
        $path = $this->planPath($token);
        if (!is_file($path)) {
            throw new \RuntimeException($this->l('Anteprima scaduta o già usata, ricarica il file.'));
        }
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        unlink($path);

        $changes = array_map(fn (array $c) => new Change((int) $c[0], (string) $c[1], (string) $c[2], $c[3], $c[4]), $data['changes']);

        return [new Plan($changes, [], 0), (string) $data['file']];
    }
}
