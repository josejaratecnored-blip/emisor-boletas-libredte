<?php

declare(strict_types=1);

namespace App;

use Derafu\Certificate\Contract\CertificateInterface;
use InvalidArgumentException;
use libredte\lib\Core\Package\Billing\Contract\BillingPackageInterface;
use libredte\lib\Core\Package\Billing\Component\Document\Support\DocumentBag;
use libredte\lib\Core\Package\Billing\Component\Document\Support\DocumentEnvelope;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Factory\EmisorFactory;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Factory\ReceptorFactory;
use PDO;
use RuntimeException;

/**
 * Emite boletas afectas (39): reserva folio, arma y firma, envía al SII y
 * registra cada paso en la tabla boletas.
 */
final class Emisor
{
    private const TIPO_DTE = 39;

    // Estados del envío en el SII que aún no son definitivos.
    private const ESTADOS_EN_PROCESO = ['REC', 'SOK', 'CRT', 'FOK', 'PRD'];

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
     * @param array<array{nombre:string, cantidad:int|float, precio:int}> $items Precio unitario con IVA.
     * @return array Fila de la boleta.
     */
    public function emitir(array $items): array
    {
        $detalle = $this->validarItems($items);

        $reserva = (new Folios($this->db))->reservar($this->ambiente, self::TIPO_DTE);

        $bag = $this->armar($reserva['folio'], $reserva['archivo'], $detalle);
        $datos = $bag->getDocument()->getData()['Encabezado'];
        $this->db->prepare(
            "UPDATE boletas SET estado = 'emitida', fecha_emision = ?, monto_total = ?, xml = ? WHERE id = ?"
        )->execute([
            $datos['IdDoc']['FchEmis'],
            $datos['Totales']['MntTotal'],
            $bag->getDocument()->saveXml(),
            $reserva['boleta_id'],
        ]);

        try {
            $this->enviar($reserva['boleta_id'], $bag);
        } catch (RuntimeException $e) {
            // La boleta queda 'emitida' con su XML para reenviarla después.
            error_log("Boleta {$reserva['boleta_id']} no enviada al SII: {$e->getMessage()}");
        }

        return $this->buscar($reserva['boleta_id']);
    }

    /**
     * Reenvía al SII una boleta que quedó emitida pero no enviada.
     */
    public function reenviar(int $boletaId): array
    {
        $boleta = $this->buscar($boletaId);
        if ($boleta['estado'] !== 'emitida') {
            throw new RuntimeException("La boleta $boletaId está '{$boleta['estado']}', solo se reenvían las 'emitida'.");
        }

        $bag = $this->billing->getDocumentComponent()->loadXml($boleta['xml'])[0];
        $bag->setCertificate($this->certificate);
        $this->enviar($boletaId, $bag);

        return $this->buscar($boletaId);
    }

    /**
     * Consulta al SII el estado del envío y lo registra.
     */
    public function actualizarEstado(int $boletaId): array
    {
        $boleta = $this->buscar($boletaId);
        if ($boleta['track_id'] === null) {
            throw new RuntimeException("La boleta $boletaId no ha sido enviada al SII.");
        }

        $respuesta = $this->sii()->estadoEnvio($this->empresa['rut'], $boleta['track_id']);
        $estadistica = $respuesta['estadistica'][0] ?? [];

        $estado = match (true) {
            in_array($respuesta['estado'] ?? '', self::ESTADOS_EN_PROCESO, true) => 'enviada',
            ($respuesta['estado'] ?? '') !== 'EPR' => 'rechazada',
            ($estadistica['rechazados'] ?? 0) > 0 => 'rechazada',
            ($estadistica['reparos'] ?? 0) > 0 => 'reparos',
            default => 'aceptada',
        };

        $this->db->prepare('UPDATE boletas SET estado = ?, respuesta_sii = ? WHERE id = ?')
            ->execute([$estado, json_encode($respuesta, JSON_UNESCAPED_UNICODE), $boletaId]);

        return $this->buscar($boletaId);
    }

