<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Script cron pour exporter les écritures comptables de la veille au format XLSX
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

    $filename = 'export_comptable_' . $date_from . '.xlsx';
    $filepath = $exportsDir . $filename;
    $tempDir = sys_get_temp_dir() . '/xlsx_' . uniqid();
    if (!mkdir($tempDir)) {
        PrestaShopLogger::addLog('[CRON] Erreur création dossier temporaire : ' . $tempDir, 3);
        die("Erreur création dossier temporaire\n");
    }

    ExportComptableTools::createXlsxStructure($tempDir, $rows);
    PrestaShopLogger::addLog('[CRON] Structure XLSX créée dans : ' . $tempDir, 1);

    $zipFile = $tempDir . '.zip';
    ExportComptableTools::zipDirectory($tempDir, $zipFile);
    PrestaShopLogger::addLog('[CRON] ZIP créé : ' . $zipFile, 1);

    if (!rename($zipFile, $filepath)) {
        PrestaShopLogger::addLog('[CRON] Erreur renommage ZIP en XLSX', 3);
        die("Erreur renommage ZIP en XLSX\n");
    }
    PrestaShopLogger::addLog('[CRON] Fichier XLSX généré : ' . $filepath, 1);

    ExportComptableTools::deleteDirectory($tempDir);
    PrestaShopLogger::addLog('[CRON] Dossier temporaire supprimé : ' . $tempDir, 1);

    echo "Export comptable XLSX généré : $filepath\n";
} catch (Exception $e) {
    PrestaShopLogger::addLog('[CRON] Exception : ' . $e->getMessage(), 3);
    echo "Erreur lors de l\'export comptable : " . $e->getMessage() . "\n";
}
