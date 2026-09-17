<?php

declare(strict_types=1);

namespace App;

use Derafu\Certificate\Contract\CertificateInterface;
use libredte\lib\Core\Package\Billing\Component\Document\Support\DocumentBag;
use libredte\lib\Core\Package\Billing\Component\Document\Support\DocumentEnvelope;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Factory\EmisorFactory;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Factory\ReceptorFactory;
use libredte\lib\Core\Package\Billing\Contract\BillingPackageInterface;

/**
 * Arma y firma el sobre de envío al SII (EnvioBOLETA o EnvioDTE según el
 * tipo de documento) con la resolución del ambiente.
 */
final class Sobre
{
    public static function armar(
        BillingPackageInterface $billing,
        DocumentBag $bag,
        CertificateInterface $certificate,
        array $empresa,
        string $ambiente,
    ): string {
        $resolucion = $empresa['resolucion'][$ambiente];

        $envelope = (new DocumentEnvelope())
            ->addDocument($bag)
            ->setCertificate($certificate)
            ->setEmisor((new EmisorFactory())->create([
                'rut' => $empresa['rut'],
                'razon_social' => $empresa['razon_social'],
                'autorizacion_dte' => [
                    'fecha_resolucion' => $resolucion['fecha'],
                    'numero_resolucion' => $resolucion['numero'],
                ],
            ]))
            ->setReceptor((new ReceptorFactory())->create(['rut' => '60803000-K'])); // SII
        $billing->getDocumentComponent()->getDispatcherWorker()->normalize($envelope);

        return $envelope->getXmlDocument()->saveXml();
    }
}
