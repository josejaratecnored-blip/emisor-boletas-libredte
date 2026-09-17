<?php

declare(strict_types=1);

namespace App;

use Derafu\Certificate\Contract\CertificateInterface;
use libredte\lib\Core\Package\Billing\Component\Document\Support\DocumentBag;
use libredte\lib\Core\Package\Billing\Contract\BillingPackageInterface;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Notas de crédito (61) que anulan totalmente una boleta de este sistema:
 * reserva folio, arma y firma, envía al SII por DTEUpload y registra cada paso
 * en la tabla notas_credito.
 */
final class NotasCredito
{
    private const TIPO_DTE = 61;

    // Estados de QueryEstUp que rechazan el envío completo.
    private const ESTADOS_RECHAZO = ['RSC', 'RFR', 'RCT', 'RCH', 'RPT', 'RCO', 'VOF'];

    public function __construct(
        private readonly PDO $db,
        private readonly BillingPackageInterface $billing,
        private readonly CertificateInterface $certificate,
        private readonly array $empresa,
        private readonly string $ambiente,
        private readonly string $secretsDir,
    ) {
    }

    /**
     * Anula una boleta aceptada por el SII. Una boleta solo puede tener una nota de crédito.
     *
     * @return array Fila de la nota de crédito.
     */
    public function anular(int $boletaId): array
    {
        $stmt = $this->db->prepare('SELECT estado, xml FROM boletas WHERE id = ? AND ambiente = ?');
        $stmt->execute([$boletaId, $this->ambiente]);
        $boleta = $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new RuntimeException("No existe la boleta $boletaId.");

        if (!in_array($boleta['estado'], ['aceptada', 'reparos'], true)) {
            throw new RuntimeException("Solo se anulan boletas aceptadas por el SII (la boleta $boletaId está '{$boleta['estado']}').");
        }
        if ($this->porBoleta($boletaId) !== null) {
            throw new RuntimeException("La boleta $boletaId ya tiene una nota de crédito.");
        }

        try {
            $reserva = (new Folios($this->db))->reservar($this->ambiente, self::TIPO_DTE, ['boleta_id' => $boletaId]);
        } catch (PDOException $e) {
            // Índice único boleta_id: otra solicitud la anuló al mismo tiempo.
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw new RuntimeException("La boleta $boletaId ya tiene una nota de crédito.");
            }
            throw $e;
        }

        $bag = $this->armar($reserva['folio'], $reserva['archivo'], $boleta['xml']);
        $datos = $bag->getDocument()->getData()['Encabezado'];
        $this->db->prepare(
            "UPDATE notas_credito SET estado = 'emitida', fecha_emision = ?, monto_total = ?, xml = ? WHERE id = ?"
        )->execute([$datos['IdDoc']['FchEmis'], $datos['Totales']['MntTotal'], $bag->getDocument()->saveXml(), $reserva['id']]);

        try {
            $this->enviar($reserva['id'], $bag);
        } catch (RuntimeException $e) {
            // Queda 'emitida' con su XML para reenviarla después.
            error_log("Nota de crédito {$reserva['id']} no enviada al SII: {$e->getMessage()}");
        }

