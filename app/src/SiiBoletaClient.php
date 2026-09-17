<?php

declare(strict_types=1);

namespace App;

use CURLStringFile;
use Derafu\Certificate\Contract\CertificateInterface;
use RuntimeException;

/**
 * Cliente de la API REST de boleta electrónica del SII.
 *
 * LibreDTE Core no implementa este envío (BoletaSenderStrategy lanza "no está
 * implementada"), por eso se implementa aquí según la especificación oficial:
 * https://www4c.sii.cl/bolcoreinternetui/api/
 */
final class SiiBoletaClient
{
    private const SERVERS = [
        'cert' => [
            'api' => 'https://apicert.sii.cl/recursos/v1',
            'envio' => 'https://pangal.sii.cl/recursos/v1',
        ],
        'prod' => [
            'api' => 'https://api.sii.cl/recursos/v1',
            'envio' => 'https://rahue.sii.cl/recursos/v1',
        ],
    ];

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

    /**
     * Obtiene (y memoriza) un token: pide semilla, la firma y la canjea.
     */
    public function token(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        $api = self::SERVERS[$this->ambiente]['api'];

        [, $body] = $this->request('GET', "$api/boleta.electronica.semilla");
        $semilla = $this->tag($body, 'SEMILLA');
        if ($semilla === null || $this->tag($body, 'ESTADO') !== '00') {
            throw new RuntimeException("No se pudo obtener la semilla: $body");
        }

        [, $body] = $this->request('POST', "$api/boleta.electronica.token", [
            'body' => $this->firmarSemilla($semilla),
            'headers' => ['Content-Type: application/xml'],
        ]);
        $token = $this->tag($body, 'TOKEN');
        if ($token === null || $this->tag($body, 'ESTADO') !== '00') {
            throw new RuntimeException('No se pudo obtener el token: ' . ($this->tag($body, 'GLOSA') ?? $body));
        }

        return $this->token = $token;
    }

    /**
     * Envía un XML EnvioBOLETA firmado. Entrega la respuesta JSON (trackid, estado).
     */
    public function enviar(string $xmlEnvioBoleta, string $rutEmisor): array
    {
        [$rutSender, $dvSender] = explode('-', $this->certificate->getId());
        [$rutCompany, $dvCompany] = explode('-', strtoupper($rutEmisor));

        [$status, $body] = $this->request('POST', self::SERVERS[$this->ambiente]['envio'] . '/boleta.electronica.envio', [
            'auth' => true,
            'body' => [
                'rutSender' => $rutSender,
                'dvSender' => $dvSender,
                'rutCompany' => $rutCompany,
                'dvCompany' => $dvCompany,
                'archivo' => new CURLStringFile($xmlEnvioBoleta, 'envio_boleta.xml', 'application/xml'),
            ],
        ]);

        return $this->json($status, $body);
    }

    /**
     * Consulta el estado de un envío por su track ID.
     */
    public function estadoEnvio(string $rutEmisor, int|string $trackId): array
    {
        $rut = strtoupper($rutEmisor);
        [$status, $body] = $this->request(
            'GET',
            self::SERVERS[$this->ambiente]['api'] . "/boleta.electronica.envio/$rut-$trackId",
            ['auth' => true]
        );

        return $this->json($status, $body);
    }

    /**
     * Firma getToken en formato XML-DSig compacto (sin indentación).
     *
     * La firma generada por derafu/signature es rechazada por el SII con
     * ESTADO 11 ("elemento Certificate no existe"), tanto en REST como SOAP.
     */
    private function firmarSemilla(string $semilla): string
    {
        $doc = "<getToken><item><Semilla>$semilla</Semilla></item></getToken>";
        $digest = base64_encode(sha1($doc, true)); // $doc ya está en forma canónica.

        $signedInfo = '<CanonicalizationMethod Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"></CanonicalizationMethod>'
            . '<SignatureMethod Algorithm="http://www.w3.org/2000/09/xmldsig#rsa-sha1"></SignatureMethod>'
            . '<Reference URI=""><Transforms><Transform Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"></Transform></Transforms>'
            . '<DigestMethod Algorithm="http://www.w3.org/2000/09/xmldsig#sha1"></DigestMethod>'
            . "<DigestValue>$digest</DigestValue></Reference>";

        $privateKey = $this->certificate->getPrivateKey();
        openssl_sign(
            '<SignedInfo xmlns="http://www.w3.org/2000/09/xmldsig#">' . $signedInfo . '</SignedInfo>',
            $signature,
            $privateKey,
            OPENSSL_ALGO_SHA1
        );
        $rsa = openssl_pkey_get_details(openssl_pkey_get_private($privateKey))['rsa'];

        $signatureXml = '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#">'
            . "<SignedInfo>$signedInfo</SignedInfo>"
            . '<SignatureValue>' . base64_encode($signature) . '</SignatureValue>'
            . '<KeyInfo><KeyValue><RSAKeyValue>'
            . '<Modulus>' . base64_encode($rsa['n']) . '</Modulus>'
            . '<Exponent>' . base64_encode($rsa['e']) . '</Exponent>'
            . '</RSAKeyValue></KeyValue>'
            . '<X509Data><X509Certificate>' . $this->certificate->getCertificate(true) . '</X509Certificate></X509Data>'
            . '</KeyInfo></Signature>';

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . str_replace('</getToken>', "$signatureXml</getToken>", $doc);
    }

    /**
     * @return array{0:int,1:string} Código HTTP y cuerpo.
     */
    private function request(string $method, string $url, array $opts = []): array
    {
        $headers = array_merge(['User-Agent: ' . self::USER_AGENT], $opts['headers'] ?? []);
        if (!empty($opts['auth'])) {
            $headers[] = 'Cookie: TOKEN=' . $this->token();
            $headers[] = 'Accept: application/json';
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
        ]);
        if (isset($opts['body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
        }

        $body = curl_exec($ch);
        if ($body === false) {
            throw new RuntimeException("Error de conexión con $url: " . curl_error($ch));
        }

        return [curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $body];
    }

    private function json(int $status, string $body): array
    {
        $data = json_decode($body, true);
        if ($status !== 200 || !is_array($data)) {
            throw new RuntimeException("Respuesta inesperada del SII (HTTP $status): $body");
        }

        return $data;
    }

    private function tag(string $xml, string $tag): ?string
    {
        return preg_match("#<(?:SII:)?$tag>([^<]*)</(?:SII:)?$tag>#", $xml, $m) ? $m[1] : null;
    }
}
