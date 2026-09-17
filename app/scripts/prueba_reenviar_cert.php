<?php

/**
 * Prueba de reenviar() en certificación (gasta 1 folio). Simula al SII caído
 * con un proxy inexistente durante la emisión, luego reenvía sin proxy.
 * Uso: php scripts/prueba_reenviar_cert.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$mostrar = fn (array $b) => json_encode(array_intersect_key($b, array_flip(['id', 'folio', 'estado', 'monto_total', 'track_id'])));
$emisor = emisor('cert');

// 1. SII inalcanzable: curl usa el proxy de la variable de entorno.
putenv('HTTPS_PROXY=http://127.0.0.1:9');
putenv('https_proxy=http://127.0.0.1:9');
$boleta = $emisor->emitir([['nombre' => 'Prueba reenvío', 'cantidad' => 1, 'precio' => 1000]]);
echo '1. Emitida con SII caído: ', $mostrar($boleta), PHP_EOL;
if ($boleta['estado'] !== 'emitida') {
    exit("FALLA: se esperaba estado 'emitida'.\n");
}

// 2. SII disponible de nuevo.
putenv('HTTPS_PROXY');
putenv('https_proxy');
$boleta = $emisor->reenviar((int) $boleta['id']);
echo '2. Reenviada: ', $mostrar($boleta), PHP_EOL;

// 3. Reenviar una boleta ya enviada debe rechazarse.
try {
    $emisor->reenviar((int) $boleta['id']);
    echo "3. FALLA: permitió reenviar una boleta ya enviada.\n";
} catch (RuntimeException $e) {
    echo '3. Segundo reenvío rechazado: ', $e->getMessage(), PHP_EOL;
}

// 4. Estado final en el SII.
for ($i = 0; $i < 6 && $boleta['estado'] === 'enviada'; $i++) {
    sleep(10);
    $boleta = $emisor->actualizarEstado((int) $boleta['id']);
}
echo '4. Estado final: ', $mostrar($boleta), PHP_EOL;
