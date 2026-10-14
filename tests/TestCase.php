<?php

declare(strict_types=1);

namespace PayGate\Laravel\Tests;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PayGate\Laravel\PayGateServiceProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * A minimal Laravel 11+ application with the package's provider and a few callback routes.
 * Callbacks are signed with a key pair made for each test run (key id "test").
 */
abstract class TestCase extends BaseTestCase
{
    /** @var string */
    protected static $privateKey;

    /** @var string */
    protected static $publicKey;

    public const APP_URL = 'https://shop.example';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (self::$privateKey === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $private);
            self::$privateKey = $private;
            self::$publicKey = openssl_pkey_get_details($key)['key'];
        }
    }

    public function createApplication()
    {
        $app = Application::configure(basePath: __DIR__ . '/fixtures')
            ->withProviders([PayGateServiceProvider::class], false)
            ->withRouting(using: function () {
                Route::get('/paygate/callback', function (Request $request) {
                    $data = $request->attributes->get('paygate');

                    return response()->json(['order' => $data->get('order'), 'value' => $data->getValueCoin(), 'coin' => $data->getCoin()]);
                })->name('paygate.callback')->middleware('paygate.callback:order');
                Route::get('/paygate/signature-only', fn () => response('ok'))->name('paygate.plain')->middleware('paygate.callback');
            })
            ->withMiddleware(function () {
            })
            ->withExceptions(function () {
            })
            ->create();
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('app.url', static::APP_URL);
        $app['config']->set('paygate.public_keys', ['test' => self::$publicKey]);

        return $app;
    }

    /** PayGate.to's signature: RSA-SHA256 over the full callback URL, base64. */
    protected static function sign(string $url): string
    {
        openssl_sign($url, $signature, self::$privateKey, OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }

    /**
     * Sends a GET with the raw query string exactly as given (Request::create would re-encode it).
     *
     * @param array<string,string> $headers
     */
    protected function sendCallback(string $url, array $headers): Response
    {
        $parts = parse_url($url);
        $raw = $parts['query'] ?? '';
        $request = Request::create($parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . $parts['path'], 'GET');
        $request->server->set('QUERY_STRING', $raw);
        $request->server->set('REQUEST_URI', $parts['path'] . ($raw !== '' ? '?' . $raw : ''));
        parse_str($raw, $query);
        $request->query->replace($query);
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $this->app->make(HttpKernel::class)->handle($request);
    }

    /** PayGate.to appends its payment data to the registered callback URL. */
    protected static function paygateUrl(string $registered, string $coin = 'polygon_usdc', string $value = '45.10'): string
    {
        return $registered . '&value_coin=' . $value . '&coin=' . $coin . '&txid_in=0xin' . str_repeat('a', 62)
            . '&txid_out=0xout' . str_repeat('b', 60) . '&address_in=' . rawurlencode('A1b2C3%2Fd') . '&value_forwarded_coin=' . $value
            . '&address_out=' . rawurlencode('{"0xmerchant":"0.99"}');
    }
}
