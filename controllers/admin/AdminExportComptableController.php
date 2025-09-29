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

        // Récupère les filtres
        $date_from = Tools::getValue('date_from');
        $date_to = Tools::getValue('date_to');
        $export = Tools::getValue('export_csv');

        // Données
        $rows = $this->getAccountingRows($date_from, $date_to);

        if ($export) {
            $this->exportCsv($rows);
            exit;
        }

        // Assignation Smarty
        $this->context->smarty->assign([
            'date_from' => $date_from,
            'date_to' => $date_to,
            'rows' => $rows,
            'headers' => $this->getHeaders(),
        ]);

        $this->setTemplate('export.tpl');
    }

    /**
     * Construit les en-têtes (2 lignes affichées dans THEAD).
     */
    protected function getHeaders()
    {
        return [
            ['Type d’enregistrement (E,A)', 'Code journal', 'N° écriture', 'N° pièce', 'Date pièce', 'Libellé', 'Date échéance', 'Code nature', 'Racine compte collectif', 'Montant en euros', 'Code débit/crédit (C,D)', 'Compte général', 'Date (date facture ?)', 'Code lettrage', 'Date lettrage', 'Compte auxiliaire', 'Code nature tiers (C,F,A,Blanc)', 'Code trésorerie', 'N° relance', 'date valeur', 'Référence document', 'N° de séquence', 'Code section (axe anal.1)', 'Code affaire (axe anal. 2)', 'Code destination (axe anal. 2)', 'Quantité analytique', 'Montant en devises', 'Code devise ISO', 'Taux de la devise', 'Mode de paiement', 'Bon à payer', 'Code banque affectation', 'Echéance escomptable', 'Zone texte libre', 'Ecriture modifiable (L,I,blanc)', 'Date création', 'Heure création'],
            ['TYPE', 'JNAL', 'NECR', 'NPIE', 'DATP', 'LIBE', 'DATH', 'CNPI', 'RACI', 'MONT', 'CODC', 'CPTG', 'DATE', 'CLET', 'DATL', 'CPTA', 'CNAT', 'CTRE', 'NORL', 'DATV', 'REFD', 'NECA', 'CSEC', 'CAFF', 'CDES', 'QTUE', 'MTDV', 'CODV', 'TXDV', 'MOPM', 'BONP', 'BQAF', 'ECES', 'TXTL', 'ECRM', 'DATK', 'HEUK'],
        ];
    }

    /**
     * Fabrique les 4 lignes par facture conformément aux règles.
     */
    protected function getAccountingRows($date_from, $date_to)
    {
        $id_lang = (int) $this->context->language->id;

        // Filtre par dates de facture (ps_order_invoice.date_add)
        $where = ' WHERE oi.number > 0 '; // only real invoices with number assigned
        $params = [];

        if ($date_from) {
            $where .= ' AND DATE(oi.date_add) >= DATE(?) ';
            $params[] = $date_from;
        }
        if ($date_to) {
            $where .= ' AND DATE(oi.date_add) <= DATE(?) ';
            $params[] = $date_to;
        }

        $orderBy = ' ORDER BY oi.date_add DESC ';
        $limit = '';
        if (!$date_from && !$date_to) {
            // par défaut : 100 dernières factures
            $limit = ' LIMIT 100 ';
        }

        $sql = '
            SELECT
                oi.id_order_invoice,
                oi.number AS invoice_number,
                oi.date_add AS invoice_date,
                o.reference AS order_reference,
                o.id_order,
                a.firstname, a.lastname, a.company,
                a.id_country,
                country.iso_code AS country_iso,
                oi.total_paid_tax_incl,
                oi.total_paid_tax_excl,
                oi.total_products AS total_products_ht,
                oi.total_shipping_tax_excl AS shipping_ht
            FROM ' . _DB_PREFIX_ . 'order_invoice oi
            INNER JOIN ' . _DB_PREFIX_ . 'orders o ON (o.id_order = oi.id_order)
            INNER JOIN ' . _DB_PREFIX_ . 'address a ON (a.id_address = o.id_address_invoice)
            INNER JOIN ' . _DB_PREFIX_ . 'country country ON (country.id_country = a.id_country)
            ' . $where . $orderBy . $limit;

        $invoices = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql, $params);

        $rows = [];
        $sequence = 0;

        foreach ($invoices as $inv) {
            $sequence++;

            $invoiceNumber = (string) $inv['invoice_number'];
            $invoiceDate = new DateTime($inv['invoice_date']);
            $dateStr = $invoiceDate->format('d/m/y');

            $isFrance = (strtoupper((string) $inv['country_iso']) === 'FR');

            // Libellé: prenom nom [ - company] depuis l'adresse de facturation
            $label = trim($inv['firstname'] . ' ' . $inv['lastname']);
            if (!empty($inv['company'])) {
                $label .= ' - ' . $inv['company'];
            }

            // Totaux
            $total_ttc = (float) $inv['total_paid_tax_incl'];
            $total_ht_articles = (float) $inv['total_products_ht'];
            $total_ht_shipping = (float) $inv['shipping_ht'];
            $total_taxes = (float) $inv['total_paid_tax_incl'] - (float) $inv['total_paid_tax_excl'];

            // Règles mapping
            $code_journal = 'VT';

            // 1) Ligne total TTC (Débit)
            $rows[] = $this->makeRow([
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
                'ECRM' => 'C', // Ecriture modifiable: selon ta règle tu mets "L,I,blanc" — je laisse vide ailleurs et "C" ici d’après ton exemple pour “Code lettrage”. Si tu voulais ECRM vide, remplace.
                'DATK' => '',
                'HEUK' => '',
            ]);

            // 2) Ligne total articles HT (Crédit)
            $rows[] = $this->makeRow([
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

            // 3) Ligne frais de port HT (Crédit)
            if ($total_ht_shipping != 0.0) {
                $rows[] = $this->makeRow([
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

            // 4) Ligne total taxes (Crédit)
            if ($total_taxes != 0.0) {
                $rows[] = $this->makeRow([
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
        }

        return $rows;
    }

    protected function fmt($number)
    {
        // Nombre en euros — utiliser point comme séparateur décimal pour CSV/interop
        return number_format((float) $number, 2, '.', '');
    }

    protected function makeRow(array $map)
    {
        // Garantit la présence de toutes les colonnes dans l’ordre (même vides)
        $keys = ['TYPE', 'JNAL', 'NECR', 'NPIE', 'DATP', 'LIBE', 'DATH', 'CNPI', 'RACI', 'MONT', 'CODC', 'CPTG', 'DATE', 'CLET', 'DATL', 'CPTA', 'CNAT', 'CTRE', 'NORL', 'DATV', 'REFD', 'NECA', 'CSEC', 'CAFF', 'CDES', 'QTUE', 'MTDV', 'CODV', 'TXDV', 'MOPM', 'BONP', 'BQAF', 'ECES', 'TXTL', 'ECRM', 'DATK', 'HEUK'];
        $row = [];
        foreach ($keys as $k) {
            $row[$k] = isset($map[$k]) ? $map[$k] : '';
        }
        return $row;
    }

    protected function exportCsv(array $rows)
    {
        $filename = 'export_comptable_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');

        // En-têtes sur deux lignes
        $headers = $this->getHeaders();
        fputcsv($out, $headers[0], ';');
        fputcsv($out, $headers[1], ';');

        // Lignes
        foreach ($rows as $r) {
            fputcsv($out, array_values($r), ';');
        }

        fclose($out);
    }
}
