<?php
// Classe utilitaire pour l'export comptable sans dépendance au contexte BO

class ExportComptableTools
{
    public static function getAccountingRows($date_from, $date_to)
    {
        $groups = [];
        $invoiceGroups = self::getInvoiceRows($date_from, $date_to);
        $groups = array_merge($groups, $invoiceGroups);
        $creditSlipGroups = self::getCreditSlipRows($date_from, $date_to);
        $groups = array_merge($groups, $creditSlipGroups);
        return $groups;
    }

    protected static function getInvoiceRows($date_from, $date_to)
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
            $label = trim($inv['firstname'] . ' ' . $inv['lastname']);
            if (!empty($inv['company'])) {
                $label .= ' - ' . $inv['company'];
            }
            $label = mb_strtoupper($label, 'UTF-8');
            // Nettoyer les caractères spéciaux pour LD Compta
            $label = self::cleanLabel($label);
            $customerId = str_pad($inv['id_customer'], 5, '0', STR_PAD_LEFT);
            $compteClient = 'T' . $customerId;
            $total_ttc = (float) $inv['total_paid_tax_incl'];
            $total_ht_articles = (float) $inv['total_products_ht'];
            $total_ht_shipping = (float) $inv['shipping_ht'];
            $total_consigne = self::getConsigneTotalForOrder($inv['id_order']);
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
                } else {
                    $paymentMethod = $pm;
                }
            }
            $invoiceRows[] = self::makeRow([
                'TYPE' => 'E',
                'JNAL' => $code_journal,
                'NECR' => '',
                'NPIE' => $invoiceNumber,
                'DATP' => $invoiceDate->format('d/m/Y'),
                'LIBE' => $label,
                'DATH' => $dath,
                'CNPI' => 'FV',
                'RACI' => '',
                'MONT' => self::fmt($total_ttc),
                'CODC' => 'D',
                'CPTG' => '41100000',
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
            $invoiceRows[] = self::makeRow([
                'TYPE' => 'E',
                'JNAL' => $code_journal,
                'NECR' => '',
                'NPIE' => $invoiceNumber,
                'DATP' => $invoiceDate->format('d/m/Y'),
                'LIBE' => $label,
                'DATH' => $dath,
                'CNPI' => 'FV',
                'RACI' => '',
                'MONT' => self::fmt($montant_articles_sans_consigne),
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
                $invoiceRows[] = self::makeRow([
                    'TYPE' => 'E',
                    'JNAL' => $code_journal,
                    'NECR' => '',
                    'NPIE' => $invoiceNumber,
                    'DATP' => $invoiceDate->format('d/m/Y'),
                    'LIBE' => $label,
                    'DATH' => $dath,
                    'CNPI' => 'FV',
                    'RACI' => '',
                    'MONT' => self::fmt($total_ht_shipping),
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
                $invoiceRows[] = self::makeRow([
                    'TYPE' => 'E',
                    'JNAL' => $code_journal,
                    'NECR' => '',
                    'NPIE' => $invoiceNumber,
                    'DATP' => $invoiceDate->format('d/m/Y'),
                    'LIBE' => $label,
                    'DATH' => $dath,
                    'CNPI' => 'FV',
                    'RACI' => '',
                    'MONT' => self::fmt($total_taxes),
                    'CODC' => 'C',
                    'CPTG' => '44570000',
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
                $invoiceRows[] = self::makeRow([
                    'TYPE' => 'E',
                    'JNAL' => $code_journal,
                    'NECR' => '',
                    'NPIE' => $invoiceNumber,
                    'DATP' => $invoiceDate->format('d/m/Y'),
                    'LIBE' => $label,
                    'DATH' => $dath,
                    'CNPI' => 'FV',
                    'RACI' => '',
                    'MONT' => self::fmt($total_consigne),
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

    protected static function getCreditSlipRows($date_from, $date_to)
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
            $label = trim($slip['firstname'] . ' ' . $slip['lastname']);
            if (!empty($slip['company'])) {
                $label .= ' - ' . $slip['company'];
            }
            $label = mb_strtoupper($label, 'UTF-8');
            // Nettoyer les caractères spéciaux pour LD Compta
            $label = self::cleanLabel($label);
            $customerId = str_pad($slip['id_customer'], 5, '0', STR_PAD_LEFT);
            $compteClient = 'T' . $customerId;
            $total_ttc = (float) $slip['total_products_tax_incl'] + (float) $slip['total_shipping_tax_incl'];
            $total_ht_articles = (float) $slip['total_products_tax_excl'];
            $total_ht_shipping = (float) $slip['total_shipping_tax_excl'];
            $total_consigne = self::getConsigneTotalForOrder($slip['id_order']);
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
                } else {
                    $paymentMethod = $pm;
                }
            }
            $slipRows[] = self::makeRow([
                'TYPE' => 'E',
                'JNAL' => $code_journal,
                'NECR' => '',
                'NPIE' => $slipNumber,
                'DATP' => $slipDate->format('d/m/Y'),
                'LIBE' => $label,
                'DATH' => $dath,
                'CNPI' => 'FV',
                'RACI' => '',
                'MONT' => self::fmt($total_ttc),
                'CODC' => 'C',
                'CPTG' => '41100000',
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
            $slipRows[] = self::makeRow([
                'TYPE' => 'E',
                'JNAL' => $code_journal,
                'NECR' => '',
                'NPIE' => $slipNumber,
                'DATP' => $slipDate->format('d/m/Y'),
                'LIBE' => $label,
                'DATH' => $dath,
                'CNPI' => 'FV',
                'RACI' => '',
                'MONT' => self::fmt($montant_articles_sans_consigne),
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
                $slipRows[] = self::makeRow([
                    'TYPE' => 'E',
                    'JNAL' => $code_journal,
                    'NECR' => '',
                    'NPIE' => $slipNumber,
                    'DATP' => $slipDate->format('d/m/Y'),
                    'LIBE' => $label,
                    'DATH' => $dath,
                    'CNPI' => 'FV',
                    'RACI' => '',
                    'MONT' => self::fmt($total_ht_shipping),
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
                $slipRows[] = self::makeRow([
                    'TYPE' => 'E',
                    'JNAL' => $code_journal,
                    'NECR' => '',
                    'NPIE' => $slipNumber,
                    'DATP' => $slipDate->format('d/m/Y'),
                    'LIBE' => $label,
                    'DATH' => $dath,
                    'CNPI' => 'FV',
                    'RACI' => '',
                    'MONT' => self::fmt($total_taxes),
                    'CODC' => 'D',
                    'CPTG' => '44570000',
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
                $slipRows[] = self::makeRow([
                    'TYPE' => 'E',
                    'JNAL' => $code_journal,
                    'NECR' => '',
                    'NPIE' => $slipNumber,
                    'DATP' => $slipDate->format('d/m/Y'),
                    'LIBE' => $label,
                    'DATH' => $dath,
                    'CNPI' => 'FV',
                    'RACI' => '',
                    'MONT' => self::fmt($total_consigne),
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

    protected static function getConsigneTotalForOrder($id_order)
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

    protected static function fmt($number)
    {
        return number_format((float) $number, 2, ',', '');
    }

    protected static function makeRow(array $map)
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
        $row = [];
        foreach ($keys as $k) {
            $row[$k] = isset($map[$k]) ? $map[$k] : '';
        }
        return $row;
    }

    public static function getHeaders()
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
            ]
        ];
    }

    /**
     * Génère le contenu CSV avec séparateur point-virgule
     */
    public static function generateCsvContent($rows)
    {
        $output = '';
        $headers = self::getHeaders();

        // Ligne 1 : descriptions des colonnes
        $output .= self::arrayToCsvLine($headers[0]);

        // Ligne 2 : codes des colonnes
        $output .= self::arrayToCsvLine($headers[1]);

        // Données
        foreach ($rows as $invoiceRows) {
            foreach ($invoiceRows as $row) {
                $output .= self::arrayToCsvLine(array_values($row));
            }
        }

        return $output;
    }

    /**
     * Convertit un tableau en ligne CSV avec séparateur ;
     * Utilise CRLF pour Windows/WinDev et guillemets uniquement si nécessaire
     */
    protected static function arrayToCsvLine($array)
    {
        $line = '';
        foreach ($array as $index => $value) {
            if ($index > 0) {
                $line .= ';';
            }
            // Convertir en string
            $value = (string) $value;

            // Entourer de guillemets seulement si le champ contient des caractères spéciaux
            if (
                strpos($value, ';') !== false || strpos($value, '"') !== false ||
                strpos($value, "\n") !== false || strpos($value, "\r") !== false
            ) {
                // Échapper les guillemets
                $value = str_replace('"', '""', $value);
                $line .= '"' . $value . '"';
            } else {
                $line .= $value;
            }
        }
        // Utiliser CRLF pour Windows/WinDev
        return $line . "\r\n";
    }

    /**
     * Nettoie un libellé pour l'export comptable
     * Supprime ou remplace les caractères problématiques
     */
    public static function cleanLabel($label)
    {
        // Supprimer les caractères de contrôle
        $label = preg_replace('/[\x00-\x1F\x7F]/u', '', $label);

        // Remplacer certains caractères accentués par leur équivalent
        $label = str_replace(
            [
                'À',
                'Á',
                'Â',
                'Ã',
                'Ä',
                'Å',
                'Æ',
                'Ç',
                'È',
                'É',
                'Ê',
                'Ë',
                'Ì',
                'Í',
                'Î',
                'Ï',
                'Ð',
                'Ñ',
                'Ò',
                'Ó',
                'Ô',
                'Õ',
                'Ö',
                'Ø',
                'Ù',
                'Ú',
                'Û',
                'Ü',
                'Ý',
                'Þ',
                'ß',
                'à',
                'á',
                'â',
                'ã',
                'ä',
                'å',
                'æ',
                'ç',
                'è',
                'é',
                'ê',
                'ë',
                'ì',
                'í',
                'î',
                'ï',
                'ð',
                'ñ',
                'ò',
                'ó',
                'ô',
                'õ',
                'ö',
                'ø',
                'ù',
                'ú',
                'û',
                'ü',
                'ý',
                'þ',
                'ÿ',
                'Œ',
                'œ'
            ],
            [
                'A',
                'A',
                'A',
                'A',
                'A',
                'A',
                'AE',
                'C',
                'E',
                'E',
                'E',
                'E',
                'I',
                'I',
                'I',
                'I',
                'D',
                'N',
                'O',
                'O',
                'O',
                'O',
                'O',
                'O',
                'U',
                'U',
                'U',
                'U',
                'Y',
                'TH',
                'SS',
                'A',
                'A',
                'A',
                'A',
                'A',
                'A',
                'AE',
                'C',
                'E',
                'E',
                'E',
                'E',
                'I',
                'I',
                'I',
                'I',
                'D',
                'N',
                'O',
                'O',
                'O',
                'O',
                'O',
                'O',
                'U',
                'U',
                'U',
                'U',
                'Y',
                'TH',
                'Y',
                'OE',
                'OE'
            ],
            $label
        );

        // Limiter la longueur (sécurité)
        $label = mb_substr($label, 0, 100, 'UTF-8');

        return $label;
    }
}
