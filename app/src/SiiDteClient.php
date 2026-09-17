<?php

declare(strict_types=1);

namespace App;

use CURLStringFile;
use Derafu\Certificate\Contract\CertificateInterface;
use RuntimeException;

/**
 * Cliente del canal de DTE "no boleta" del SII (notas de crédito, facturas):
 * token por SOAP, envío por DTEUpload y estado por QueryEstUp.
 *
 * Los jobs equivalentes de LibreDTE usan la firma de derafu/signature (que el
 * SII rechaza al pedir token) y un cliente SOAP que no carga el WSDL.
 */
final class SiiDteClient
{
    private const SERVERS = ['cert' => 'https://maullin.sii.cl', 'prod' => 'https://palena.sii.cl'];

    private const USER_AGENT = 'Mozilla/4.0 ( compatible; PROG 1.0; Windows NT)';

    private ?string $token = null;

    public function __construct(
        private readonly CertificateInterface $certificate,
        private readonly string $ambiente = 'cert',
    ) {
        if (!isset(self::SERVERS[$ambiente])) {
            throw new RuntimeException("Ambiente inválido: $ambiente");
        }
    }

    public function token(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        $body = $this->soap('CrSeed', 'getSeed', '');
        $semilla = $this->tag($body, 'SEMILLA');
        if ($semilla === null || $this->tag($body, 'ESTADO') !== '00') {
            throw new RuntimeException('No se pudo obtener la semilla: ' . substr($body, 0, 300));
        }

        $firmado = htmlspecialchars(SiiSemilla::firmar($semilla, $this->certificate), ENT_XML1);
        $body = $this->soap('GetTokenFromSeed', 'getToken', "<pszXml>$firmado</pszXml>");
        $token = $this->tag($body, 'TOKEN');
        if ($token === null || $this->tag($body, 'ESTADO') !== '00') {
            throw new RuntimeException('No se pudo obtener el token: ' . ($this->tag($body, 'GLOSA') ?? substr($body, 0, 300)));
        }

        return $this->token = $token;
    }

    /**
     * Sube un XML EnvioDTE firmado. Entrega el track ID.
     */
    public function enviar(string $xmlEnvioDte, string $rutEmisor): int
    {
        [$rutSender, $dvSender] = explode('-', $this->certificate->getId());
        [$rutCompany, $dvCompany] = explode('-', strtoupper($rutEmisor));

        $ch = curl_init(self::SERVERS[$this->ambiente] . '/cgi_dte/UPL/DTEUpload');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['User-Agent: ' . self::USER_AGENT, 'Cookie: TOKEN=' . $this->token()],
            CURLOPT_POSTFIELDS => [
                'rutSender' => $rutSender,
                'dvSender' => $dvSender,
                'rutCompany' => $rutCompany,
                'dvCompany' => $dvCompany,
                'archivo' => new CURLStringFile($xmlEnvioDte, "{$rutEmisor}_envio_dte.xml", 'text/xml'),
            ],
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            throw new RuntimeException('Error de conexión con DTEUpload: ' . curl_error($ch));
        }

        $status = $this->tag($body, 'STATUS');
        $trackId = $this->tag($body, 'TRACKID');
        if ($status !== '0' || $trackId === null) {
            throw new RuntimeException("DTEUpload rechazó el envío (STATUS $status): " . substr(strip_tags($body), 0, 300));
        }

        return (int) $trackId;
    }

    /**
     * Estado de un envío. Entrega los tags de la respuesta (ESTADO, GLOSA, ACEPTADOS, RECHAZADOS, REPAROS…).
     */
    public function estadoEnvio(string $rutEmisor, int $trackId): array
    {
        [$rut, $dv] = explode('-', strtoupper($rutEmisor));
        $body = $this->soap(
            'QueryEstUp',
            'getEstUp',
            "<Rut>$rut</Rut><Dv>$dv</Dv><TrackId>$trackId</TrackId><Token>{$this->token()}</Token>"
        );

        preg_match_all('#<(?:SII:)?([A-Z_]+)>([^<]*)</(?:SII:)?\1>#', $body, $m, PREG_SET_ORDER);
        $datos = [];
        foreach ($m as [, $tag, $valor]) {
            $datos[$tag] = mb_convert_encoding($valor, 'UTF-8', 'ISO-8859-1');
        }
        if (!isset($datos['ESTADO'])) {
            throw new RuntimeException('Respuesta inesperada de QueryEstUp: ' . substr($body, 0, 300));
        }

        return $datos;
    }

    private function soap(string $servicio, string $funcion, string $parametros): string
    {
        $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body>'
            . "<$funcion>$parametros</$funcion>"
            . '</soapenv:Body></soapenv:Envelope>';

        $ch = curl_init(self::SERVERS[$this->ambiente] . "/DTEWS/$servicio.jws");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_POSTFIELDS => $envelope,
            CURLOPT_HTTPHEADER => ['Content-Type: text/xml; charset=utf-8', 'SOAPAction: ""', 'User-Agent: ' . self::USER_AGENT],
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            throw new RuntimeException("Error de conexión con $servicio: " . curl_error($ch));
        }

        // La respuesta SII viene como XML escapado dentro del SOAP.
        return html_entity_decode($body);
    }

    private function tag(string $xml, string $tag): ?string
    {
        return preg_match("#<(?:SII:)?$tag>([^<]*)</(?:SII:)?$tag>#", $xml, $m) ? $m[1] : null;
    }
}
