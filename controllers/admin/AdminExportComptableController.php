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

        // Données (tableau groupé : chaque élément = lignes d'une facture)
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

        // Utilise la notation "module:" (PS 1.7/8)
        $this->setTemplate('/export.tpl');
    }

    /**
     * En-têtes (2 lignes)
     * Les 37 colonnes doivent rester alignées avec l’ordre des clés de makeRow().
     */
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
                // 'Compte client',
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
                // 'CPTC',
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

    /**
     * Retourne un tableau GROUPÉ : array<array<row>>
     * $groups[0] = [ligne1, ligne2, (ligne3 si frais), (ligne4 si TVA)]
     * Inclut les factures ET les avoirs
     */
    protected function getAccountingRows($date_from, $date_to)
    {
        $groups = [];

        // 1) Récupérer les factures
        $invoiceGroups = $this->getInvoiceRows($date_from, $date_to);
        $groups = array_merge($groups, $invoiceGroups);

        // 2) Récupérer les avoirs
        $creditSlipGroups = $this->getCreditSlipRows($date_from, $date_to);
        $groups = array_merge($groups, $creditSlipGroups);

        return $groups;
    }

    /**
     * Récupère les lignes comptables pour les factures
     */
    protected function getInvoiceRows($date_from, $date_to)
    {
        // Construction du WHERE (échappé)
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
                c.firstname, c.lastname, c.company,
                a.id_country,
                country.iso_code AS country_iso,
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
            LEFT JOIN ' . _DB_PREFIX_ . 'order_payment op ON (op.order_reference = o.reference)
            ' . $where . $orderBy . $limit;

        $invoices = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);

        $groups = [];
        foreach ($invoices as $inv) {
            $invoiceRows = [];

            $invoiceNumber = (string) $inv['invoice_number'];
            $invoiceDate = new DateTime($inv['invoice_date']);
            $dateStr = $invoiceDate->format('d/m/y');
            $isFrance = (strtoupper((string) $inv['country_iso']) === 'FR');

            // Libellé: "Prénom Nom" ou "Prénom Nom - Société" (en majuscules)
            $label = trim($inv['firstname'] . ' ' . $inv['lastname']);
            if (!empty($inv['company'])) {
                $label .= ' - ' . $inv['company'];
            }
            $label = mb_strtoupper($label, 'UTF-8');
            // Nettoyer les caractères spéciaux pour LD Compta
            require_once _PS_MODULE_DIR_ . 'export_comptable/ExportComptableTools.php';
            $label = ExportComptableTools::cleanLabel($label);

            // Compte client : T + id_customer (8 chiffres au total)
            $customerId = str_pad($inv['id_customer'], 5, '0', STR_PAD_LEFT);
            $compteClient = 'T' . $customerId;

            // Montants
            $total_ttc = (float) $inv['total_paid_tax_incl'];
            $total_ht_articles = (float) $inv['total_products_ht'];
            $total_ht_shipping = (float) $inv['shipping_ht'];
            $total_consigne = $this->getConsigneTotalForOrder($inv['id_order']);

            // Calcul TVA avec arrondi pour garantir l'équilibre comptable
            // TVA = TTC - (Articles HT + Frais de port HT)
            $total_taxes = $total_ttc - ($total_ht_articles + $total_ht_shipping);

            $code_journal = '71';

            // Calcul de la date d'échéance
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

            // Mode de paiement (correspondances personnalisées)
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
                } else {
                    $paymentMethod = $pm;
                }
            }

            // 1) Total TTC (Débit) — compte 41100 — lettrage CLET selon pays
            $invoiceRows[] = $this->makeRow([
                'TYPE' => 'E',
                'JNAL' => $code_journal,
                'NECR' => '',
                'NPIE' => $invoiceNumber,
                'DATP' => $invoiceDate->format('d/m/Y'),
                'LIBE' => $label,
                'DATH' => $dath,
                'CNPI' => 'FV',
                'RACI' => '',
                'MONT' => $this->fmt($total_ttc),
                'CODC' => 'D',
                'CPTG' => '41100000',
                // 'CPTC' => $compteClient,
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

            // 2) Total articles HT (Crédit) — 70700300/70792300
            // On retire la consigne car elle est incluse dans le prix de vente
            $montant_articles_sans_consigne = $total_ht_articles - $total_consigne;
            $invoiceRows[] = $this->makeRow([
                'TYPE' => 'E',
                'JNAL' => $code_journal,
                'NECR' => '',
                'NPIE' => $invoiceNumber,
                'DATP' => $invoiceDate->format('d/m/Y'),
                'LIBE' => $label,
                'DATH' => $dath,
                'CNPI' => 'FV',
                'RACI' => '',
                'MONT' => $this->fmt($montant_articles_sans_consigne),
                'CODC' => 'C',
                'CPTG' => $isFrance ? '70700300' : '70792300',
                // 'CPTC' => '',
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

            // 3) Frais de port HT (Crédit) — 70850300/70852300 si non nul
            if ($total_ht_shipping != 0.0) {
                $invoiceRows[] = $this->makeRow([
                    'TYPE' => 'E',
                    'JNAL' => $code_journal,
                    'NECR' => '',
                    'NPIE' => $invoiceNumber,
                    'DATP' => $invoiceDate->format('d/m/Y'),
                    'LIBE' => $label,
                    'DATH' => $dath,
                    'CNPI' => 'FV',
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

            // 4) Total taxes (Crédit) — 445700 si non nul
            // Afficher la ligne TVA uniquement si total_paid_tax_excl != total_paid_tax_incl
            if ($total_taxes != 0.0 && $inv['total_paid_tax_excl'] != $inv['total_paid_tax_incl']) {
                $invoiceRows[] = $this->makeRow([
                    'TYPE' => 'E',
                    'JNAL' => $code_journal,
                    'NECR' => '',
                    'NPIE' => $invoiceNumber,
                    'DATP' => $invoiceDate->format('d/m/Y'),
                    'LIBE' => $label,
                    'DATH' => $dath,
                    'CNPI' => 'FV',
                    'RACI' => '',
                    'MONT' => $this->fmt($total_taxes),
                    'CODC' => 'C',
                    'CPTG' => '44570000',
                    // 'CPTC' => '',
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

            // 5) Consigne HT (Crédit) — 70710300/70712300 si non nul
            if ($total_consigne != 0.0) {
                $invoiceRows[] = $this->makeRow([
                    'TYPE' => 'E',
                    'JNAL' => $code_journal,
                    'NECR' => '',
                    'NPIE' => $invoiceNumber,
                    'DATP' => $invoiceDate->format('d/m/Y'),
                    'LIBE' => $label,
                    'DATH' => $dath,
                    'CNPI' => 'FV',
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

    /**
     * Récupère les lignes comptables pour les avoirs (écritures inversées)
     */
    protected function getCreditSlipRows($date_from, $date_to)
    {
        // Construction du WHERE pour les avoirs
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
                c.firstname, c.lastname, c.company,
                a.id_country,
                country.iso_code AS country_iso,
                op.payment_method
            FROM ' . _DB_PREFIX_ . 'order_slip os
            INNER JOIN ' . _DB_PREFIX_ . 'orders o ON (o.id_order = os.id_order)
            INNER JOIN ' . _DB_PREFIX_ . 'customer c ON (c.id_customer = o.id_customer)
            INNER JOIN ' . _DB_PREFIX_ . 'address a ON (a.id_address = o.id_address_invoice)
            INNER JOIN ' . _DB_PREFIX_ . 'country country ON (country.id_country = a.id_country)
            LEFT JOIN ' . _DB_PREFIX_ . 'order_payment op ON (op.order_reference = o.reference)
            ' . $where . $orderBy . $limit;

        $slips = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);

        $groups = [];
        foreach ($slips as $slip) {
            $slipRows = [];

            $slipNumber = 'AV' . str_pad($slip['id_order_slip'], 6, '0', STR_PAD_LEFT);
            $slipDate = new DateTime($slip['slip_date']);
            $dateStr = $slipDate->format('d/m/y');
            $isFrance = (strtoupper((string) $slip['country_iso']) === 'FR');

            // Libellé: "Prénom Nom" ou "Prénom Nom - Société" (en majuscules)
            $label = trim($slip['firstname'] . ' ' . $slip['lastname']);
            if (!empty($slip['company'])) {
                $label .= ' - ' . $slip['company'];
            }
            $label = mb_strtoupper($label, 'UTF-8');
            // Nettoyer les caractères spéciaux pour LD Compta
            require_once _PS_MODULE_DIR_ . 'export_comptable/ExportComptableTools.php';
            $label = ExportComptableTools::cleanLabel($label);

            // Compte client : T + id_customer (8 chiffres au total)
            $customerId = str_pad($slip['id_customer'], 5, '0', STR_PAD_LEFT);
            $compteClient = 'T' . $customerId;

            // Montants (positifs car on inverse ensuite avec CODC)
            $total_ttc = (float) $slip['total_products_tax_incl'] + (float) $slip['total_shipping_tax_incl'];
            $total_ht_articles = (float) $slip['total_products_tax_excl'];
            $total_ht_shipping = (float) $slip['total_shipping_tax_excl'];
            $total_consigne = $this->getConsigneTotalForOrder($slip['id_order']);

            // Calcul TVA avec arrondi pour garantir l'équilibre comptable
            // TVA = TTC - (Articles HT + Frais de port HT)
            $total_taxes = $total_ttc - ($total_ht_articles + $total_ht_shipping);

            $code_journal = '71';

            // Calcul de la date d'échéance
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

            // Mode de paiement (correspondances personnalisées)
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
                } else {
                    $paymentMethod = $pm;
                }
            }

            // 1) Total TTC (CRÉDIT au lieu de Débit) — compte 41100
            $slipRows[] = $this->makeRow([
                'TYPE' => 'E',
                'JNAL' => $code_journal,
                'NECR' => '',
                'NPIE' => $slipNumber,
                'DATP' => $slipDate->format('d/m/Y'),
                'LIBE' => $label,
                'DATH' => $dath,
                'CNPI' => 'FV',
                'RACI' => '',
                'MONT' => $this->fmt($total_ttc),
                'CODC' => 'C',  // INVERSÉ
                'CPTG' => '41100000',
                // 'CPTC' => $compteClient,
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

            // 2) Total articles HT (DÉBIT au lieu de Crédit) — 70700300/70792300
            // On retire la consigne car elle est incluse dans le prix de vente
            $montant_articles_sans_consigne = $total_ht_articles - $total_consigne;
            $slipRows[] = $this->makeRow([
                'TYPE' => 'E',
                'JNAL' => $code_journal,
                'NECR' => '',
                'NPIE' => $slipNumber,
                'DATP' => $slipDate->format('d/m/Y'),
                'LIBE' => $label,
                'DATH' => $dath,
                'CNPI' => 'FV',
                'RACI' => '',
                'MONT' => $this->fmt($montant_articles_sans_consigne),
                'CODC' => 'D',  // INVERSÉ
                'CPTG' => $isFrance ? '70700300' : '70792300',
                // 'CPTC' => '',
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

            // 3) Frais de port HT (DÉBIT au lieu de Crédit) — 70850300/70852300 si non nul
            if ($total_ht_shipping != 0.0) {
                $slipRows[] = $this->makeRow([
                    'TYPE' => 'E',
                    'JNAL' => $code_journal,
                    'NECR' => '',
                    'NPIE' => $slipNumber,
                    'DATP' => $slipDate->format('d/m/Y'),
                    'LIBE' => $label,
                    'DATH' => $dath,
                    'CNPI' => 'FV',
                    'RACI' => '',
                    'MONT' => $this->fmt($total_ht_shipping),
                    'CODC' => 'D',  // INVERSÉ
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

            // 4) Total taxes (DÉBIT au lieu de Crédit) — 445700 si non nul
            // Afficher la ligne TVA uniquement si total_products_tax_excl + total_shipping_tax_excl != total_products_tax_incl + total_shipping_tax_incl
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
                    'CNPI' => 'FV',
                    'RACI' => '',
                    'MONT' => $this->fmt($total_taxes),
                    'CODC' => 'D',  // INVERSÉ
                    'CPTG' => '44570000',
                    // 'CPTC' => '',
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

            // 5) Consigne HT (DÉBIT au lieu de Crédit) — 70710300/70712300 si non nul
            if ($total_consigne != 0.0) {
                $slipRows[] = $this->makeRow([
                    'TYPE' => 'E',
                    'JNAL' => $code_journal,
                    'NECR' => '',
                    'NPIE' => $slipNumber,
                    'DATP' => $slipDate->format('d/m/Y'),
                    'LIBE' => $label,
                    'DATH' => $dath,
                    'CNPI' => 'FV',
                    'RACI' => '',
                    'MONT' => $this->fmt($total_consigne),
                    'CODC' => 'D',  // INVERSÉ
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

    /**
     * Calcule le total des consignes pour une commande
     */
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
        // Décimale virgule (format français)
        return number_format((float) $number, 2, ',', '');
    }

    protected function makeRow(array $map)
    {
        // Ordre strict des 38 colonnes
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
     * Export CSV avec séparateur point-virgule
     */
    protected function exportCsv(array $rows)
    {
        $filename = 'export_comptable_' . date('Ymd_His') . '.csv';

        // Générer le contenu CSV
        require_once _PS_MODULE_DIR_ . 'export_comptable/ExportComptableTools.php';
        $csvContent = ExportComptableTools::generateCsvContent($rows);

        // Ne pas ajouter de BOM pour LD Compta/WinDev (peut causer des erreurs GPF)
        // Utiliser ISO-8859-1 (Latin1) ou Windows-1252 pour meilleure compatibilité WinDev
        $output = mb_convert_encoding($csvContent, 'Windows-1252', 'UTF-8');

        // Envoyer le fichier
        header('Content-Type: text/csv; charset=Windows-1252');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($output));

        echo $output;
    }
}