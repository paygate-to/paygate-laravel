<?php

declare(strict_types=1);

namespace PayGate\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \PayGate\Card\CardApi card()
 * @method static \PayGate\Crypto\CryptoApi crypto()
 * @method static \PayGate\Callback\CallbackVerifier callbacks()
 * @method static string getApiDomain()
 * @method static string getCheckoutDomain()
 *
 * @see \PayGate\Client
 */
final class PayGate extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \PayGate\Client::class;
    }
}
