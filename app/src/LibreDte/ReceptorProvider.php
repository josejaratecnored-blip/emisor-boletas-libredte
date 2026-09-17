<?php

declare(strict_types=1);

namespace App\LibreDte;

use libredte\lib\Core\Package\Billing\Component\TradingParties\Contract\ReceptorFactoryInterface;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Contract\ReceptorInterface;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Contract\ReceptorProviderInterface;

/**
 * Entrega el receptor tal como viene en el documento, sin completar datos.
 */
final class ReceptorProvider implements ReceptorProviderInterface
{
    public function __construct(private ReceptorFactoryInterface $receptorFactory)
    {
    }

    public function retrieve(int|string|ReceptorInterface $receptor): ReceptorInterface
    {
        return $receptor instanceof ReceptorInterface
            ? $receptor
            : $this->receptorFactory->create(['rut' => $receptor]);
    }
}
