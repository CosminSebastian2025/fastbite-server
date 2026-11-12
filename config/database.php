<?php
/**
 * Configurazione MySQL condivisa da API e pannello admin.
 *
 * Valori di default per XAMPP:
 *   host 127.0.0.1, porta 3306, database AppFood, utente root, password vuota.
 *
 * Ogni valore può essere sovrascritto, in ordine di priorità:
 *   1) config/database.local.php  (file generato dall'installazione, non versionato)
 *   2) variabili d'ambiente FASTBITE_DB_HOST / PORT / NAME / USER / PASS
 *   3) valori di default qui sotto
 *
 * Questo file non contiene segreti: le credenziali reali vanno nel file locale.
 */

$dbConfig = array(
    'host'    => getenv('FASTBITE_DB_HOST') !== false ? getenv('FASTBITE_DB_HOST') : '127.0.0.1',
    'port'    => getenv('FASTBITE_DB_PORT') !== false ? (int)getenv('FASTBITE_DB_PORT') : 3306,
    'name'    => getenv('FASTBITE_DB_NAME') !== false ? getenv('FASTBITE_DB_NAME') : 'AppFood',
    'user'    => getenv('FASTBITE_DB_USER') !== false ? getenv('FASTBITE_DB_USER') : 'root',
    'pass'    => getenv('FASTBITE_DB_PASS') !== false ? getenv('FASTBITE_DB_PASS') : '',
    'charset' => 'utf8mb4',
);

$localConfig = __DIR__ . '/database.local.php';
if (is_file($localConfig)) {
    $override = require $localConfig;
    if (is_array($override)) {
        $dbConfig = array_merge($dbConfig, $override);
    }
}

return $dbConfig;
