<?php

/**
 * Prueba local (sin SII): arma una boleta 39 con CAF y certificado falsos,
 * valida esquema y firma, y genera el PDF.
 *
 * Uso: php scripts/prueba_boleta_fake.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Derafu\Certificate\Service\CertificateFaker;
use Derafu\Certificate\Service\CertificateLoader;
use libredte\lib\Core\Package\Billing\Component\Document\Support\DocumentBag;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Entity\Emisor;

$billing = libredte()
    ->getPackageRegistry()
    ->getBillingPackage();
$documents = $billing->getDocumentComponent();

$data = [
    'Encabezado' => [
        'IdDoc' => ['TipoDTE' => 39, 'Folio' => 1],
        'Emisor' => [
            'RUTEmisor' => '76192083-9',
            'RznSocEmisor' => 'Emisor de prueba',
            'GiroEmisor' => 'Prueba',
            'DirOrigen' => 'Santiago',
            'CmnaOrigen' => 'Santiago',
        ],
        'Receptor' => ['RUTRecep' => '66666666-6'],
    ],
    'Detalle' => [
        ['NmbItem' => 'Producto de prueba', 'QtyItem' => 1, 'PrcItem' => 1190],
    ],
];

$emisor = new Emisor('76192083-9', 'Emisor de prueba');
$caf = $billing->getIdentifierComponent()->getCafFakerWorker()->create($emisor, 39, 1);
$certificate = (new CertificateFaker(new CertificateLoader()))->createFake(id: $emisor->getRUT());

$bag = new DocumentBag(parsedData: $data, caf: $caf, certificate: $certificate);
$documents->getBuilderWorker()->build($bag);

$xml = $bag->getDocument()->saveXml();
$documents->getValidatorWorker()->validateSchema($xml);
$firmaOk = $documents->getValidatorWorker()->validateSignature($xml)->isValid();

$bag->getOptions()->set('renderer.format', 'pdf');
$pdf = (string) $documents->getRendererWorker()->render($bag);

$out = __DIR__ . '/../var/pruebas';
@mkdir($out, 0750, true);
file_put_contents("$out/boleta_fake.xml", $xml);
file_put_contents("$out/boleta_fake.pdf", $pdf);

$totales = $bag->getDocument()->getData()['Encabezado']['Totales'];
echo 'Totales: ', json_encode($totales), PHP_EOL;
echo 'Esquema XML: OK', PHP_EOL;
echo 'Firma valida: ', $firmaOk ? 'SI' : 'NO', PHP_EOL;
echo 'PDF: ', strlen($pdf), ' bytes', PHP_EOL;
