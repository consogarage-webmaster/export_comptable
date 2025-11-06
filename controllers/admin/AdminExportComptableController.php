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
        $export = (bool) Tools::getValue('export_csv');

        // Données (tableau groupé : chaque élément = lignes d’une facture)
        $rows = $this->getAccountingRows($date_from, $date_to);

        if ($export) {
            $this->exportCsv($rows);
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
                'Date (date facture)',
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
                c.firstname, c.lastname, c.company,
                a.id_country,
                country.iso_code AS country_iso,
                oi.total_paid_tax_incl,
                oi.total_paid_tax_excl,
                oi.total_products       AS total_products_ht,
                oi.total_shipping_tax_excl AS shipping_ht
            FROM ' . _DB_PREFIX_ . 'order_invoice oi
            INNER JOIN ' . _DB_PREFIX_ . 'orders o    ON (o.id_order = oi.id_order)
            INNER JOIN ' . _DB_PREFIX_ . 'customer c  ON (c.id_customer = o.id_customer)
            INNER JOIN ' . _DB_PREFIX_ . 'address a   ON (a.id_address = o.id_address_invoice)
            INNER JOIN ' . _DB_PREFIX_ . 'country country ON (country.id_country = a.id_country)
            ' . $where . $orderBy . $limit;

        $invoices = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);

        $groups = [];
        foreach ($invoices as $inv) {
            $invoiceRows = [];

            $invoiceNumber = (string) $inv['invoice_number'];
            $invoiceDate = new DateTime($inv['invoice_date']);
            $dateStr = $invoiceDate->format('d/m/y');
            $isFrance = (strtoupper((string) $inv['country_iso']) === 'FR');

            // Libellé: "Prénom Nom" ou "Prénom Nom - Société"
            $label = trim($inv['firstname'] . ' ' . $inv['lastname']);
            if (!empty($inv['company'])) {
                $label .= ' - ' . $inv['company'];
            }

            // Montants
            $total_ttc = (float) $inv['total_paid_tax_incl'];
            $total_ht_articles = (float) $inv['total_products_ht'];
            $total_ht_shipping = (float) $inv['shipping_ht'];
            $total_taxes = (float) $inv['total_paid_tax_incl'] - (float) $inv['total_paid_tax_excl'];

            $code_journal = 'VT';

            // 1) Total TTC (Débit) — compte 41100 — lettrage CLET selon pays
            $invoiceRows[] = $this->makeRow([
                'TYPE' => 'E',
                'JNAL' => $code_journal,
                'NECR' => '',
                'NPIE' => $invoiceNumber,
                'DATP' => $invoiceDate->format('d/m/Y'),
                'LIBE' => $label,
                'DATH' => '',
                'CNPI' => '',
                'RACI' => '',
                'MONT' => $this->fmt($total_ttc),
                'CODC' => 'D',
                'CPTG' => '41100',
                'DATE' => $dateStr,
                'CLET' => $isFrance ? '00001' : '00101',
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
                'MOPM' => '',
                'BONP' => '',
                'BQAF' => '',
                'ECES' => '',
                'TXTL' => '',
                'ECRM' => '',
                'DATK' => '',
                'HEUK' => '',
            ]);

            // 2) Total articles HT (Crédit) — 707000/707100
            $invoiceRows[] = $this->makeRow([
                'TYPE' => 'E',
                'JNAL' => $code_journal,
                'NECR' => '',
                'NPIE' => $invoiceNumber,
                'DATP' => $invoiceDate->format('d/m/Y'),
                'LIBE' => $label,
                'DATH' => '',
                'CNPI' => '',
                'RACI' => '',
                'MONT' => $this->fmt($total_ht_articles),
                'CODC' => 'C',
                'CPTG' => $isFrance ? '707000' : '707100',
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
                'MOPM' => '',
                'BONP' => '',
                'BQAF' => '',
                'ECES' => '',
                'TXTL' => '',
                'ECRM' => '',
                'DATK' => '',
                'HEUK' => '',
            ]);

            // 3) Frais de port HT (Crédit) — 708500/708501 si non nul
            if ($total_ht_shipping != 0.0) {
                $invoiceRows[] = $this->makeRow([
                    'TYPE' => 'E',
                    'JNAL' => $code_journal,
                    'NECR' => '',
                    'NPIE' => $invoiceNumber,
                    'DATP' => $invoiceDate->format('d/m/Y'),
                    'LIBE' => $label,
                    'DATH' => '',
                    'CNPI' => '',
                    'RACI' => '',
                    'MONT' => $this->fmt($total_ht_shipping),
                    'CODC' => 'C',
                    'CPTG' => $isFrance ? '708500' : '708501',
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
                    'MOPM' => '',
                    'BONP' => '',
                    'BQAF' => '',
                    'ECES' => '',
                    'TXTL' => '',
                    'ECRM' => '',
                    'DATK' => '',
                    'HEUK' => '',
                ]);
            }

            // 4) Total taxes (Crédit) — 445700 si non nul
            if ($total_taxes != 0.0) {
                $invoiceRows[] = $this->makeRow([
                    'TYPE' => 'E',
                    'JNAL' => $code_journal,
                    'NECR' => '',
                    'NPIE' => $invoiceNumber,
                    'DATP' => $invoiceDate->format('d/m/Y'),
                    'LIBE' => $label,
                    'DATH' => '',
                    'CNPI' => '',
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
                    'MOPM' => '',
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

    protected function fmt($number)
    {
        // Décimale point (interop CSV/ERP)
        return number_format((float) $number, 2, '.', '');
    }

    protected function makeRow(array $map)
    {
        // Ordre strict des 37 colonnes
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
        $row = [];
        foreach ($keys as $k) {
            $row[$k] = isset($map[$k]) ? $map[$k] : '';
        }
        return $row;
    }

    /**
     * Export CSV
     */
    protected function exportCsv(array $rows)
    {
        $filename = 'export_comptable_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');

        $headers = $this->getHeaders();
        fputcsv($out, $headers[0], ';');
        fputcsv($out, $headers[1], ';');

        foreach ($rows as $invoiceRows) {
            foreach ($invoiceRows as $r) {
                fputcsv($out, array_values($r), ';');
            }
        }
        fclose($out);
    }
}
