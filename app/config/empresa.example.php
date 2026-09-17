<?php

// Copiar a empresa.php. Datos del emisor. La resolución corresponde al ambiente (cert = maullin, prod = palena).
return [
    'rut' => '11111111-1',
    'razon_social' => 'EMPRESA DE EJEMPLO SPA',
    'giro' => 'VENTA AL POR MENOR', // máximo 80 caracteres
    'acteco' => 476103,
    'direccion' => 'Calle Ejemplo 123',
    'comuna' => 'Santiago',
    'resolucion' => [
        'cert' => ['fecha' => '2024-01-01', 'numero' => 0],
        'prod' => ['fecha' => '2014-08-22', 'numero' => 80],
    ],
];
