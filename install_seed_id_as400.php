<?php
// Script d'installation pour insérer les correspondances id_customer <-> id_as400
// À exécuter lors de l'installation du module


define('_PS_ADMIN_DIR_', getcwd());
require_once dirname(__FILE__) . '/../../config/config.inc.php';
require_once dirname(__FILE__) . '/../../init.php';

// Si _MYSQL_ENGINE_ n'est pas défini, le définir par défaut
if (!defined('_MYSQL_ENGINE_')) {
    define('_MYSQL_ENGINE_', 'InnoDB');
}

$table = _DB_PREFIX_ . 'export_comptable_id_as400';

// Création de la table si elle n'existe pas

$sql = "CREATE TABLE IF NOT EXISTS `$table` (
    `id_customer` INT NOT NULL,
    `id_as400` VARCHAR(64) NOT NULL,
    PRIMARY KEY (`id_customer`)
) ENGINE=" . _MYSQL_ENGINE_ . " DEFAULT CHARSET=utf8;";

if (!Db::getInstance()->execute($sql)) {
    $error = Db::getInstance()->getMsgError();
    die("Erreur lors de la création de la table : $error\n");
}

// Affichage debug : base utilisée
if (method_exists(Db::getInstance(), 'getDatabaseName')) {
    echo "Base utilisée : " . Db::getInstance()->getDatabaseName() . "\n";
}

// Lecture du CSV
// Utiliser le fichier V2
$csvFile = __DIR__ . '/doc/correspondances_id_as400-V2.csv';
if (!file_exists($csvFile)) {
    die("Fichier CSV introuvable: $csvFile\n");
}

$handle = fopen($csvFile, 'r');
if (!$handle) {
    die("Impossible d'ouvrir le fichier CSV\n");
}

// Suppression des anciennes données
Db::getInstance()->execute("TRUNCATE TABLE `$table`");

$row = 0;
$inserted = 0;
while (($data = fgetcsv($handle, 1000, ';')) !== false) {
    // Sauter l'en-tête si présente
    if ($row === 0 && (stripos($data[0], 'id') !== false || stripos($data[1], 'as400') !== false)) {
        $row++;
        continue;
    }
    $id_customer = (int) preg_replace('/\D/', '', $data[0]);
    $id_as400 = pSQL($data[1]);
    // Debug : afficher chaque ligne lue
    echo "Ligne $row : id_customer='$id_customer' id_as400='$id_as400'\n";
    if ($id_customer && $id_as400) {
        if (
            Db::getInstance()->insert('export_comptable_id_as400', [
                'id_customer' => $id_customer,
                'id_as400' => $id_as400
            ])
        ) {
            $inserted++;
        } else {
            echo "  -> ECHEC INSERTION\n";
        }
    } else {
        echo "  -> LIGNE IGNORÉE\n";
    }
    $row++;
}
fclose($handle);

// Affichage debug : nombre d'insertions
echo "Import terminé : $row lignes traitées, $inserted insertions.\n";

// Affichage debug : nombre de lignes dans la table après import
$count = Db::getInstance()->getValue("SELECT COUNT(*) FROM `$table`");
echo "Nombre de lignes dans la table après import : $count\n";
