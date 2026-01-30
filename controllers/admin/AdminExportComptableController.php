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
        $export = (bool) Tools::getValue('export_xlsx');

        // Données (tableau groupé : chaque élément = lignes d’une facture)
        $rows = $this->getAccountingRows($date_from, $date_to);

        if ($export) {
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

            // Calcul de la date d'échéance (dernier jour du mois suivant)
            $dath = '';
            if (!empty($inv['payment_method']) && $inv['payment_method'] === 'Paiement en compte') {
                $echeance = clone $invoiceDate;
                $echeance->modify('first day of next month');
                $echeance->modify('last day of this month');
                $dath = $echeance->format('d/m/Y');
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
                'CNPI' => '',
                'RACI' => '',
                'MONT' => $this->fmt($total_ttc),
                'CODC' => 'D',
                'CPTG' => '41100000',
                // 'CPTC' => $compteClient,
                'DATE' => $dateStr,
                'CLET' => '',
                'DATL' => '',
                'CPTA' => $compteClient,
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
                'CNPI' => '',
                'RACI' => '',
                'MONT' => $this->fmt($montant_articles_sans_consigne),
                'CODC' => 'C',
                'CPTG' => $isFrance ? '70700300' : '70792300',
                // 'CPTC' => '',
                'DATE' => $dateStr,
                'CLET' => '',
                'DATL' => '',
                'CPTA' => $compteClient,
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
                    'CNPI' => '',
                    'RACI' => '',
                    'MONT' => $this->fmt($total_ht_shipping),
                    'CODC' => 'C',
                    'CPTG' => $isFrance ? '70850300' : '70852300',
                    'CPTC' => '',
                    'DATE' => $dateStr,
                    'CLET' => '',
                    'DATL' => '',
                    'CPTA' => $compteClient,
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
            // Ne pas écrire de ligne TVA pour les ventes à l'étranger (exo)
            if ($total_taxes != 0.0 && $isFrance) {
                $invoiceRows[] = $this->makeRow([
                    'TYPE' => 'E',
                    'JNAL' => $code_journal,
                    'NECR' => '',
                    'NPIE' => $invoiceNumber,
                    'DATP' => $invoiceDate->format('d/m/Y'),
                    'LIBE' => $label,
                    'DATH' => $dath,
                    'CNPI' => '',
                    'RACI' => '',
                    'MONT' => $this->fmt($total_taxes),
                    'CODC' => 'C',
                    'CPTG' => '44570000',
                    'CPTC' => '',
                    'DATE' => $dateStr,
                    'CLET' => '',
                    'DATL' => '',
                    'CPTA' => $compteClient,
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
                    'CNPI' => '',
                    'RACI' => '',
                    'MONT' => $this->fmt($total_consigne),
                    'CODC' => 'C',
                    'CPTG' => $isFrance ? '70710300' : '70712300',
                    'CPTC' => '',
                    'DATE' => $dateStr,
                    'CLET' => '',
                    'DATL' => '',
                    'CPTA' => $compteClient,
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

            // Calcul de la date d'échéance (dernier jour du mois suivant)
            $dath = '';
            if (!empty($slip['payment_method']) && $slip['payment_method'] === 'Paiement en compte') {
                $echeance = clone $slipDate;
                $echeance->modify('first day of next month');
                $echeance->modify('last day of this month');
                $dath = $echeance->format('d/m/Y');
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
                'CNPI' => '',
                'RACI' => '',
                'MONT' => $this->fmt($total_ttc),
                'CODC' => 'C',  // INVERSÉ
                'CPTG' => '41100000',
                // 'CPTC' => $compteClient,
                'DATE' => $dateStr,
                'CLET' => '',
                'DATL' => '',
                'CPTA' => $compteClient,
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
                'CNPI' => '',
                'RACI' => '',
                'MONT' => $this->fmt($montant_articles_sans_consigne),
                'CODC' => 'D',  // INVERSÉ
                'CPTG' => $isFrance ? '70700300' : '70792300',
                // 'CPTC' => '',
                'DATE' => $dateStr,
                'CLET' => '',
                'DATL' => '',
                'CPTA' => $compteClient,
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
                    'CNPI' => '',
                    'RACI' => '',
                    'MONT' => $this->fmt($total_ht_shipping),
                    'CODC' => 'D',  // INVERSÉ
                    'CPTG' => $isFrance ? '70850300' : '70852300',
                    'CPTC' => '',
                    'DATE' => $dateStr,
                    'CLET' => '',
                    'DATL' => '',
                    'CPTA' => $compteClient,
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
            // Ne pas écrire de ligne TVA pour les avoirs à l'étranger (exo)
            if ($total_taxes != 0.0 && $isFrance) {
                $slipRows[] = $this->makeRow([
                    'TYPE' => 'E',
                    'JNAL' => $code_journal,
                    'NECR' => '',
                    'NPIE' => $slipNumber,
                    'DATP' => $slipDate->format('d/m/Y'),
                    'LIBE' => $label,
                    'DATH' => $dath,
                    'CNPI' => '',
                    'RACI' => '',
                    'MONT' => $this->fmt($total_taxes),
                    'CODC' => 'D',  // INVERSÉ
                    'CPTG' => '44570000',
                    'CPTC' => '',
                    'DATE' => $dateStr,
                    'CLET' => '',
                    'DATL' => '',
                    'CPTA' => $compteClient,
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
                    'CNPI' => '',
                    'RACI' => '',
                    'MONT' => $this->fmt($total_consigne),
                    'CODC' => 'D',  // INVERSÉ
                    'CPTG' => $isFrance ? '70710300' : '70712300',
                    'CPTC' => '',
                    'DATE' => $dateStr,
                    'CLET' => '',
                    'DATL' => '',
                    'CPTA' => $compteClient,
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
     * Export XLSX (Office Open XML) standalone - sans dépendance externe
     */
    protected function exportXlsx(array $rows)
    {
        $filename = 'export_comptable_' . date('Ymd_His') . '.xlsx';
        $tempDir = sys_get_temp_dir() . '/xlsx_' . uniqid();
        mkdir($tempDir);

        // Créer la structure Office Open XML
        $this->createXlsxStructure($tempDir, $rows);

        // Créer le fichier ZIP
        $zipFile = $tempDir . '.zip';
        $this->zipDirectory($tempDir, $zipFile);

        // Envoyer le fichier
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($zipFile));
        readfile($zipFile);

        // Nettoyage
        $this->deleteDirectory($tempDir);
        unlink($zipFile);
    }

    protected function createXlsxStructure($dir, $rows)
    {
        // Structure de base
        mkdir($dir . '/_rels');
        mkdir($dir . '/docProps');
        mkdir($dir . '/xl');
        mkdir($dir . '/xl/_rels');
        mkdir($dir . '/xl/worksheets');

        // [Content_Types].xml
        file_put_contents($dir . '/[Content_Types].xml', $this->getContentTypes());

        // _rels/.rels
        file_put_contents($dir . '/_rels/.rels', $this->getRelsRoot());

        // docProps/app.xml
        file_put_contents($dir . '/docProps/app.xml', $this->getAppXml());

        // docProps/core.xml
        file_put_contents($dir . '/docProps/core.xml', $this->getCoreXml());

        // xl/_rels/workbook.xml.rels
        file_put_contents($dir . '/xl/_rels/workbook.xml.rels', $this->getWorkbookRels());

        // xl/workbook.xml
        file_put_contents($dir . '/xl/workbook.xml', $this->getWorkbookXml());

        // xl/styles.xml
        file_put_contents($dir . '/xl/styles.xml', $this->getStylesXml());

        // xl/worksheets/sheet1.xml (avec les données)
        file_put_contents($dir . '/xl/worksheets/sheet1.xml', $this->getSheetXml($rows));
    }

    protected function getContentTypes()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
            '<Default Extension="xml" ContentType="application/xml"/>' .
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
            '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
            '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
            '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>' .
            '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>' .
            '</Types>';
    }

    protected function getRelsRoot()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
            '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>' .
            '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>' .
            '</Relationships>';
    }

    protected function getAppXml()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">' .
            '<Application>PrestaShop Export Comptable</Application>' .
            '</Properties>';
    }

    protected function getCoreXml()
    {
        $now = date('Y-m-d\TH:i:s\Z');
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" ' .
            'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" ' .
            'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' .
            '<dc:creator>PrestaShop</dc:creator>' .
            '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>' .
            '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>' .
            '</cp:coreProperties>';
    }

    protected function getWorkbookRels()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
            '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
            '</Relationships>';
    }

    protected function getWorkbookXml()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
            '<sheets>' .
            '<sheet name="Export Comptable" sheetId="1" r:id="rId1"/>' .
            '</sheets>' .
            '</workbook>';
    }

    protected function getStylesXml()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<fonts count="2">' .
            '<font><sz val="11"/><name val="Calibri"/></font>' .
            '<font><b/><sz val="11"/><name val="Calibri"/></font>' .
            '</fonts>' .
            '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>' .
            '<borders count="1"><border><left/><right/><top/><bottom/></border></borders>' .
            '<cellXfs count="2">' .
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>' .
            '<xf numFmtId="0" fontId="1" fillId="0" borderId="0"/>' .
            '</cellXfs>' .
            '</styleSheet>';
    }

    protected function getSheetXml($rows)
    {
        $headers = $this->getHeaders();
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<sheetData>';

        $rowNum = 1;

        // En-têtes ligne 1 (avec style gras = s="1")
        $xml .= '<row r="' . $rowNum . '">';
        $colNum = 0;
        foreach ($headers[0] as $header) {
            $cellRef = $this->columnLetter($colNum) . $rowNum;
            $xml .= '<c r="' . $cellRef . '" t="inlineStr" s="1"><is><t>' . htmlspecialchars($header, ENT_XML1, 'UTF-8') . '</t></is></c>';
            $colNum++;
        }
        $xml .= '</row>';
        $rowNum++;

        // En-têtes ligne 2 (avec style gras = s="1")
        $xml .= '<row r="' . $rowNum . '">';
        $colNum = 0;
        foreach ($headers[1] as $header) {
            $cellRef = $this->columnLetter($colNum) . $rowNum;
            $xml .= '<c r="' . $cellRef . '" t="inlineStr" s="1"><is><t>' . htmlspecialchars($header, ENT_XML1, 'UTF-8') . '</t></is></c>';
            $colNum++;
        }
        $xml .= '</row>';
        $rowNum++;

        // Données
        foreach ($rows as $invoiceRows) {
            foreach ($invoiceRows as $r) {
                $xml .= '<row r="' . $rowNum . '">';
                $colNum = 0;
                foreach (array_values($r) as $cell) {
                    $cellRef = $this->columnLetter($colNum) . $rowNum;
                    $xml .= '<c r="' . $cellRef . '" t="inlineStr"><is><t>' . htmlspecialchars($cell, ENT_XML1, 'UTF-8') . '</t></is></c>';
                    $colNum++;
                }
                $xml .= '</row>';
                $rowNum++;
            }
        }

        $xml .= '</sheetData></worksheet>';
        return $xml;
    }

    protected function columnLetter($col)
    {
        $letter = '';
        while ($col >= 0) {
            $letter = chr($col % 26 + 65) . $letter;
            $col = floor($col / 26) - 1;
        }
        return $letter;
    }

    protected function zipDirectory($source, $destination)
    {
        $zip = new ZipArchive();
        if ($zip->open($destination, ZipArchive::CREATE) !== true) {
            die('Impossible de créer le fichier ZIP');
        }

        $source = realpath($source);
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($source) + 1);
                $zip->addFile($filePath, $relativePath);
            }
        }

        $zip->close();
    }

    protected function deleteDirectory($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
