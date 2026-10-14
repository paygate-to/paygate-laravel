<?php

declare(strict_types=1);

namespace PayGate\Laravel\Tests\Feature;

use PayGate\Callback\CallbackToken;
use PayGate\Client;
use PayGate\Laravel\Facades\PayGate;
use PayGate\Laravel\PayGateServiceProvider;
use PayGate\Laravel\Tests\TestCase;

final class ServiceProviderTest extends TestCase
{
    public function test_client_is_a_configured_singleton(): void
    {
        $this->app['config']->set('paygate.api_domain', 'api-myshop-1a2b3c.paygate.to');
        $this->app->forgetInstance(Client::class);

        $client = $this->app->make(Client::class);
        $this->assertSame($client, $this->app->make('paygate'));
        $this->assertSame($client, PayGate::getFacadeRoot());
        $this->assertSame('api-myshop-1a2b3c.paygate.to', PayGate::getApiDomain());
        $this->assertSame('checkout.paygate.to', PayGate::getCheckoutDomain());
    }

    public function test_configured_keys_are_added_next_to_the_bundled_key(): void
    {
        $callbacks = $this->app->make(Client::class)->callbacks();
        $url = 'https://shop.example/cb?order=1&value_coin=1';

        $this->assertTrue($callbacks->verify('https://shop.example/cb', 'order=1&value_coin=1', self::sign($url), 'test')->isValid());
        // v1 is still known: a test-key signature fails on the signature, not on the key id.
        $this->assertSame('signature_mismatch', $callbacks->verify('https://shop.example/cb', 'order=1&value_coin=1', self::sign($url), 'v1')->getReason());
    }

    public function test_public_key_can_be_a_file_path(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'pgkey');
        file_put_contents($file, self::$publicKey);
        $this->app['config']->set('paygate.public_keys', ['file' => $file]);
        $this->app->forgetInstance(Client::class);
        $url = 'https://shop.example/cb?a=1';

        $this->assertTrue($this->app->make(Client::class)->callbacks()->verify('https://shop.example/cb', 'a=1', self::sign($url), 'file')->isValid());
        unlink($file);
    }

    public function test_callback_secret_is_derived_from_app_key_and_never_equals_it(): void
    {
        $secret = PayGateServiceProvider::callbackSecret(null, 'base64:abc');

        $this->assertSame(64, strlen($secret));
        $this->assertNotSame('base64:abc', $secret);
        $this->assertSame('mine', PayGateServiceProvider::callbackSecret('mine', 'base64:abc'));
    }

    public function test_missing_secret_and_app_key_fails_loudly(): void
    {
        $this->expectException(\RuntimeException::class);
        PayGateServiceProvider::callbackSecret('', '');
    }

    public function test_order_tokens_verify(): void
    {
        $tokens = $this->app->make(CallbackToken::class);
        $url = $tokens->callbackUrl('https://shop.example/cb', ['order' => '42']);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        $this->assertTrue($tokens->verify('42', $q['token']));
        $this->assertFalse($tokens->verify('43', $q['token']));
    }
}
