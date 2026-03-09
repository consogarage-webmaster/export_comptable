<?php

// Lorsque ce script est exécuté en standalone (CLI ou accès direct),
// PrestaShop n'est pas encore chargé — on le bootstrape.
// Lorsqu'il est include() depuis install(), PS est déjà chargé.
$_seed_standalone = !defined('_PS_VERSION_');
if ($_seed_standalone) {
    define('_PS_ADMIN_DIR_', getcwd());
    require_once dirname(__FILE__) . '/../../config/config.inc.php';
    require_once dirname(__FILE__) . '/../../init.php';
}

if (!defined('_MYSQL_ENGINE_')) {
    define('_MYSQL_ENGINE_', 'InnoDB');
}

$table = _DB_PREFIX_ . 'export_comptable_id_as400';

$sql = "CREATE TABLE IF NOT EXISTS `$table` (
    `id_customer` INT NOT NULL,
    `id_as400` VARCHAR(64) NOT NULL,
    PRIMARY KEY (`id_customer`)
) ENGINE=" . _MYSQL_ENGINE_ . " DEFAULT CHARSET=utf8;";

if (!Db::getInstance()->execute($sql)) {
    $error = Db::getInstance()->getMsgError();
    if ($_seed_standalone) {
        die("Erreur lors de la création de la table : $error\n");
    }
    return false;
}

if ($_seed_standalone && method_exists(Db::getInstance(), 'getDatabaseName')) {
    echo "Base utilisée : " . Db::getInstance()->getDatabaseName() . "\n";
}

$csvFile = __DIR__ . '/doc/correspondances_id_as400-V2.csv';
if (!file_exists($csvFile)) {
    if ($_seed_standalone) {
        die("Fichier CSV introuvable: $csvFile\n");
    }
    return false;
}

$handle = fopen($csvFile, 'r');
if (!$handle) {
    if ($_seed_standalone) {
        die("Impossible d'ouvrir le fichier CSV\n");
    }
    return false;
}

Db::getInstance()->execute("TRUNCATE TABLE `$table`");

$row = 0;
$inserted = 0;
while (($data = fgetcsv($handle, 1000, ';')) !== false) {
    if ($row === 0 && (stripos($data[0], 'id') !== false || stripos($data[1], 'as400') !== false)) {
        $row++;
        continue;
    }
    $id_customer = (int) preg_replace('/\D/', '', $data[0]);
    $id_as400 = pSQL($data[1]);
    if ($_seed_standalone) {
        echo "Ligne $row : id_customer='$id_customer' id_as400='$id_as400'\n";
    }
    if ($id_customer && $id_as400) {
        if (
            Db::getInstance()->insert('export_comptable_id_as400', [
                'id_customer' => $id_customer,
                'id_as400' => $id_as400
            ])
        ) {
            $inserted++;
        } elseif ($_seed_standalone) {
            echo "  -> ECHEC INSERTION\n";
        }
    } elseif ($_seed_standalone) {
        echo "  -> LIGNE IGNORÉE\n";
    }
    $row++;
}
fclose($handle);

if ($_seed_standalone) {
    echo "Import terminé : $row lignes traitées, $inserted insertions.\n";
    $count = Db::getInstance()->getValue("SELECT COUNT(*) FROM `$table`");
    echo "Nombre de lignes dans la table après import : $count\n";
}
