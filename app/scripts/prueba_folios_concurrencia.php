<?php

/**
 * Prueba de concurrencia de la reserva de folios, en el ambiente 'test'.
 * Crea un CAF ficticio de 200 folios, lanza 4 procesos que reservan 50 cada
 * uno, verifica que no haya duplicados ni faltantes, y limpia.
 *
 * Uso: php scripts/prueba_folios_concurrencia.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Folios;

if (($argv[1] ?? '') === '--hijo') {
    $folios = new Folios(db());
    for ($i = 0; $i < 50; $i++) {
        echo $folios->reservar('test', 39)['folio'], PHP_EOL;
    }
    exit(0);
}

$db = db();
$db->exec("DELETE FROM notas_credito WHERE ambiente = 'test'");
$db->exec("DELETE FROM boletas WHERE ambiente = 'test'");
$db->exec("DELETE FROM cafs WHERE ambiente = 'test'");
$db->exec("INSERT INTO cafs (ambiente, tipo_dte, folio_desde, folio_hasta, siguiente_folio, fecha_autorizacion, archivo)
           VALUES ('test', 39, 1, 200, 1, CURDATE(), 'prueba')");

$procesos = [];
for ($p = 0; $p < 4; $p++) {
    $procesos[] = popen(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --hijo 2>&1', 'r');
}
$salida = '';
$intercalados = 0;
foreach ($procesos as $proc) {
    $propios = array_map('intval', array_filter(explode("\n", $out = stream_get_contents($proc)), 'is_numeric'));
    // Si otro proceso reservó entre medio, los folios propios no son consecutivos.
    for ($i = 1; $i < count($propios); $i++) {
        $intercalados += (int) ($propios[$i] !== $propios[$i - 1] + 1);
    }
    $salida .= $out;
    pclose($proc);
}

$folios = array_map('intval', array_filter(explode("\n", $salida), 'is_numeric'));
sort($folios);
$enDb = (int) $db->query("SELECT COUNT(DISTINCT folio) FROM boletas WHERE ambiente = 'test'")->fetchColumn();

try {
    (new Folios($db))->reservar('test', 39);
    $agotado = 'NO (error: entregó un folio 201)';
} catch (RuntimeException $e) {
    $agotado = 'SI';
}

echo 'Folios reservados: ', count($folios), PHP_EOL;
echo 'Distintos: ', count(array_unique($folios)), PHP_EOL;
echo 'Rango completo 1..200: ', $folios === range(1, 200) ? 'SI' : 'NO', PHP_EOL;
echo 'Filas distintas en BD: ', $enDb, PHP_EOL;
echo 'Folio 201 rechazado: ', $agotado, PHP_EOL;
echo 'Saltos por reservas simultáneas de otros procesos: ', $intercalados, ($intercalados ? ' (hubo concurrencia real)' : ' (los procesos no se solaparon)'), PHP_EOL;
if (count($folios) !== 200) {
    echo 'Salida con errores:', PHP_EOL, substr($salida, 0, 1000), PHP_EOL;
}

$db->exec("DELETE FROM notas_credito WHERE ambiente = 'test'");
$db->exec("DELETE FROM boletas WHERE ambiente = 'test'");
$db->exec("DELETE FROM cafs WHERE ambiente = 'test'");
