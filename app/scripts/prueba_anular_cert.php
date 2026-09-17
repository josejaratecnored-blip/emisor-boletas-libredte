<?php

/**
 * Anula una boleta de certificación con App\NotasCredito (gasta 1 folio 61 de
 * certificación) y espera el estado final del SII.
 *
 * Uso: php scripts/prueba_anular_cert.php <boleta_id>
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$boletaId = (int) ($argv[1] ?? 0) ?: exit("Uso: php scripts/prueba_anular_cert.php <boleta_id>\n");

$notas = notasCredito('cert');
$mostrar = fn (array $nc) => json_encode(array_diff_key($nc, ['xml' => 1]), JSON_UNESCAPED_UNICODE);

$nc = $notas->anular($boletaId);
echo 'Emitida: ', $mostrar($nc), PHP_EOL;

for ($i = 0; $i < 12 && $nc['estado'] === 'enviada'; $i++) {
    sleep(10);
    $nc = $notas->actualizarEstado((int) $nc['id']);
}
echo 'Final: ', $mostrar($nc), PHP_EOL;
