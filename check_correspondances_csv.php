#!/usr/bin/env php
<?php
// Script de contrôle du CSV correspondances_id_as400.csv
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
    if (count($data) < 2) {
        echo "Ligne $row : colonnes manquantes\n";
        $errors++;
        continue;
    }
    if (trim($data[0]) === '' || trim($data[1]) === '') {
        echo "Ligne $row : valeur vide\n";
        $errors++;
    }
}
fclose($handle);
if ($errors === 0) {
    echo "Aucune anomalie détectée dans le CSV.\n";
} else {
    echo "$errors anomalie(s) détectée(s) dans le CSV.\n";
}
