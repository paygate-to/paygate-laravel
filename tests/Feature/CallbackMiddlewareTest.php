<?php

declare(strict_types=1);

namespace PayGate\Laravel\Tests\Feature;

use PayGate\Laravel\Tests\TestCase;

final class CallbackMiddlewareTest extends TestCase
{
    public function test_genuine_callback_reaches_the_route_with_parsed_data(): void
    {
        $url = self::paygateUrl(paygate_callback_url('paygate.callback', ['order' => 'A1001']));
        $response = $this->sendCallback($url, ['X-PayGate-Signature' => self::sign($url), 'X-PayGate-Key-Id' => 'test']);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame(['order' => 'A1001', 'value' => '45.10', 'coin' => 'polygon_usdc'], json_decode((string) $response->getContent(), true));
    }

    public function test_callback_url_uses_app_url_order_and_token(): void
    {
        $url = paygate_callback_url('paygate.callback', ['order' => 'A1001']);

        $this->assertMatchesRegularExpression('#^https://shop\.example/paygate/callback\?order=A1001&token=[0-9a-f]{64}$#', $url);
    }

    public function test_missing_signature_is_refused(): void
    {
        $url = self::paygateUrl(paygate_callback_url('paygate.callback', ['order' => 'A1001']));

        $this->assertSame(403, $this->sendCallback($url, [])->getStatusCode());
    }

    public function test_changed_amount_is_refused(): void
    {
        $url = self::paygateUrl(paygate_callback_url('paygate.callback', ['order' => 'A1001']));
        $signature = self::sign($url);
        $tampered = str_replace('value_coin=45.10', 'value_coin=4510', $url);

        $this->assertSame(403, $this->sendCallback($tampered, ['X-PayGate-Signature' => $signature, 'X-PayGate-Key-Id' => 'test'])->getStatusCode());
    }

    public function test_unknown_key_id_is_refused(): void
    {
        $url = self::paygateUrl(paygate_callback_url('paygate.callback', ['order' => 'A1001']));

        $this->assertSame(403, $this->sendCallback($url, ['X-PayGate-Signature' => self::sign($url), 'X-PayGate-Key-Id' => 'v9'])->getStatusCode());
    }

    public function test_signature_for_another_host_is_refused(): void
    {
        $url = self::paygateUrl(str_replace(self::APP_URL, 'https://evil.example', paygate_callback_url('paygate.callback', ['order' => 'A1001'])));

        $this->assertSame(403, $this->sendCallback($url, ['X-PayGate-Signature' => self::sign($url), 'X-PayGate-Key-Id' => 'test'])->getStatusCode());
    }

    public function test_callback_moved_to_another_order_is_refused(): void
    {
        $url = self::paygateUrl(str_replace('order=A1001', 'order=A1002', paygate_callback_url('paygate.callback', ['order' => 'A1001'])));
        $response = $this->sendCallback($url, ['X-PayGate-Signature' => self::sign($url), 'X-PayGate-Key-Id' => 'test']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('order token', (string) $response->getContent());
    }

    public function test_value_planted_in_the_merchant_url_is_overridden(): void
    {
        $url = self::paygateUrl(paygate_callback_url('paygate.callback', ['order' => 'A1001']) . '&value_coin=9999', 'polygon_usdc', '1.00');
        $response = $this->sendCallback($url, ['X-PayGate-Signature' => self::sign($url), 'X-PayGate-Key-Id' => 'test']);

        $this->assertSame('1.00', json_decode((string) $response->getContent(), true)['value']);
    }

    public function test_middleware_without_order_parameter_checks_the_signature_only(): void
    {
        $url = self::APP_URL . '/paygate/signature-only?value_coin=0.001&coin=btc&txid_in=abc&pending=0';

        $this->assertSame(200, $this->sendCallback($url, ['X-PayGate-Signature' => self::sign($url), 'X-PayGate-Key-Id' => 'test'])->getStatusCode());
        $this->assertSame(403, $this->sendCallback($url, ['X-PayGate-Signature' => self::sign($url . '&x=1'), 'X-PayGate-Key-Id' => 'test'])->getStatusCode());
    }
}
