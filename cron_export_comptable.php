<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Script cron pour exporter les écritures comptables de la veille au format CSV
// Usage : à appeler en tâche cron chaque jour

require_once(dirname(__FILE__) . '/../../config/config.inc.php');
require_once(dirname(__FILE__) . '/../../init.php');
require_once(dirname(__FILE__) . '/export_comptable.php');
require_once(dirname(__FILE__) . '/ExportComptableTools.php');


$date_from = date('Y-m-d', strtotime('-1 day'));
$date_to = $date_from;

try {
    PrestaShopLogger::addLog('[CRON] Début export comptable - date : ' . $date_from, 1);

    $module = Module::getInstanceByName('export_comptable');
    if (!$module) {
        PrestaShopLogger::addLog('[CRON] Module export_comptable non trouvé', 3);
        die("Module export_comptable non trouvé\n");
    }

    $rows = ExportComptableTools::getAccountingRows($date_from, $date_to);
    PrestaShopLogger::addLog('[CRON] Nombre de groupes de lignes récupérés : ' . count($rows), 1);

    $exportsDir = __DIR__ . '/exports/';
    if (!is_dir($exportsDir)) {
        if (!mkdir($exportsDir, 0775, true)) {
            PrestaShopLogger::addLog('[CRON] Erreur création du dossier exports/ : ' . $exportsDir, 3);
            die("Erreur création du dossier exports/\n");
        } else {
            PrestaShopLogger::addLog('[CRON] Dossier exports/ créé automatiquement : ' . $exportsDir, 1);
        }
    }
    if (!is_writable($exportsDir)) {
        PrestaShopLogger::addLog('[CRON] Le dossier exports/ n\'est pas accessible en écriture : ' . $exportsDir, 3);
        die("Le dossier exports/ n'est pas accessible en écriture\n");
    }

    $filename = 'export_comptable_' . $date_from . '.csv';
    $filepath = $exportsDir . $filename;

    // Générer le contenu CSV
    $csvContent = ExportComptableTools::generateCsvContent($rows);
    PrestaShopLogger::addLog('[CRON] Contenu CSV généré : ' . strlen($csvContent) . ' octets', 1);

    // Ajouter BOM UTF-8 pour une meilleure compatibilité avec Excel
    $csvContent = "\xEF\xBB\xBF" . $csvContent;

    // Écrire le fichier
    if (file_put_contents($filepath, $csvContent) === false) {
        PrestaShopLogger::addLog('[CRON] Erreur écriture fichier CSV : ' . $filepath, 3);
        die("Erreur écriture fichier CSV\n");
    }

    PrestaShopLogger::addLog('[CRON] Fichier CSV généré : ' . $filepath, 1);

    echo "Export comptable CSV généré : $filepath\n";
} catch (Exception $e) {
    PrestaShopLogger::addLog('[CRON] Exception : ' . $e->getMessage(), 3);
    echo "Erreur lors de l'export comptable : " . $e->getMessage() . "\n";
}
