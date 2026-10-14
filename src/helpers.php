<?php

declare(strict_types=1);

use PayGate\Callback\CallbackToken;

if (!function_exists('paygate_callback_url')) {
    /**
     * Callback URL for a named route with the order reference and its token:
     * paygate_callback_url('paygate.callback', ['order' => $order->id]).
     *
     * @param array<string,string|int> $params The first parameter is the signed order reference.
     */
    function paygate_callback_url(string $routeName, array $params): string
    {
        // Same base as the middleware checks: APP_URL, never the Host header of the current request.
        $base = rtrim((string) config('app.url'), '/') . route($routeName, [], false);

        return app(CallbackToken::class)->callbackUrl($base, $params);
    }
}
