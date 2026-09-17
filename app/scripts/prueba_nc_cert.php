<?php

/**
 * Nota de crédito (61) que anula una boleta de certificación, con el CAF 61
 * real de var/secrets. Sin --enviar solo arma y valida.
 *
 * Uso: php scripts/prueba_nc_cert.php <boleta_id> <folio_nc> [--enviar]
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\SiiDteClient;
use Derafu\Certificate\Service\CertificateLoader;
use libredte\lib\Core\Package\Billing\Component\Document\Support\DocumentBag;
use libredte\lib\Core\Package\Billing\Component\Document\Support\DocumentEnvelope;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Factory\EmisorFactory;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Factory\ReceptorFactory;

[, $boletaId, $folioNc] = $argv + [null, null, null];
if (!ctype_digit((string) $boletaId) || !ctype_digit((string) $folioNc)) {
    exit("Uso: php scripts/prueba_nc_cert.php <boleta_id> <folio_nc> [--enviar]\n");
}

$empresa = require __DIR__ . '/../config/empresa.php';
$secrets = __DIR__ . '/../var/secrets';
$billing = libredte()->getPackageRegistry()->getBillingPackage();
$docs = $billing->getDocumentComponent();
$certificate = (new CertificateLoader())->loadFromFile(glob("$secrets/*.pfx")[0], env('CERT_PASSWORD'));

// CAF 61 de certificación que contenga el folio pedido.
$caf = null;
foreach (glob("$secrets/FoliosSII*.xml") as $archivo) {
    $xml = file_get_contents($archivo);
    if (
        str_contains($xml, '<TD>61</TD>') && str_contains($xml, '<IDK>100</IDK>')
        && preg_match('#<D>(\d+)</D>\s*<H>(\d+)</H>#', $xml, $m) && $folioNc >= $m[1] && $folioNc <= $m[2]
    ) {
        $caf = $billing->getIdentifierComponent()->getCafLoaderWorker()->load($xml);
    }
}
$caf ?? exit("No hay CAF 61 de certificación con el folio $folioNc.\n");

$boleta = emisor('cert')->buscar((int) $boletaId);
$b = $docs->loadXml($boleta['xml'])[0]->getDocument()->getData();
$detalle = isset($b['Detalle'][0]) ? $b['Detalle'] : [$b['Detalle']];

$bag = new DocumentBag(
    parsedData: [
        'Encabezado' => [
            'IdDoc' => ['TipoDTE' => 61, 'Folio' => (int) $folioNc, 'MntBruto' => 1],
            'Emisor' => [
                'RUTEmisor' => $empresa['rut'],
                'RznSoc' => $empresa['razon_social'],
                'GiroEmis' => $empresa['giro'],
                'Acteco' => $empresa['acteco'],
                'DirOrigen' => $empresa['direccion'],
                'CmnaOrigen' => $empresa['comuna'],
            ],
            'Receptor' => ['RUTRecep' => '66666666-6', 'RznSocRecep' => 'Cliente Internet'],
        ],
        'Detalle' => array_map(
            fn (array $d) => ['NmbItem' => $d['NmbItem'], 'QtyItem' => $d['QtyItem'], 'PrcItem' => $d['PrcItem']],
            $detalle
        ),
        'Referencia' => [[
            'TpoDocRef' => 39,
            'FolioRef' => $b['Encabezado']['IdDoc']['Folio'],
            'FchRef' => $b['Encabezado']['IdDoc']['FchEmis'],
            'CodRef' => 1,
            'RazonRef' => 'Anula boleta',
        ]],
    ],
    caf: $caf,
    certificate: $certificate
);
$docs->getBuilderWorker()->build($bag);

$resolucion = $empresa['resolucion']['cert'];
$envelope = (new DocumentEnvelope())
    ->addDocument($bag)
    ->setCertificate($certificate)
    ->setEmisor((new EmisorFactory())->create([
        'rut' => $empresa['rut'],
        'razon_social' => $empresa['razon_social'],
        'autorizacion_dte' => ['fecha_resolucion' => $resolucion['fecha'], 'numero_resolucion' => $resolucion['numero']],
    ]))
    ->setReceptor((new ReceptorFactory())->create(['rut' => '60803000-K'])); // SII
$docs->getDispatcherWorker()->normalize($envelope);
$docs->getDispatcherWorker()->validateSchema($envelope);
$firmas = $docs->getDispatcherWorker()->validateSignature($envelope);

$xmlSobre = $envelope->getXmlDocument()->saveXml();
@mkdir($out = __DIR__ . '/../var/envios', 0750, true);
file_put_contents("$out/EnvioDTE_61_$folioNc.xml", $xmlSobre);

$nc = $bag->getDocument()->getData();
echo 'Tipo sobre: ', $envelope->getTipoSobre()->name, PHP_EOL;
echo 'Carátula: ', json_encode($envelope->getCaratula()), PHP_EOL;
echo 'Referencia: ', json_encode($nc['Referencia'], JSON_UNESCAPED_UNICODE), PHP_EOL;
echo 'Totales NC: ', json_encode($nc['Encabezado']['Totales']), ' | boleta: ', json_encode($b['Encabezado']['Totales']), PHP_EOL;
echo 'Esquema EnvioDTE y firmas locales: OK ', count($firmas), ' firmas', PHP_EOL;

if (!in_array('--enviar', $argv, true)) {
    exit(0);
}

$sii = new SiiDteClient($certificate, 'cert');
$trackId = $sii->enviar($xmlSobre, $empresa['rut']);
echo "Enviado. Track ID: $trackId", PHP_EOL;
for ($i = 0; $i < 12; $i++) {
    sleep(10);
    $estado = $sii->estadoEnvio($empresa['rut'], $trackId);
    echo 'Estado: ', json_encode($estado, JSON_UNESCAPED_UNICODE), PHP_EOL;
    if (!in_array($estado['ESTADO'], ['REC', 'SOK', 'CRT', 'FOK', 'PDR', '-11'], true)) {
        break;
    }
}
