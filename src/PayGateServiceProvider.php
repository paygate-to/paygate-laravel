<?php

declare(strict_types=1);

namespace PayGate\Laravel;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use PayGate\Callback\CallbackToken;
use PayGate\Client;
use PayGate\Laravel\Http\Middleware\VerifyPayGateCallback;

/**
 * Registers the PayGate.to client, the order-token helper and the "paygate.callback" middleware.
 */
final class PayGateServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/paygate.php', 'paygate');

        $this->app->singleton(Client::class, function ($app): Client {
            $config = $app['config']->get('paygate', []);

            $client = new Client(array_filter([
                'api_domain' => $config['api_domain'] ?? null,
                'checkout_domain' => $config['checkout_domain'] ?? null,
                'timeout' => isset($config['timeout']) ? (float) $config['timeout'] : null,
                'retries' => isset($config['retries']) ? (int) $config['retries'] : null,
            ], static function ($value) {
                return $value !== null && $value !== '';
            }));
            foreach ((array) ($config['public_keys'] ?? []) as $keyId => $pem) {
                $pem = (string) $pem;
                if (strpos($pem, '-----BEGIN') === false && is_file($pem)) {
                    $pem = (string) file_get_contents($pem);
                }
                $client->callbacks()->addKey((string) $keyId, $pem);
            }

            return $client;
        });
        $this->app->alias(Client::class, 'paygate');

        $this->app->singleton(CallbackToken::class, function ($app): CallbackToken {
            return new CallbackToken(self::callbackSecret($app['config']->get('paygate.callback_secret'), (string) $app['config']->get('app.key')));
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/paygate.php' => $this->app->configPath('paygate.php')], 'paygate-config');
        }

        /** @var Router $router */
        $router = $this->app['router'];
        $router->aliasMiddleware('paygate.callback', VerifyPayGateCallback::class);
    }

    /**
     * The configured secret, or one derived from APP_KEY (never APP_KEY itself).
     */
    public static function callbackSecret(?string $configured, string $appKey): string
    {
        if ($configured !== null && $configured !== '') {
            return $configured;
        }
        if ($appKey === '') {
            throw new \RuntimeException('Set APP_KEY or PAYGATE_CALLBACK_SECRET to sign PayGate.to callback URLs.');
        }

        return hash_hmac('sha256', 'paygate-callback-secret', $appKey);
    }
}
