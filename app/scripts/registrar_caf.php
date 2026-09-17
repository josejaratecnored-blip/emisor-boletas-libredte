<?php

/**
 * Registra un CAF de var/secrets en la base de datos.
 * Uso: php scripts/registrar_caf.php <archivo.xml> [siguiente_folio]
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Folios;

$archivo = __DIR__ . '/../var/secrets/' . basename($argv[1] ?? '');
if (!is_file($archivo)) {
    exit("Uso: php scripts/registrar_caf.php <archivo en var/secrets> [siguiente_folio]\n");
}

$empresa = require __DIR__ . '/../config/empresa.php';
$id = (new Folios(db()))->registrarCaf($archivo, $empresa['rut'], isset($argv[2]) ? (int) $argv[2] : null);

$caf = db()->query("SELECT ambiente, tipo_dte, folio_desde, folio_hasta, siguiente_folio FROM cafs WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
echo 'CAF registrado: ', json_encode($caf), PHP_EOL;