    public function listar(int $limite = 50): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, folio, estado, fecha_emision, monto_total, track_id, xml IS NOT NULL AS tiene_xml
             FROM boletas WHERE ambiente = ? AND tipo_dte = ? ORDER BY id DESC LIMIT ' . max(1, $limite)
        );
        $stmt->execute([$this->ambiente, self::TIPO_DTE]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function pdf(int $boletaId): string
    {
        $boleta = $this->buscar($boletaId);
        if ($boleta['xml'] === null) {
            throw new RuntimeException("La boleta $boletaId no tiene documento emitido.");
        }

        $bag = $this->billing->getDocumentComponent()->loadXml($boleta['xml'])[0];
        $bag->getOptions()->set('renderer.format', 'pdf');

        return (string) $this->billing->getDocumentComponent()->getRendererWorker()->render($bag);
    }

    public function buscar(int $boletaId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM boletas WHERE id = ? AND ambiente = ?');
        $stmt->execute([$boletaId, $this->ambiente]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new RuntimeException("No existe la boleta $boletaId.");
    }

    private function validarItems(array $items): array
    {
        if ($items === []) {
            throw new InvalidArgumentException('La boleta debe tener al menos un ítem.');
        }

        $detalle = [];
        foreach (array_values($items) as $i => $item) {
            $n = $i + 1;
            $nombre = trim((string) ($item['nombre'] ?? ''));
            $cantidad = $item['cantidad'] ?? null;
            $precio = $item['precio'] ?? null;

            if ($nombre === '' || mb_strlen($nombre) > 80) {
                throw new InvalidArgumentException("Ítem $n: el nombre es obligatorio y de máximo 80 caracteres.");
            }
            if (!is_numeric($cantidad) || $cantidad <= 0) {
                throw new InvalidArgumentException("Ítem $n: la cantidad debe ser mayor a 0.");
            }
            if (!is_int($precio) && !ctype_digit((string) $precio) || (int) $precio <= 0) {
                throw new InvalidArgumentException("Ítem $n: el precio debe ser un entero mayor a 0 (con IVA).");
            }

            $detalle[] = ['NmbItem' => $nombre, 'QtyItem' => $cantidad + 0, 'PrcItem' => (int) $precio];
        }

        return $detalle;
    }

    private function armar(int $folio, string $archivoCaf, array $detalle): DocumentBag
    {
        $caf = $this->billing->getIdentifierComponent()->getCafLoaderWorker()
            ->load(file_get_contents("{$this->secretsDir}/$archivoCaf"));

        $bag = new DocumentBag(
            parsedData: [
                'Encabezado' => [
                    'IdDoc' => ['TipoDTE' => self::TIPO_DTE, 'Folio' => $folio],
                    'Emisor' => [
                        'RUTEmisor' => $this->empresa['rut'],
                        'RznSoc' => $this->empresa['razon_social'],
                        'GiroEmis' => $this->empresa['giro'],
                        'DirOrigen' => $this->empresa['direccion'],
                        'CmnaOrigen' => $this->empresa['comuna'],
                    ],
                    'Receptor' => ['RUTRecep' => '66666666-6', 'RznSocRecep' => 'Cliente Internet'],
                ],
                'Detalle' => $detalle,
            ],
            caf: $caf,
            certificate: $this->certificate
        );
        $this->billing->getDocumentComponent()->getBuilderWorker()->build($bag);

        return $bag;
    }

    private function enviar(int $boletaId, DocumentBag $bag): void
    {
        $resolucion = $this->empresa['resolucion'][$this->ambiente];

        $envelope = (new DocumentEnvelope())
            ->addDocument($bag)
            ->setCertificate($this->certificate)
            ->setEmisor((new EmisorFactory())->create([
                'rut' => $this->empresa['rut'],
                'razon_social' => $this->empresa['razon_social'],
                'autorizacion_dte' => [
                    'fecha_resolucion' => $resolucion['fecha'],
                    'numero_resolucion' => $resolucion['numero'],
                ],
            ]))
            ->setReceptor((new ReceptorFactory())->create(['rut' => '60803000-K'])); // SII
        $this->billing->getDocumentComponent()->getDispatcherWorker()->normalize($envelope);

        $respuesta = $this->sii()->enviar($envelope->getXmlDocument()->saveXml(), $this->empresa['rut']);

        $this->db->prepare("UPDATE boletas SET estado = 'enviada', track_id = ?, respuesta_sii = ? WHERE id = ?")
            ->execute([$respuesta['trackid'], json_encode($respuesta, JSON_UNESCAPED_UNICODE), $boletaId]);
    }

    private function sii(): SiiBoletaClient
    {
        return new SiiBoletaClient($this->certificate, $this->ambiente);
    }
}
