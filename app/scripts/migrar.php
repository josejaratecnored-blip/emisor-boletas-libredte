<?php

/**
 * Crea las tablas si no existen. Uso: php scripts/migrar.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

db()->exec(file_get_contents(__DIR__ . '/../config/schema.sql'));

echo 'Tablas: ', implode(', ', db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)), PHP_EOL;
