<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

/**
 * Registro de CAF y reserva de folios sin duplicados.
 */
final class Folios
{
    private const TABLAS = [39 => 'boletas', 61 => 'notas_credito'];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Registra un CAF leyendo solo su encabezado (no la llave privada).
     *
     * @param int|null $siguienteFolio Primer folio aún no usado (por defecto, el inicio del rango).
     */
    public function registrarCaf(string $archivo, string $rutEmisor, ?int $siguienteFolio = null): int
    {
        $xml = file_get_contents($archivo);
        $tag = fn (string $t) => preg_match("#<$t>([^<]*)</$t>#", $xml, $m) ? $m[1] : null;

        if ($tag('RE') !== $rutEmisor) {
            throw new RuntimeException("El CAF es del RUT {$tag('RE')}, no de $rutEmisor.");
        }
        $ambiente = match ($tag('IDK')) {
            '100' => 'cert',
            '300' => 'prod',
            default => throw new RuntimeException("IDK desconocido en el CAF: {$tag('IDK')}"),
        };
        $desde = (int) $tag('D');
        $hasta = (int) $tag('H');
        $siguienteFolio ??= $desde;
        if ($siguienteFolio < $desde || $siguienteFolio > $hasta + 1) {
            throw new RuntimeException("Siguiente folio $siguienteFolio fuera del rango $desde-$hasta.");
        }

        $this->db->prepare(
            'INSERT INTO cafs (ambiente, tipo_dte, folio_desde, folio_hasta, siguiente_folio, fecha_autorizacion, archivo)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$ambiente, (int) $tag('TD'), $desde, $hasta, $siguienteFolio, $tag('FA'), basename($archivo)]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Reserva el siguiente folio disponible creando la fila del documento en
     * su tabla. Usa el CAF más antiguo con folios.
     *
     * @param array $datos Columnas adicionales de la fila (p. ej. boleta_id).
     * @return array{id:int, folio:int, caf_id:int, archivo:string}
     */
    public function reservar(string $ambiente, int $tipoDte, array $datos = []): array
    {
        $tabla = self::TABLAS[$tipoDte] ?? throw new RuntimeException("Tipo de DTE no soportado: $tipoDte");

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'SELECT id, siguiente_folio, archivo FROM cafs
                 WHERE ambiente = ? AND tipo_dte = ? AND siguiente_folio <= folio_hasta
                 ORDER BY folio_desde LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$ambiente, $tipoDte]);
            $caf = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$caf) {
                throw new RuntimeException("No quedan folios disponibles para DTE $tipoDte en $ambiente.");
            }

            $folio = (int) $caf['siguiente_folio'];
            $this->db->prepare('UPDATE cafs SET siguiente_folio = siguiente_folio + 1 WHERE id = ?')
                ->execute([$caf['id']]);
            $fila = ['ambiente' => $ambiente, 'tipo_dte' => $tipoDte, 'folio' => $folio, 'caf_id' => $caf['id']] + $datos;
            $this->db->prepare(sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                $tabla,
                implode(', ', array_keys($fila)),
                implode(', ', array_fill(0, count($fila), '?'))
            ))->execute(array_values($fila));
            $id = (int) $this->db->lastInsertId();

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return ['id' => $id, 'folio' => $folio, 'caf_id' => (int) $caf['id'], 'archivo' => $caf['archivo']];
    }
}
