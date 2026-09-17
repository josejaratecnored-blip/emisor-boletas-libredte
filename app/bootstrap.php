<?php

declare(strict_types=1);

/**
 * Carga Composer e instancia LibreDTE.
 *
 * LibreDTE asume que es el proyecto raíz (busca resources/ y ../vendor/derafu
 * en su propia carpeta), así que se le indica su carpeta como proyecto y la
 * caché se deja en var/ de esta app. El enlace
 * vendor/libredte/libredte-lib-core/vendor lo crea el script de composer.
 * Los servicios se cargan desde config/services.yaml de esta app.
 */

require __DIR__ . '/vendor/autoload.php';

// El servidor está en UTC; fechas de emisión y firma deben ser hora de Chile.
date_default_timezone_set('America/Santiago');

use Derafu\Kernel\Environment;
use libredte\lib\Core\Application;

/**
 * Lee una variable de .env tal cual (sin parse_ini_file, que interpreta
 * caracteres especiales de las contraseñas).
 */
function env(string $name): ?string
{
    foreach (file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (str_starts_with($line, "$name=")) {
            return substr($line, strlen($name) + 1);
        }
    }

    return null;
}

function db(): PDO
{
    static $pdo;

    if (!$pdo) {
        $pdo = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', env('DB_HOST'), env('DB_NAME')),
            env('DB_USER'),
            env('DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        // MySQL del hosting no tiene zonas con nombre; se usa el desfase actual de Chile.
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }

    return $pdo;
}

function emisor(string $ambiente): App\Emisor
{
    $secrets = __DIR__ . '/var/secrets';

    return new App\Emisor(
        db(),
        libredte()->getPackageRegistry()->getBillingPackage(),
        (new Derafu\Certificate\Service\CertificateLoader())->loadFromFile(glob("$secrets/*.pfx")[0], env('CERT_PASSWORD')),
        require __DIR__ . '/config/empresa.php',
        $ambiente,
        $secrets,
    );
}

function libredte(): Application
{
    $libDir = __DIR__ . '/vendor/libredte/libredte-lib-core';

    return Application::getInstance(new Environment('prod', false, [], [
        'project' => $libDir,
        'config' => __DIR__ . '/config',
        'cache' => __DIR__ . '/var/cache/libredte',
        'log' => __DIR__ . '/var/log',
        'resources' => $libDir . '/resources',
    ]));
}
