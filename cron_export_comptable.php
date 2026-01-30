<?php
// Script cron pour exporter les écritures comptables de la veille au format XLSX
// Usage : à appeler en tâche cron chaque jour

require_once(dirname(__FILE__) . '/../../config/config.inc.php');
require_once(dirname(__FILE__) . '/../../init.php');
require_once(dirname(__FILE__) . '/export_comptable.php');

$date_from = date('Y-m-d', strtotime('-1 day'));
$date_to = $date_from;

$module = Module::getInstanceByName('export_comptable');
if (!$module) {
    die("Module export_comptable non trouvé\n");
}

// Récupérer les lignes à exporter (même logique que le BO)
$controller = new AdminExportComptableController();
$rows = $controller->getAccountingRows($date_from, $date_to);

// Générer le nom du fichier
$filename = 'export_comptable_' . $date_from . '.xlsx';
$filepath = __DIR__ . '/exports/' . $filename;
$tempDir = sys_get_temp_dir() . '/xlsx_' . uniqid();
mkdir($tempDir);

// Créer la structure Office Open XML
$controller->createXlsxStructure($tempDir, $rows);

// Créer le fichier ZIP
$zipFile = $tempDir . '.zip';
$controller->zipDirectory($tempDir, $zipFile);

// Renommer le zip en .xlsx
rename($zipFile, $filepath);

// Nettoyer le dossier temporaire
$controller->deleteDirectory($tempDir);
echo "Export comptable XLSX généré : $filepath\n";
