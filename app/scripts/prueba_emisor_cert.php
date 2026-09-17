<?php

/**
 * Emite una boleta de prueba en certificación con App\Emisor y espera el
 * estado final del SII. Uso: php scripts/prueba_emisor_cert.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$emisor = emisor('cert');

$boleta = $emisor->emitir([
    ['nombre' => 'Cuaderno universitario', 'cantidad' => 2, 'precio' => 1990],
    ['nombre' => 'Lápiz pasta azul', 'cantidad' => 3, 'precio' => 350],
]);
$mostrar = fn (array $b) => json_encode(array_diff_key($b, ['xml' => 1]), JSON_UNESCAPED_UNICODE);
echo 'Emitida: ', $mostrar($boleta), PHP_EOL;

for ($i = 0; $i < 6 && $boleta['estado'] === 'enviada'; $i++) {
    sleep(10);
    $boleta = $emisor->actualizarEstado((int) $boleta['id']);
}
echo 'Final: ', $mostrar($boleta), PHP_EOL;
echo 'XML guardado: ', strlen((string) $boleta['xml']), ' bytes', PHP_EOL;
