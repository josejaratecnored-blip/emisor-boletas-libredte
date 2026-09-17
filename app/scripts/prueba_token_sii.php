<?php

/**
 * Prueba de autenticación contra la API de boletas del SII (certificación).
 * No envía documentos. Uso: php scripts/prueba_token_sii.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\SiiBoletaClient;
use Derafu\Certificate\Service\CertificateLoader;

$certificate = (new CertificateLoader())->loadFromFile(
    glob(__DIR__ . '/../var/secrets/*.pfx')[0],
    env('CERT_PASSWORD')
);

$token = (new SiiBoletaClient($certificate, 'cert'))->token();

echo 'Certificado de: ', $certificate->getName(), ' (', $certificate->getId(), ')', PHP_EOL;
echo 'Token obtenido: SI (', strlen($token), ' caracteres)', PHP_EOL;