        return $this->buscar($reserva['id']);
    }

    public function reenviar(int $id): array
    {
        $nc = $this->buscar($id);
        if ($nc['estado'] !== 'emitida') {
            throw new RuntimeException("La nota de crédito $id está '{$nc['estado']}', solo se reenvían las 'emitida'.");
        }

        $bag = $this->billing->getDocumentComponent()->loadXml($nc['xml'])[0];
        $bag->setCertificate($this->certificate);
        $this->enviar($id, $bag);

        return $this->buscar($id);
    }

    public function actualizarEstado(int $id): array
    {
        $nc = $this->buscar($id);
        if ($nc['track_id'] === null) {
            throw new RuntimeException("La nota de crédito $id no ha sido enviada al SII.");
        }

        $r = $this->sii()->estadoEnvio($this->empresa['rut'], (int) $nc['track_id']);
        $estado = match (true) {
            in_array($r['ESTADO'], self::ESTADOS_RECHAZO, true) => 'rechazada',
            $r['ESTADO'] !== 'EPR' => 'enviada', // en proceso
            (int) ($r['RECHAZADOS'] ?? 0) > 0 => 'rechazada',
            (int) ($r['REPAROS'] ?? 0) > 0 => 'reparos',
            (int) ($r['ACEPTADOS'] ?? 0) > 0 => 'aceptada',
            default => 'enviada',
        };

        $this->db->prepare('UPDATE notas_credito SET estado = ?, respuesta_sii = ? WHERE id = ?')
            ->execute([$estado, json_encode($r, JSON_UNESCAPED_UNICODE), $id]);

        return $this->buscar($id);
    }

    public function pdf(int $id): string
    {
        $nc = $this->buscar($id);
        if ($nc['xml'] === null) {
            throw new RuntimeException("La nota de crédito $id no tiene documento emitido.");
        }

        $bag = $this->billing->getDocumentComponent()->loadXml($nc['xml'])[0];
        $bag->getOptions()->set('renderer.format', 'pdf');

        return (string) $this->billing->getDocumentComponent()->getRendererWorker()->render($bag);
    }

    public function buscar(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM notas_credito WHERE id = ? AND ambiente = ?');
        $stmt->execute([$id, $this->ambiente]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new RuntimeException("No existe la nota de crédito $id.");
    }

    private function porBoleta(int $boletaId): ?array
    {
        $stmt = $this->db->prepare('SELECT id FROM notas_credito WHERE boleta_id = ?');
        $stmt->execute([$boletaId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function armar(int $folio, string $archivoCaf, string $xmlBoleta): DocumentBag
    {
        $documentos = $this->billing->getDocumentComponent();
        $boleta = $documentos->loadXml($xmlBoleta)[0]->getDocument()->getData();
        $detalle = isset($boleta['Detalle'][0]) ? $boleta['Detalle'] : [$boleta['Detalle']];

        $caf = $this->billing->getIdentifierComponent()->getCafLoaderWorker()
            ->load(file_get_contents("{$this->secretsDir}/$archivoCaf"));

        $bag = new DocumentBag(
            parsedData: [
                'Encabezado' => [
                    // MntBruto: precios con IVA, igual que la boleta, para que los totales coincidan.
                    'IdDoc' => ['TipoDTE' => self::TIPO_DTE, 'Folio' => $folio, 'MntBruto' => 1],
                    'Emisor' => [
                        'RUTEmisor' => $this->empresa['rut'],
                        'RznSoc' => $this->empresa['razon_social'],
                        'GiroEmis' => $this->empresa['giro'],
                        'Acteco' => $this->empresa['acteco'],
                        'DirOrigen' => $this->empresa['direccion'],
                        'CmnaOrigen' => $this->empresa['comuna'],
                    ],
                    'Receptor' => [
                        'RUTRecep' => $boleta['Encabezado']['Receptor']['RUTRecep'],
                        'RznSocRecep' => $boleta['Encabezado']['Receptor']['RznSocRecep'],
                    ],
                ],
                'Detalle' => array_map(
                    fn (array $d) => ['NmbItem' => $d['NmbItem'], 'QtyItem' => $d['QtyItem'], 'PrcItem' => $d['PrcItem']],
                    $detalle
                ),
                'Referencia' => [[
                    'TpoDocRef' => 39,
                    'FolioRef' => $boleta['Encabezado']['IdDoc']['Folio'],
                    'FchRef' => $boleta['Encabezado']['IdDoc']['FchEmis'],
                    'CodRef' => 1,
                    'RazonRef' => 'Anula boleta',
                ]],
            ],
            caf: $caf,
            certificate: $this->certificate
        );
        $documentos->getBuilderWorker()->build($bag);

        return $bag;
    }

    private function enviar(int $id, DocumentBag $bag): void
    {
        $xml = Sobre::armar($this->billing, $bag, $this->certificate, $this->empresa, $this->ambiente);
        $trackId = $this->sii()->enviar($xml, $this->empresa['rut']);

        $this->db->prepare("UPDATE notas_credito SET estado = 'enviada', track_id = ?, respuesta_sii = ? WHERE id = ?")
            ->execute([$trackId, json_encode(['TRACKID' => $trackId]), $id]);
    }

    private function sii(): SiiDteClient
    {
        return new SiiDteClient($this->certificate, $this->ambiente);
    }
}
