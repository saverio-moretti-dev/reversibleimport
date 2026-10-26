<?php
/**
 * Import CSV di prezzi e quantità con anteprima e ritorno indietro.
 *
 * @license MIT
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

// Nessuna dipendenza a runtime: Composer serve solo per i test.
foreach (['Model', 'CatalogRepository', 'CsvReader', 'Planner', 'Importer', 'PrestaShopCatalogRepository'] as $file) {
    require_once __DIR__ . '/src/' . $file . '.php';
}

class ReversibleImport extends Module
{
    public function __construct()
    {
        $this->name = 'reversibleimport';
        $this->tab = 'administration';
        $this->version = '0.1.0';
        $this->author = 'Saverio Moretti';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.1.0', 'max' => '8.99.99'];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Import reversibile');
        $this->description = $this->l('Import CSV di prezzi e quantità con anteprima, scrittura in transazione e ritorno indietro.');
    }

    public function install(): bool
    {
        return parent::install() && $this->createTables() && $this->installTab();
    }

    /** La disinstallazione toglie la storia degli import: dopo non si torna più indietro. */
    public function uninstall(): bool
    {
        return $this->uninstallTab() && $this->dropTables() && parent::uninstall();
    }

    public function getContent(): void
    {
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminReversibleImport'));
    }

    private function createTables(): bool
    {
        $engine = _MYSQL_ENGINE_;
        $prefix = _DB_PREFIX_;

        return Db::getInstance()->execute("
            CREATE TABLE IF NOT EXISTS `{$prefix}reversibleimport_batch` (
                `id_batch` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `file_name` VARCHAR(255) NOT NULL,
                `change_count` INT UNSIGNED NOT NULL,
                `id_employee` INT UNSIGNED NOT NULL,
                `id_shop` INT UNSIGNED NOT NULL,
                `date_add` DATETIME NOT NULL,
                `date_undone` DATETIME NULL,
                PRIMARY KEY (`id_batch`),
                KEY `id_shop` (`id_shop`)
            ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4")
            && Db::getInstance()->execute("
            CREATE TABLE IF NOT EXISTS `{$prefix}reversibleimport_snapshot` (
                `id_snapshot` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_batch` INT UNSIGNED NOT NULL,
                `id_product` INT UNSIGNED NOT NULL,
                `reference` VARCHAR(64) NOT NULL,
                `field` VARCHAR(16) NOT NULL,
                `value_before` VARCHAR(32) NOT NULL,
                `value_after` VARCHAR(32) NOT NULL,
                PRIMARY KEY (`id_snapshot`),
                KEY `id_batch` (`id_batch`)
            ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4");
    }

    private function dropTables(): bool
    {
        return Db::getInstance()->execute(
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'reversibleimport_snapshot`, `' . _DB_PREFIX_ . 'reversibleimport_batch`'
        );
    }

    private function installTab(): bool
    {
        $tab = new Tab();
        $tab->class_name = 'AdminReversibleImport';
        $tab->module = $this->name;
        $tab->id_parent = (int) Tab::getIdFromClassName('AdminCatalog');
        $tab->active = true;
        foreach (Language::getLanguages(false) as $language) {
            $tab->name[(int) $language['id_lang']] = 'Import reversibile';
        }

        return (bool) $tab->add();
    }

    private function uninstallTab(): bool
    {
        $idTab = (int) Tab::getIdFromClassName('AdminReversibleImport');

        return $idTab === 0 || (new Tab($idTab))->delete();
    }
}
