#!/usr/bin/env php
<?php
// Script pour afficher les lignes incorrectes du CSV correspondances_id_as400.csv
$csvFile = __DIR__ . '/doc/correspondances_id_as400.csv';
if (!file_exists($csvFile)) {
    die("Fichier CSV introuvable: $csvFile\n");
}

$handle = fopen($csvFile, 'r');
if (!$handle) {
    die("Impossible d'ouvrir le fichier CSV\n");
}

$row = 0;
$errors = 0;
while (($data = fgetcsv($handle, 1000, ';')) !== false) {
    $row++;
    // Ligne incorrecte : moins de 2 colonnes ou une colonne vide
    if (count($data) < 2 || trim($data[0]) === '' || trim($data[1]) === '') {
        echo "Ligne $row : (" . count($data) . " colonnes) ";
        foreach ($data as $i => $val) {
            echo "[" . $i . "] '" . addcslashes($val, "\0..\37\177\n\r\t") . "' ";
        }
        echo "\n";
        $errors++;
    }
}
fclose($handle);
if ($errors === 0) {
    echo "Aucune ligne incorrecte détectée dans le CSV.\n";
} else {
    echo "$errors ligne(s) incorrecte(s) détectée(s).\n";
}
