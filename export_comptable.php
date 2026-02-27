<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class Export_Comptable extends Module
{
    public function __construct()
    {
        $this->name = 'export_comptable';
        $this->tab = 'administration';
        $this->version = '1.0.1';
        $this->author = 'Consogarage';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Export comptable');
        $this->description = $this->l('Tableau (4 lignes par facture) + filtre date + export CSV (séparateur ;).');
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
    }

    public function install()
    {
        $ok = parent::install() && $this->installTab();
        $seedScript = dirname(__FILE__) . '/install_seed_id_as400.php';
        if ($ok && file_exists($seedScript)) {
            include($seedScript);
        }
        return $ok;
    }

    public function uninstall()
    {
        return $this->uninstallTab() && parent::uninstall();
    }

    protected function installTab()
    {
        $id_parent = (int) Tab::getIdFromClassName('AdminParentOrders');
        if (!$id_parent) {
            $id_parent = 0;
        }

        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = 'AdminExportComptable';
        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[$lang['id_lang']] = $this->l('Export comptable');
        }
        $tab->id_parent = $id_parent;
        $tab->module = $this->name;

        return (bool) $tab->add();
    }

    protected function uninstallTab()
    {
        $id_tab = (int) Tab::getIdFromClassName('AdminExportComptable');
        if ($id_tab) {
            $tab = new Tab($id_tab);
            return (bool) $tab->delete();
        }
        return true;
    }
}
