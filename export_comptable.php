<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class Export_Comptable extends Module
{
    const CONF_CRON_EMAILS = 'EXPORT_COMPTABLE_CRON_EMAILS';

    // Destinataires par défaut de l'export quotidien (demande de Ramazan Soylu, 05/11/2026)
    const DEFAULT_CRON_EMAILS = "ncarpanoni@ital-express.fr\ncrafflin@ital-express.fr\nrsoylu@ital-express.fr";

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
        $ok = parent::install() && $this->installTab()
            && Configuration::updateValue(self::CONF_CRON_EMAILS, self::DEFAULT_CRON_EMAILS);
        $seedScript = dirname(__FILE__) . '/install_seed_id_as400.php';
        if ($ok && file_exists($seedScript)) {
            include($seedScript);
        }
        return $ok;
    }

    public function uninstall()
    {
        Configuration::deleteByName(self::CONF_CRON_EMAILS);

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

    /**
     * Page « Configurer » : destinataires de l'export comptable quotidien (cron).
     */
    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitExportComptableEmails')) {
            $raw = (string) Tools::getValue(self::CONF_CRON_EMAILS);
            $emails = self::parseEmails($raw, $invalid);

            if (!empty($invalid)) {
                $output .= $this->displayError(
                    $this->l('Adresse(s) invalide(s) : ') . implode(', ', array_map('htmlspecialchars', $invalid))
                );
            } else {
                Configuration::updateValue(self::CONF_CRON_EMAILS, implode("\n", $emails));
                $output .= $this->displayConfirmation(
                    empty($emails)
                        ? $this->l('Aucun destinataire : l\'export ne sera plus envoyé par e-mail.')
                        : $this->l('Destinataires enregistrés.')
                );
            }
        }

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->submit_action = 'submitExportComptableEmails';
        $helper->fields_value[self::CONF_CRON_EMAILS] = Tools::getValue(
            self::CONF_CRON_EMAILS,
            implode("\n", self::getCronEmails())
        );

        $form = [
            'form' => [
                'legend' => ['title' => $this->l('Envoi de l\'export quotidien par e-mail')],
                'input' => [[
                    'type' => 'textarea',
                    'label' => $this->l('Destinataires'),
                    'name' => self::CONF_CRON_EMAILS,
                    'rows' => 5,
                    'desc' => $this->l('Une adresse par ligne (ou séparées par des virgules). Le fichier de la veille est envoyé en pièce jointe après chaque passage du cron. Laisser vide pour ne rien envoyer.'),
                ]],
                'submit' => ['title' => $this->l('Enregistrer')],
            ],
        ];

        return $output . $helper->generateForm([$form]);
    }

    /**
     * @return string[] Adresses valides configurées (valeur par défaut si jamais configuré)
     */
    public static function getCronEmails()
    {
        $raw = Configuration::get(self::CONF_CRON_EMAILS);
        if ($raw === false) {
            $raw = self::DEFAULT_CRON_EMAILS;
        }

        return self::parseEmails((string) $raw, $invalid);
    }

    /**
     * @param string $raw
     * @param array  $invalid Adresses rejetées (sortie)
     * @return string[]
     */
    public static function parseEmails($raw, &$invalid = [])
    {
        $invalid = [];
        $emails = [];
        foreach (preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $email) {
            if (Validate::isEmail($email)) {
                $emails[Tools::strtolower($email)] = $email;
            } else {
                $invalid[] = $email;
            }
        }

        return array_values($emails);
    }

    /**
     * Envoie le fichier d'export aux destinataires configurés.
     *
     * @param string $filepath
     * @param string $date      YYYY-MM-DD (jour exporté)
     * @param int    $nbRows    Nombre de groupes de lignes (factures et avoirs)
     * @return bool|null null si aucun destinataire, sinon succès de l'envoi
     */
    public function sendExportByEmail($filepath, $date, $nbRows)
    {
        $emails = self::getCronEmails();
        if (empty($emails)) {
            return null;
        }

        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $dateFr = date('d/m/Y', strtotime($date));

        $sent = Mail::send(
            $idLang,
            'export_comptable_cron',
            sprintf('Export comptable du %s', $dateFr),
            [
                '{export_date}' => $dateFr,
                '{nb_rows}' => (int) $nbRows,
                '{filename}' => basename($filepath),
            ],
            $emails,
            null,
            null,
            null,
            [
                'content' => file_get_contents($filepath),
                'name' => basename($filepath),
                'mime' => 'text/csv',
            ],
            null,
            _PS_MODULE_DIR_ . $this->name . '/mails/',
            false
        );

        return (bool) $sent;
    }
}
