<?php

/**
 * Crea (o cambia la contraseña de) un usuario del panel. La contraseña se
 * escribe sin mostrarse en pantalla.
 *
 * Uso: php scripts/crear_usuario.php correo@ejemplo.cl "Nombre"
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

[, $email, $nombre] = $argv + [null, null, null];
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !$nombre) {
    exit("Uso: php scripts/crear_usuario.php correo@ejemplo.cl \"Nombre\"\n");
}

function leerOculto(string $mensaje): string
{
    echo $mensaje;
    shell_exec('stty -echo');
    $valor = rtrim((string) fgets(STDIN), "\r\n");
    shell_exec('stty echo');
    echo PHP_EOL;

    return $valor;
}

$password = leerOculto('Contraseña (mínimo 12 caracteres): ');
if (mb_strlen($password) < 12) {
    exit("La contraseña debe tener al menos 12 caracteres.\n");
}
if (leerOculto('Repite la contraseña: ') !== $password) {
    exit("Las contraseñas no coinciden.\n");
}

db()->prepare(
    'INSERT INTO usuarios (email, nombre, password_hash) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), password_hash = VALUES(password_hash)'
)->execute([$email, $nombre, password_hash($password, PASSWORD_DEFAULT)]);

echo "Usuario $email listo.\n";
