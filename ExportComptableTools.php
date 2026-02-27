<?php
// Classe utilitaire pour l'export comptable sans dépendance au contexte BO

class ExportComptableTools
{
    // Fonction getIdAs400 supprimée, le LEFT JOIN SQL est utilisé directement pour récupérer id_as400
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
                as4.id_as400 AS id_as400true,
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
            EFT LEFT JOIN ' . _DB_PREFIX_ . 'export_comptable_id_as400 as4 ON (as4.id_customer = c.id_customer)
            ' . $where . $orderBy . $limit;
        $invoices = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);
        $groups = [];
        foreach ($invoices as $inv) {
            $invoiceRows = [];
            $invoiceNumber = (string) $inv['invoice_number'];
            $invoiceDate = new DateTime($inv['invoice_date']);
            $dateStr = $invoiceDate->format('d/m/Y');
            $isFrance = (strtoupper((string) $inv['country_iso']) === 'FR');
            $label = trim($inv['firstname'] . ' ' . $inv['lastname']);
            if (!empty($inv['company'])) {
                $label .= ' - ' . $inv['company'];
            }
            $label = mb_strtoupper($label, 'UTF-8');
            // Nettoyer les caractères spéciaux pour LD Compta
            $label = self::cleanLabel($label);
            // Afficher id_customer et id_as400true (ou null) dans CPTA
            $id_customer = $inv['id_customer'];
            $id_as400true = isset($inv['id_as400true']) ? $inv['id_as400true'] : null;
            $cpta_display = 'T' . str_pad($id_customer, 5, '0', STR_PAD_LEFT);
            if ($id_as400true !== null && $id_as400true !== '') {
                $cpta_display .= ' / AS400:' . str_pad($id_as400true, 5, '0', STR_PAD_LEFT);
            } else {
                $cpta_display .= ' / AS400:null';
            }
            $compteClient = $cpta_display;
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
                } elseif (stripos($pm, 'chèque') !== false || stripos($pm, 'cheque') !== false) {
                    $paymentMethod = 'CH';
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
                'CNPI' => 'FC',
                'RACI' => '',
                'MONT' => self::fmt($total_ttc),
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
            $invoiceRows[] = self::makeRow([
                'TYPE' => 'E',
                'JNAL' => $code_journal,
                'NECR' => '',
                'NPIE' => $invoiceNumber,
                'DATP' => $invoiceDate->format('d/m/Y'),
                'LIBE' => $label,
                'DATH' => $dath,
                'CNPI' => 'FC',
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
                    'CNPI' => 'FC',
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
                    'CNPI' => 'FC',
                    'RACI' => '',
                    'MONT' => self::fmt($total_taxes),
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
                $invoiceRows[] = self::makeRow([
                    'TYPE' => 'E',
                    'JNAL' => $code_journal,
                    'NECR' => '',
                    'NPIE' => $invoiceNumber,
                    'DATP' => $invoiceDate->format('d/m/Y'),
                    'LIBE' => $label,
                    'DATH' => $dath,
                    'CNPI' => 'FC',
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
            $dateStr = $slipDate->format('d/m/Y');
            $isFrance = (strtoupper((string) $slip['country_iso']) === 'FR');
            $label = trim($slip['firstname'] . ' ' . $slip['lastname']);
            if (!empty($slip['company'])) {
                $label .= ' - ' . $slip['company'];
            }
            $label = mb_strtoupper($label, 'UTF-8');
            // Nettoyer les caractères spéciaux pour LD Compta
            $label = self::cleanLabel($label);
            // Afficher id_customer et id_as400true (ou null) dans CPTA
            $id_customer = $slip['id_customer'];
            $id_as400true = isset($slip['id_as400true']) ? $slip['id_as400true'] : null;
            $cpta_display = 'T' . str_pad($id_customer, 5, '0', STR_PAD_LEFT);
            if ($id_as400true !== null && $id_as400true !== '') {
                $cpta_display .= ' / AS400:' . str_pad($id_as400true, 5, '0', STR_PAD_LEFT);
            } else {
                $cpta_display .= ' / AS400:null';
            }
            $compteClient = $cpta_display;
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
                } elseif (stripos($pm, 'chèque') !== false || stripos($pm, 'cheque') !== false) {
                    $paymentMethod = 'CH';
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
                'CNPI' => 'AC',
                'RACI' => '',
                'MONT' => self::fmt($total_ttc),
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
            $slipRows[] = self::makeRow([
                'TYPE' => 'E',
                'JNAL' => $code_journal,
                'NECR' => '',
                'NPIE' => $slipNumber,
                'DATP' => $slipDate->format('d/m/Y'),
                'LIBE' => $label,
                'DATH' => $dath,
                'CNPI' => 'AC',
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
                    'CNPI' => 'AC',
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
                    'CNPI' => 'AC',
                    'RACI' => '',
                    'MONT' => self::fmt($total_taxes),
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
                $slipRows[] = self::makeRow([
                    'TYPE' => 'E',
                    'JNAL' => $code_journal,
                    'NECR' => '',
                    'NPIE' => $slipNumber,
                    'DATP' => $slipDate->format('d/m/Y'),
                    'LIBE' => $label,
                    'DATH' => $dath,
                    'CNPI' => 'AC',
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

    public static function createXlsxStructure($dir, $rows)
    {
        mkdir($dir . '/_rels');
        mkdir($dir . '/docProps');
        mkdir($dir . '/xl');
        mkdir($dir . '/xl/_rels');
        mkdir($dir . '/xl/worksheets');
        file_put_contents($dir . '/[Content_Types].xml', self::getContentTypes());
        file_put_contents($dir . '/_rels/.rels', self::getRelsRoot());
        file_put_contents($dir . '/docProps/app.xml', self::getAppXml());
        file_put_contents($dir . '/docProps/core.xml', self::getCoreXml());
        file_put_contents($dir . '/xl/_rels/workbook.xml.rels', self::getWorkbookRels());
        file_put_contents($dir . '/xl/workbook.xml', self::getWorkbookXml());
        file_put_contents($dir . '/xl/styles.xml', self::getStylesXml());
        file_put_contents($dir . '/xl/worksheets/sheet1.xml', self::getSheetXml($rows));
    }

    protected static function getContentTypes()
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

    protected static function getRelsRoot()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
            '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>' .
            '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>' .
            '</Relationships>';
    }

    protected static function getAppXml()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">' .
            '<Application>PrestaShop Export Comptable</Application>' .
            '</Properties>';
    }

    protected static function getCoreXml()
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

    protected static function getWorkbookRels()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
            '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
            '</Relationships>';
    }

    protected static function getWorkbookXml()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
            '<sheets>' .
            '<sheet name="Export Comptable" sheetId="1" r:id="rId1"/>' .
            '</sheets>' .
            '</workbook>';
    }

    protected static function getStylesXml()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<numFmts count="2">' .
            '<numFmt numFmtId="164" formatCode="dd/mm/yyyy"/>' .
            '<numFmt numFmtId="165" formatCode="#,##0.00"/>' .
            '</numFmts>' .
            '<fonts count="2">' .
            '<font><sz val="11"/><name val="Calibri"/></font>' .
            '<font><b/><sz val="11"/><name val="Calibri"/></font>' .
            '</fonts>' .
            '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>' .
            '<borders count="1"><border><left/><right/><top/><bottom/></border></borders>' .
            '<cellXfs count="4">' .
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>' .
            '<xf numFmtId="0" fontId="1" fillId="0" borderId="0"/>' .
            '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" applyNumberFormat="1"/>' .
            '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" applyNumberFormat="1"/>' .
            '</cellXfs>' .
            '</styleSheet>';
    }

    protected static function getSheetXml($rows)
    {
        $headers = self::getHeaders();
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<sheetData>';

        $rowNum = 1;
        $xml .= '<row r="' . $rowNum . '">';
        $colNum = 0;
        foreach ($headers[0] as $header) {
            $cellRef = self::columnLetter($colNum) . $rowNum;
            $xml .= '<c r="' . $cellRef . '" t="inlineStr" s="1"><is><t>' . htmlspecialchars($header, ENT_XML1, 'UTF-8') . '</t></is></c>';
            $colNum++;
        }
        $xml .= '</row>';
        $rowNum++;

        $xml .= '<row r="' . $rowNum . '">';
        $colNum = 0;
        foreach ($headers[1] as $header) {
            $cellRef = self::columnLetter($colNum) . $rowNum;
            $xml .= '<c r="' . $cellRef . '" t="inlineStr" s="1"><is><t>' . htmlspecialchars($header, ENT_XML1, 'UTF-8') . '</t></is></c>';
            $colNum++;
        }
        $xml .= '</row>';
        $rowNum++;

        foreach ($rows as $invoiceRows) {
            foreach ($invoiceRows as $r) {
                $xml .= '<row r="' . $rowNum . '">';
                $colNum = 0;
                foreach (array_values($r) as $cell) {
                    $cellRef = self::columnLetter($colNum) . $rowNum;
                    if ($cell === '') {
                        $xml .= '<c r="' . $cellRef . '"/>';
                    } elseif ($colNum === 12) {
                        // Colonne DATE : supprimer apostrophes (ASCII + typographiques) puis forcer le format d/m/Y (année sur 4 chiffres)
                        $date = (string) $cell;
                        // Retirer apostrophe droite/’ gauche/‘ accent aigu diacritique qui peuvent préfixer la valeur
                        $date = preg_replace('/^[\x{0027}\x{2019}\x{2018}\x{00B4}]+/u', '', $date);
                        $date = trim($date);
                        if (preg_match('/^\d{2}\/\d{2}\/\d{2}$/', $date)) {
                            // Convertir d/m/y en d/m/Y
                            $dt = DateTime::createFromFormat('d/m/y', $date);
                            if ($dt)
                                $date = $dt->format('d/m/Y');
                        }
                        $dateSerial = self::dateToExcelSerial($date, $colNum);
                        if ($dateSerial !== null) {
                            $xml .= '<c r="' . $cellRef . '" s="2"><v>' . $dateSerial . '</v></c>';
                        } else {
                            $xml .= '<c r="' . $cellRef . '" t="inlineStr"><is><t>' . htmlspecialchars($date, ENT_XML1, 'UTF-8') . '</t></is></c>';
                        }
                    } else {
                        // Retirer toute apostrophe préfixe (ASCII + typographiques) avant détection
                        $cell = (string) $cell;
                        $cell = preg_replace('/^[\x{0027}\x{2019}\x{2018}\x{00B4}]+/u', '', $cell);
                        $cell = trim($cell);
                        $dateSerial = self::dateToExcelSerial($cell, $colNum);
                        $numberValue = self::numberToExcelValue($cell, $colNum);
                        if ($dateSerial !== null) {
                            $xml .= '<c r="' . $cellRef . '" s="2"><v>' . $dateSerial . '</v></c>';
                        } elseif ($numberValue !== null) {
                            $xml .= '<c r="' . $cellRef . '" s="3"><v>' . $numberValue . '</v></c>';
                        } else {
                            $xml .= '<c r="' . $cellRef . '" t="inlineStr"><is><t>' . htmlspecialchars($cell, ENT_XML1, 'UTF-8') . '</t></is></c>';
                        }
                    }
                    $colNum++;
                }
                $xml .= '</row>';
                $rowNum++;
            }
        }
        $xml .= '</sheetData></worksheet>';
        return $xml;
    }

    protected static function columnLetter($col)
    {
        $letter = '';
        while ($col >= 0) {
            $letter = chr($col % 26 + 65) . $letter;
            $col = floor($col / 26) - 1;
        }
        return $letter;
    }

    protected static function dateToExcelSerial($value, $colIndex)
    {
        if (!self::isDateColumn($colIndex)) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $formats = ['d/m/Y', 'd/m/y'];
        foreach ($formats as $format) {
            $dt = DateTime::createFromFormat($format, $value);
            if ($dt instanceof DateTime && $dt->format($format) === $value) {
                $base = new DateTime('1899-12-30');
                $days = (int) floor(($dt->getTimestamp() - $base->getTimestamp()) / 86400);
                return $days;
            }
        }

        return null;
    }

    protected static function numberToExcelValue($value, $colIndex)
    {
        if (!self::isNumberColumn($colIndex)) {
            return null;
        }

        $normalized = str_replace(["\xc2\xa0", ' '], '', (string) $value);
        $normalized = str_replace(',', '.', $normalized);
        // Si la valeur commence par un point (ex ".45"), préfixer par 0 pour obtenir "0.45"
        if (preg_match('/^(-?)\./', $normalized, $m)) {
            $normalized = $m[1] . '0' . substr($normalized, strlen($m[0]) - 1);
        }
        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }

        // Toujours renvoyer une valeur avec 2 décimales (séparateur point) pour que Excel affiche
        // systématiquement deux décimales via le format numérique
        $floatVal = (float) $normalized;
        return number_format($floatVal, 2, '.', '');
    }

    protected static function isDateColumn($colIndex)
    {
        // Colonnes contenant des dates : DATP (4), DATH (6), DATE (12)
        return in_array($colIndex, [4, 6, 12], true);
    }

    protected static function isNumberColumn($colIndex)
    {
        return $colIndex === 9;
    }

    public static function zipDirectory($source, $destination)
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

    public static function deleteDirectory($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? self::deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
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
                // Nettoyer apostrophes préfixes sur toutes les colonnes et formater certaines colonnes
                foreach ($row as $key => $val) {
                    $val = (string) $val;
                    // Retirer apostrophes ASCII et typographiques en tête
                    $val = preg_replace('/^[\x{0027}\x{2019}\x{2018}\x{00B4}]+/u', '', $val);
                    $val = trim($val);
                    // Colonne DATE -> forcer d/m/Y
                    if ($key === 'DATE') {
                        $val = self::formatDateCsv($val);
                    }
                    // Colonne MONT -> forcer deux décimales format français
                    if ($key === 'MONT') {
                        $norm = str_replace(["\xc2\xa0", ' '], '', $val);
                        $norm = str_replace(',', '.', $norm);
                        if ($norm !== '' && is_numeric($norm)) {
                            $val = self::fmt((float) $norm);
                        }
                    }
                    $row[$key] = $val;
                }
                $output .= self::arrayToCsvLine(array_values($row));
            }
        }
        return $output;
    }

    // Formatage DATE pour CSV
    public static function formatDateCsv($date)
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $dt = DateTime::createFromFormat('Y-m-d', $date);
            if ($dt)
                return $dt->format('d/m/Y');
        }
        // Si format jj/mm/aa -> convertir en jj/mm/YYYY
        if (preg_match('/^\d{2}\/\d{2}\/\d{2}$/', $date)) {
            $dt = DateTime::createFromFormat('d/m/y', $date);
            if ($dt)
                return $dt->format('d/m/Y');
        }
        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $date)) {
            return $date;
        }
        return $date;
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
