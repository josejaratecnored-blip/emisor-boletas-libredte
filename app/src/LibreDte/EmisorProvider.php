<?php

declare(strict_types=1);

namespace App\LibreDte;

use libredte\lib\Core\Package\Billing\Component\TradingParties\Contract\EmisorFactoryInterface;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Contract\EmisorInterface;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Contract\EmisorProviderInterface;

/**
 * Entrega el emisor tal como viene en el documento, sin completar datos.
 */
final class EmisorProvider implements EmisorProviderInterface
{
    public function __construct(private EmisorFactoryInterface $emisorFactory)
    {
    }

    public function retrieve(int|string|EmisorInterface $emisor): EmisorInterface
    {
        return $emisor instanceof EmisorInterface
            ? $emisor
            : $this->emisorFactory->create(['rut' => $emisor]);
    }
}
