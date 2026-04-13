<?php
use PrestaShop\PrestaShop\Adapter\SymfonyContainer;

class AdminExportComptableController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();

        $this->meta_title = $this->module->l('Export comptable', 'AdminExportComptableController');
    }

    public function initContent()
    {
        parent::initContent();

        // Filtres
        $date_from = Tools::getValue('date_from');
        $date_to = Tools::getValue('date_to');
        $exportCsv = (bool) Tools::getValue('export_csv');
        $exportXlsx = (bool) Tools::getValue('export_xlsx');

        // Données (tableau groupé : chaque élément = lignes d'une facture)
        $rows = $this->getAccountingRows($date_from, $date_to);

        if ($exportCsv) {
            $this->exportCsv($rows);
            exit;
        }

        if ($exportXlsx) {
            $this->exportXlsx($rows);
            exit;
        }

        $this->context->smarty->assign([
            'date_from' => $date_from,
            'date_to' => $date_to,
            'rows' => $rows,
            'headers' => $this->getHeaders(),
            'token' => $this->token,
        ]);

        $this->setTemplate('/export.tpl');
    }

    protected function getHeaders()
    {
        return [
            [
                'Type d’enregistrement (E,A)',
                'Code journal',
                'N° écriture',
                'N° pièce',
                'Date pièce',
                'Libellé',
                'Date échéance',
                'Code nature',
                'Racine compte collectif',
                'Montant en euros',
                'Code débit/crédit (C,D)',
                'Compte général',
                'Date (date facture ?)',
                'Code lettrage',
                'Date lettrage',
                'Compte auxiliaire',
                'Code nature tiers (C,F,A,Blanc)',
                'Code trésorerie',
                'N° relance',
                'date valeur',
                'Référence document',
                'N° de séquence',
                'Code section (axe anal.1)',
                'Code affaire (axe anal. 2)',
                'Code destination (axe anal. 2)',
                'Quantité analytique',
                'Montant en devises',
                'Code devise ISO',
                'Taux de la devise',
                'Mode de paiement',
                'Bon à payer',
                'Code banque affectation',
                'Echéance escomptable',
                'Zone texte libre',
                'Ecriture modifiable (L,I,blanc)',
                'Date création',
                'Heure création'
            ],
            [
                'TYPE',
                'JNAL',
                'NECR',
                'NPIE',
                'DATP',
                'LIBE',
                'DATH',
                'CNPI',
                'RACI',
                'MONT',
                'CODC',
                'CPTG',
                'DATE',
                'CLET',
                'DATL',
                'CPTA',
                'CNAT',
                'CTRE',
                'NORL',
                'DATV',
                'REFD',
                'NECA',
                'CSEC',
                'CAFF',
                'CDES',
                'QTUE',
                'MTDV',
                'CODV',
                'TXDV',
                'MOPM',
                'BONP',
                'BQAF',
                'ECES',
                'TXTL',
                'ECRM',
                'DATK',
                'HEUK'
            ],
        ];
    }


    protected function getAccountingRows($date_from, $date_to)
    {
        $groups = [];

        $invoiceGroups = $this->getInvoiceRows($date_from, $date_to);
        $groups = array_merge($groups, $invoiceGroups);

        $creditSlipGroups = $this->getCreditSlipRows($date_from, $date_to);
        $groups = array_merge($groups, $creditSlipGroups);

        return $groups;
    }

    protected function getInvoiceRows($date_from, $date_to)
    {

        $whereParts = ['oi.number > 0'];
        if ($date_from) {
            $whereParts[] = "DATE(oi.date_add) >= '" . pSQL($date_from) . "'";
        }
        if ($date_to) {
            $whereParts[] = "DATE(oi.date_add) <= '" . pSQL($date_to) . "'";
        }
        $where = ' WHERE ' . implode(' AND ', $whereParts);

        $orderBy = ' ORDER BY oi.date_add DESC ';
        $limit = (!$date_from && !$date_to) ? ' LIMIT 100 ' : '';

        $sql = '
            SELECT
                oi.id_order_invoice,
                oi.number AS invoice_number,
                oi.date_add AS invoice_date,
                o.id_order,
                c.id_customer,
                as4.id_as400 AS id_as400true,
                c.firstname, c.lastname, c.company,
                a.id_country,
                country.iso_code AS country_iso,
                country_delivery.iso_code AS country_iso_delivery,
                oi.total_paid_tax_incl,
                oi.total_paid_tax_excl,
                oi.total_products       AS total_products_ht,
                oi.total_shipping_tax_excl AS shipping_ht,
                op.payment_method
            FROM ' . _DB_PREFIX_ . 'order_invoice oi
            INNER JOIN ' . _DB_PREFIX_ . 'orders o    ON (o.id_order = oi.id_order)
            INNER JOIN ' . _DB_PREFIX_ . 'customer c  ON (c.id_customer = o.id_customer)
            INNER JOIN ' . _DB_PREFIX_ . 'address a   ON (a.id_address = o.id_address_invoice)
            INNER JOIN ' . _DB_PREFIX_ . 'country country ON (country.id_country = a.id_country)
            INNER JOIN ' . _DB_PREFIX_ . 'address a_delivery ON (a_delivery.id_address = o.id_address_delivery)
            INNER JOIN ' . _DB_PREFIX_ . 'country country_delivery ON (country_delivery.id_country = a_delivery.id_country)
            LEFT JOIN ' . _DB_PREFIX_ . 'order_payment op ON (op.order_reference = o.reference)
            LEFT JOIN ' . _DB_PREFIX_ . 'export_comptable_id_as400 as4 ON (as4.id_customer = c.id_customer)
            ' . $where . $orderBy . $limit;

        $invoices = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);

        $groups = [];
        foreach ($invoices as $inv) {
            $invoiceRows = [];

            $invoiceNumber = (string) $inv['invoice_number'];
            $invoiceDate = new DateTime($inv['invoice_date']);
            $dateStr = $invoiceDate->format('d/m/y');
            $isFrance = (strtoupper((string) $inv['country_iso']) === 'FR')
                || (strtoupper((string) $inv['country_iso_delivery']) === 'FR');

            $label = trim($inv['firstname'] . ' ' . $inv['lastname']);
            if (!empty($inv['company'])) {
                $label .= ' - ' . $inv['company'];
            }
            $label = mb_strtoupper($label, 'UTF-8');
            require_once _PS_MODULE_DIR_ . 'export_comptable/ExportComptableTools.php';
            $label = ExportComptableTools::cleanLabel($label);

            if (isset($inv['id_as400true']) && $inv['id_as400true'] !== '' && $inv['id_as400true'] !== null) {
                $compteClient = str_pad($inv['id_as400true'], 5, '0', STR_PAD_LEFT);
            } else {
                $compteClient = str_pad($inv['id_customer'], 5, '0', STR_PAD_LEFT);
            }

            $total_ttc = (float) $inv['total_paid_tax_incl'];
            $total_ht_articles = (float) $inv['total_products_ht'];
            $total_ht_shipping = (float) $inv['shipping_ht'];
            $total_consigne = $this->getConsigneTotalForOrder($inv['id_order']);

            $total_taxes = $total_ttc - ($total_ht_articles + $total_ht_shipping);

            $code_journal = '71';

            $dath = '';
            if (!empty($inv['payment_method'])) {
                if ($inv['payment_method'] === 'Paiement en compte') {
                    $echeance = clone $invoiceDate;
                    $echeance->modify('first day of next month');
                    $echeance->modify('last day of this month');
                    $dath = $echeance->format('d/m/Y');
                } elseif ($inv['payment_method'] === 'Transfert bancaire') {
                    $dath = $invoiceDate->format('d/m/Y');
                }
            }

            $paymentMethod = '';
            if (!empty($inv['payment_method'])) {
                $pm = $inv['payment_method'];
                if ($pm === 'Transfert bancaire') {
                    $paymentMethod = 'VI';
                } elseif (strpos($pm, 'Cawl Online Payments') !== false) {
                    $paymentMethod = 'CB';
                } elseif ($pm === 'Paiement en compte') {
                    $paymentMethod = 'TD';
                } elseif ($pm === 'Mandat') {
                    $paymentMethod = 'VI';
                } elseif (stripos($pm, 'chèque') !== false || stripos($pm, 'cheque') !== false) {
                    $paymentMethod = 'CH';
                } else {
                    $paymentMethod = $pm;
                }
            }

            $invoiceRows[] = $this->makeRow([
                'TYPE' => 'E',
                'JNAL' => $code_journal,
                'NECR' => '',
                'NPIE' => $invoiceNumber,
                'DATP' => $invoiceDate->format('d/m/Y'),
                'LIBE' => $label,
                'DATH' => $dath,
                'CNPI' => 'FC',
                'RACI' => '',
                'MONT' => $this->fmt($total_ttc),
                'CODC' => 'D',
                'CPTG' => '411000',
                'DATE' => $dateStr,
                'CLET' => '',
                'DATL' => '',
                'CPTA' => $compteClient,
                'CNAT' => 'C',
                'CTRE' => '',
                'NORL' => '',
                'DATV' => '',
                'REFD' => '',
                'NECA' => '',
                'CSEC' => '',
                'CAFF' => '',
                'CDES' => '',
                'QTUE' => '',
                'MTDV' => '',
                'CODV' => '',
                'TXDV' => '',
                'MOPM' => $paymentMethod,
                'BONP' => '',
                'BQAF' => '',
                'ECES' => '',
                'TXTL' => '',
                'ECRM' => '',
                'DATK' => '',
                'HEUK' => '',
            ]);

            $montant_articles_sans_consigne = $total_ht_articles - $total_consigne;
            $invoiceRows[] = $this->makeRow([
                'TYPE' => 'E',
                'JNAL' => $code_journal,
                'NECR' => '',
                'NPIE' => $invoiceNumber,
                'DATP' => $invoiceDate->format('d/m/Y'),
                'LIBE' => $label,
                'DATH' => $dath,
                'CNPI' => 'FC',
                'RACI' => '',
                'MONT' => $this->fmt($montant_articles_sans_consigne),
                'CODC' => 'C',
                'CPTG' => $isFrance ? '70700300' : '70792300',
                'DATE' => $dateStr,
                'CLET' => '',
                'DATL' => '',
                'CPTA' => '',
                'CNAT' => '',
                'CTRE' => '',
                'NORL' => '',
                'DATV' => '',
                'REFD' => '',
                'NECA' => '',
                'CSEC' => '',
                'CAFF' => '',
                'CDES' => '',
                'QTUE' => '',
                'MTDV' => '',
                'CODV' => '',
                'TXDV' => '',
                'MOPM' => $paymentMethod,
                'BONP' => '',
                'BQAF' => '',
                'ECES' => '',
                'TXTL' => '',
                'ECRM' => '',
                'DATK' => '',
                'HEUK' => '',
            ]);

            if ($total_ht_shipping != 0.0) {
                $invoiceRows[] = $this->makeRow([
                    'TYPE' => 'E',
                    'JNAL' => $code_journal,
                    'NECR' => '',
                    'NPIE' => $invoiceNumber,
                    'DATP' => $invoiceDate->format('d/m/Y'),
                    'LIBE' => $label,
                    'DATH' => $dath,
                    'CNPI' => 'FC',
                    'RACI' => '',
                    'MONT' => $this->fmt($total_ht_shipping),
                    'CODC' => 'C',
                    'CPTG' => $isFrance ? '70850300' : '70852300',
                    'CPTC' => '',
                    'DATE' => $dateStr,
                    'CLET' => '',
                    'DATL' => '',
                    'CPTA' => '',
                    'CNAT' => '',
                    'CTRE' => '',
                    'NORL' => '',
                    'DATV' => '',
                    'REFD' => '',
                    'NECA' => '',
                    'CSEC' => '',
                    'CAFF' => '',
                    'CDES' => '',
                    'QTUE' => '',
                    'MTDV' => '',
                    'CODV' => '',
                    'TXDV' => '',
                    'MOPM' => $paymentMethod,
                    'BONP' => '',
                    'BQAF' => '',
                    'ECES' => '',
                    'TXTL' => '',
                    'ECRM' => '',
                    'DATK' => '',
                    'HEUK' => '',
                ]);
            }

            if ($total_taxes != 0.0 && $inv['total_paid_tax_excl'] != $inv['total_paid_tax_incl']) {
                $invoiceRows[] = $this->makeRow([
                    'TYPE' => 'E',
                    'JNAL' => $code_journal,
                    'NECR' => '',
                    'NPIE' => $invoiceNumber,
                    'DATP' => $invoiceDate->format('d/m/Y'),
                    'LIBE' => $label,
                    'DATH' => $dath,
                    'CNPI' => 'FC',
                    'RACI' => '',
                    'MONT' => $this->fmt($total_taxes),
                    'CODC' => 'C',
                    'CPTG' => '445700',
                    'DATE' => $dateStr,
                    'CLET' => '',
                    'DATL' => '',
                    'CPTA' => '',
                    'CNAT' => '',
                    'CTRE' => '',
                    'NORL' => '',
                    'DATV' => '',
                    'REFD' => '',
                    'NECA' => '',
                    'CSEC' => '',
                    'CAFF' => '',
                    'CDES' => '',
                    'QTUE' => '',
                    'MTDV' => '',
                    'CODV' => '',
                    'TXDV' => '',
                    'MOPM' => $paymentMethod,
                    'BONP' => '',
                    'BQAF' => '',
                    'ECES' => '',
                    'TXTL' => '',
                    'ECRM' => '',
                    'DATK' => '',
                    'HEUK' => '',
                ]);
            }

            if ($total_consigne != 0.0) {
                $invoiceRows[] = $this->makeRow([
                    'TYPE' => 'E',
                    'JNAL' => $code_journal,
                    'NECR' => '',
                    'NPIE' => $invoiceNumber,
                    'DATP' => $invoiceDate->format('d/m/Y'),
                    'LIBE' => $label,
                    'DATH' => $dath,
                    'CNPI' => 'FC',
                    'RACI' => '',
                    'MONT' => $this->fmt($total_consigne),
                    'CODC' => 'C',
                    'CPTG' => $isFrance ? '70710300' : '70712300',
                    'CPTC' => '',
                    'DATE' => $dateStr,
                    'CLET' => '',
                    'DATL' => '',
                    'CPTA' => '',
                    'CNAT' => '',
                    'CTRE' => '',
                    'NORL' => '',
                    'DATV' => '',
                    'REFD' => '',
                    'NECA' => '',
                    'CSEC' => '',
                    'CAFF' => '',
                    'CDES' => '',
                    'QTUE' => '',
                    'MTDV' => '',
                    'CODV' => '',
                    'TXDV' => '',
                    'MOPM' => $paymentMethod,
                    'BONP' => '',
                    'BQAF' => '',
                    'ECES' => '',
                    'TXTL' => '',
                    'ECRM' => '',
                    'DATK' => '',
                    'HEUK' => '',
                ]);
            }

            $groups[] = $invoiceRows;
        }

        return $groups;
    }

    protected function getCreditSlipRows($date_from, $date_to)
    {
        $whereParts = ['os.id_order_slip > 0'];
        if ($date_from) {
            $whereParts[] = "DATE(os.date_add) >= '" . pSQL($date_from) . "'";
        }
        if ($date_to) {
            $whereParts[] = "DATE(os.date_add) <= '" . pSQL($date_to) . "'";
        }
        $where = ' WHERE ' . implode(' AND ', $whereParts);

        $orderBy = ' ORDER BY os.date_add DESC ';
        $limit = (!$date_from && !$date_to) ? ' LIMIT 100 ' : '';

        $sql = '
            SELECT
                os.id_order_slip,
                os.id_order,
                os.date_add AS slip_date,
                os.total_products_tax_incl,
                os.total_products_tax_excl,
                os.total_shipping_tax_incl,
                os.total_shipping_tax_excl,
                os.amount,
                o.id_order,
                c.id_customer,
                as4.id_as400 AS id_as400true,
                c.firstname, c.lastname, c.company,
                a.id_country,
                country.iso_code AS country_iso,
                country_delivery.iso_code AS country_iso_delivery,
                op.payment_method
            FROM ' . _DB_PREFIX_ . 'order_slip os
            INNER JOIN ' . _DB_PREFIX_ . 'orders o ON (o.id_order = os.id_order)
            INNER JOIN ' . _DB_PREFIX_ . 'customer c ON (c.id_customer = o.id_customer)
            INNER JOIN ' . _DB_PREFIX_ . 'address a ON (a.id_address = o.id_address_invoice)
            INNER JOIN ' . _DB_PREFIX_ . 'country country ON (country.id_country = a.id_country)
            INNER JOIN ' . _DB_PREFIX_ . 'address a_delivery ON (a_delivery.id_address = o.id_address_delivery)
            INNER JOIN ' . _DB_PREFIX_ . 'country country_delivery ON (country_delivery.id_country = a_delivery.id_country)
            LEFT JOIN ' . _DB_PREFIX_ . 'order_payment op ON (op.order_reference = o.reference)
            LEFT JOIN ' . _DB_PREFIX_ . 'export_comptable_id_as400 as4 ON (as4.id_customer = c.id_customer)
            ' . $where . $orderBy . $limit;

        $slips = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);

        $groups = [];
        foreach ($slips as $slip) {
            $slipRows = [];

            $slipNumber = 'AV' . str_pad($slip['id_order_slip'], 6, '0', STR_PAD_LEFT);
            $slipDate = new DateTime($slip['slip_date']);
            $dateStr = $slipDate->format('d/m/y');
            $isFrance = (strtoupper((string) $slip['country_iso']) === 'FR')
                || (strtoupper((string) $slip['country_iso_delivery']) === 'FR');

            $label = trim($slip['firstname'] . ' ' . $slip['lastname']);
            if (!empty($slip['company'])) {
                $label .= ' - ' . $slip['company'];
            }
            $label = mb_strtoupper($label, 'UTF-8');
            require_once _PS_MODULE_DIR_ . 'export_comptable/ExportComptableTools.php';
            $label = ExportComptableTools::cleanLabel($label);

            if (isset($slip['id_as400true']) && $slip['id_as400true'] !== '' && $slip['id_as400true'] !== null) {
                $compteClient = str_pad($slip['id_as400true'], 5, '0', STR_PAD_LEFT);
            } else {
                $compteClient = str_pad($slip['id_customer'], 5, '0', STR_PAD_LEFT);
            }

            $total_ttc = (float) $slip['total_products_tax_incl'] + (float) $slip['total_shipping_tax_incl'];
            $total_ht_articles = (float) $slip['total_products_tax_excl'];
            $total_ht_shipping = (float) $slip['total_shipping_tax_excl'];
            $total_consigne = $this->getConsigneTotalForOrder($slip['id_order']);

            $total_taxes = $total_ttc - ($total_ht_articles + $total_ht_shipping);

            $code_journal = '71';

            $dath = '';
            if (!empty($slip['payment_method'])) {
                if ($slip['payment_method'] === 'Paiement en compte') {
                    $echeance = clone $slipDate;
                    $echeance->modify('first day of next month');
                    $echeance->modify('last day of this month');
                    $dath = $echeance->format('d/m/Y');
                } elseif ($slip['payment_method'] === 'Transfert bancaire') {
                    $dath = $slipDate->format('d/m/Y');
                }
            }

            $paymentMethod = '';
            if (!empty($slip['payment_method'])) {
                $pm = $slip['payment_method'];
                if ($pm === 'Transfert bancaire') {
                    $paymentMethod = 'VI';
                } elseif (strpos($pm, 'Cawl Online Payments') !== false) {
                    $paymentMethod = 'CB';
                } elseif ($pm === 'Paiement en compte') {
                    $paymentMethod = 'TD';
                } elseif ($pm === 'Mandat') {
                    $paymentMethod = 'VI';
                } elseif (stripos($pm, 'chèque') !== false || stripos($pm, 'cheque') !== false) {
                    $paymentMethod = 'CH';
                } else {
                    $paymentMethod = $pm;
                }
            }

            $slipRows[] = $this->makeRow([
                'TYPE' => 'E',
                'JNAL' => $code_journal,
                'NECR' => '',
                'NPIE' => $slipNumber,
                'DATP' => $slipDate->format('d/m/Y'),
                'LIBE' => $label,
                'DATH' => $dath,
                'CNPI' => 'AC',
                'RACI' => '',
                'MONT' => $this->fmt($total_ttc),
                'CODC' => 'C',
                'CPTG' => '411000',
                'DATE' => $dateStr,
                'CLET' => '',
                'DATL' => '',
                'CPTA' => $compteClient,
                'CNAT' => 'C',
                'CTRE' => '',
                'NORL' => '',
                'DATV' => '',
                'REFD' => '',
                'NECA' => '',
                'CSEC' => '',
                'CAFF' => '',
                'CDES' => '',
                'QTUE' => '',
                'MTDV' => '',
                'CODV' => '',
                'TXDV' => '',
                'MOPM' => $paymentMethod,
                'BONP' => '',
                'BQAF' => '',
                'ECES' => '',
                'TXTL' => '',
                'ECRM' => '',
                'DATK' => '',
                'HEUK' => '',
            ]);

            $montant_articles_sans_consigne = $total_ht_articles - $total_consigne;
            $slipRows[] = $this->makeRow([
                'TYPE' => 'E',
                'JNAL' => $code_journal,
                'NECR' => '',
                'NPIE' => $slipNumber,
                'DATP' => $slipDate->format('d/m/Y'),
                'LIBE' => $label,
                'DATH' => $dath,
                'CNPI' => 'AC',
                'RACI' => '',
                'MONT' => $this->fmt($montant_articles_sans_consigne),
                'CODC' => 'D',
                'CPTG' => $isFrance ? '70700300' : '70792300',
                'DATE' => $dateStr,
                'CLET' => '',
                'DATL' => '',
                'CPTA' => '',
                'CNAT' => '',
                'CTRE' => '',
                'NORL' => '',
                'DATV' => '',
                'REFD' => '',
                'NECA' => '',
                'CSEC' => '',
                'CAFF' => '',
                'CDES' => '',
                'QTUE' => '',
                'MTDV' => '',
                'CODV' => '',
                'TXDV' => '',
                'MOPM' => $paymentMethod,
                'BONP' => '',
                'BQAF' => '',
                'ECES' => '',
                'TXTL' => '',
                'ECRM' => '',
                'DATK' => '',
                'HEUK' => '',
            ]);

            if ($total_ht_shipping != 0.0) {
                $slipRows[] = $this->makeRow([
                    'TYPE' => 'E',
                    'JNAL' => $code_journal,
                    'NECR' => '',
                    'NPIE' => $slipNumber,
                    'DATP' => $slipDate->format('d/m/Y'),
                    'LIBE' => $label,
                    'DATH' => $dath,
                    'CNPI' => 'AC',
                    'RACI' => '',
                    'MONT' => $this->fmt($total_ht_shipping),
                    'CODC' => 'D',
                    'CPTG' => $isFrance ? '70850300' : '70852300',
                    'CPTC' => '',
                    'DATE' => $dateStr,
                    'CLET' => '',
                    'DATL' => '',
                    'CPTA' => '',
                    'CNAT' => '',
                    'CTRE' => '',
                    'NORL' => '',
                    'DATV' => '',
                    'REFD' => '',
                    'NECA' => '',
                    'CSEC' => '',
                    'CAFF' => '',
                    'CDES' => '',
                    'QTUE' => '',
                    'MTDV' => '',
                    'CODV' => '',
                    'TXDV' => '',
                    'MOPM' => $paymentMethod,
                    'BONP' => '',
                    'BQAF' => '',
                    'ECES' => '',
                    'TXTL' => '',
                    'ECRM' => '',
                    'DATK' => '',
                    'HEUK' => '',
                ]);
            }

            $ht = $slip['total_products_tax_excl'] + $slip['total_shipping_tax_excl'];
            $ttc = $slip['total_products_tax_incl'] + $slip['total_shipping_tax_incl'];
            if ($total_taxes != 0.0 && $ht != $ttc) {
                $slipRows[] = $this->makeRow([
                    'TYPE' => 'E',
                    'JNAL' => $code_journal,
                    'NECR' => '',
                    'NPIE' => $slipNumber,
                    'DATP' => $slipDate->format('d/m/Y'),
                    'LIBE' => $label,
                    'DATH' => $dath,
                    'CNPI' => 'AC',
                    'RACI' => '',
                    'MONT' => $this->fmt($total_taxes),
                    'CODC' => 'D',
                    'CPTG' => '445700',
                    'DATE' => $dateStr,
                    'CLET' => '',
                    'DATL' => '',
                    'CPTA' => '',
                    'CNAT' => '',
                    'CTRE' => '',
                    'NORL' => '',
                    'DATV' => '',
                    'REFD' => '',
                    'NECA' => '',
                    'CSEC' => '',
                    'CAFF' => '',
                    'CDES' => '',
                    'QTUE' => '',
                    'MTDV' => '',
                    'CODV' => '',
                    'TXDV' => '',
                    'MOPM' => $paymentMethod,
                    'BONP' => '',
                    'BQAF' => '',
                    'ECES' => '',
                    'TXTL' => '',
                    'ECRM' => '',
                    'DATK' => '',
                    'HEUK' => '',
                ]);
            }

            if ($total_consigne != 0.0) {
                $slipRows[] = $this->makeRow([
                    'TYPE' => 'E',
                    'JNAL' => $code_journal,
                    'NECR' => '',
                    'NPIE' => $slipNumber,
                    'DATP' => $slipDate->format('d/m/Y'),
                    'LIBE' => $label,
                    'DATH' => $dath,
                    'CNPI' => 'AC',
                    'RACI' => '',
                    'MONT' => $this->fmt($total_consigne),
                    'CODC' => 'D',
                    'CPTG' => $isFrance ? '70710300' : '70712300',
                    'CPTC' => '',
                    'DATE' => $dateStr,
                    'CLET' => '',
                    'DATL' => '',
                    'CPTA' => '',
                    'CNAT' => '',
                    'CTRE' => '',
                    'NORL' => '',
                    'DATV' => '',
                    'REFD' => '',
                    'NECA' => '',
                    'CSEC' => '',
                    'CAFF' => '',
                    'CDES' => '',
                    'QTUE' => '',
                    'MTDV' => '',
                    'CODV' => '',
                    'TXDV' => '',
                    'MOPM' => $paymentMethod,
                    'BONP' => '',
                    'BQAF' => '',
                    'ECES' => '',
                    'TXTL' => '',
                    'ECRM' => '',
                    'DATK' => '',
                    'HEUK' => '',
                ]);
            }

            $groups[] = $slipRows;
        }

        return $groups;
    }

    protected function getConsigneTotalForOrder($id_order)
    {
        $sql = '
            SELECT SUM(od.product_quantity * p.consigne) as total_consigne
            FROM ' . _DB_PREFIX_ . 'order_detail od
            INNER JOIN ' . _DB_PREFIX_ . 'product p ON (p.id_product = od.product_id)
            WHERE od.id_order = ' . (int) $id_order . '
            AND p.consigne > 0';

        $result = Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow($sql);
        return (float) ($result['total_consigne'] ?? 0);
    }

    protected function fmt($number)
    {
        return number_format((float) $number, 2, ',', '');
    }

    protected function makeRow(array $map)
    {
        $keys = [
            'TYPE',
            'JNAL',
            'NECR',
            'NPIE',
            'DATP',
            'LIBE',
            'DATH',
            'CNPI',
            'RACI',
            'MONT',
            'CODC',
            'CPTG',
            'DATE',
            'CLET',
            'DATL',
            'CPTA',
            'CNAT',
            'CTRE',
            'NORL',
            'DATV',
            'REFD',
            'NECA',
            'CSEC',
            'CAFF',
            'CDES',
            'QTUE',
            'MTDV',
            'CODV',
            'TXDV',
            'MOPM',
            'BONP',
            'BQAF',
            'ECES',
            'TXTL',
            'ECRM',
            'DATK',
            'HEUK'
        ];
        if (isset($map['CPTG'])) {
            $cptg = (string) $map['CPTG'];
            if (strlen($cptg) > 6 && substr($cptg, -2) === '00') {
                $map['CPTG'] = substr($cptg, 0, -2);
            }
        }
        $row = [];
        foreach ($keys as $k) {
            $row[$k] = isset($map[$k]) ? $map[$k] : '';
        }
        return $row;
    }

    protected function exportCsv(array $rows)
    {
        $filename = 'export_comptable_' . date('Ymd_His') . '.csv';

        require_once _PS_MODULE_DIR_ . 'export_comptable/ExportComptableTools.php';
        $csvContent = ExportComptableTools::generateCsvContent($rows);


        $output = mb_convert_encoding($csvContent, 'Windows-1252', 'UTF-8');

        header('Content-Type: text/csv; charset=Windows-1252');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($output));

        echo $output;
    }

    protected function exportXlsx(array $rows)
    {
        $filename = 'export_comptable_' . date('Ymd_His') . '.xlsx';
        $tempDir = sys_get_temp_dir() . '/xlsx_' . uniqid();
        mkdir($tempDir);

        require_once _PS_MODULE_DIR_ . 'export_comptable/ExportComptableTools.php';

        ExportComptableTools::createXlsxStructure($tempDir, $rows);

        $zipFile = $tempDir . '.zip';
        ExportComptableTools::zipDirectory($tempDir, $zipFile);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($zipFile));
        readfile($zipFile);

        ExportComptableTools::deleteDirectory($tempDir);
        unlink($zipFile);
    }
}