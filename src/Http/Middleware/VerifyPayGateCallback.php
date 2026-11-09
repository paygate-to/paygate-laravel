<?php

declare(strict_types=1);

namespace PayGate\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PayGate\Callback\CallbackData;
use PayGate\Callback\CallbackToken;
use PayGate\Callback\CallbackVerifier;
use PayGate\Client;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects PayGate.to callbacks that are not genuine.
 *
 *     Route::get('/paygate/callback', OrderPaid::class)->middleware('paygate.callback:order');
 *
 * Checks, in order, answering 403 on failure:
 *  1. PayGate.to's RSA signature over APP_URL + the request path + the raw query string;
 *  2. the order token, when the middleware is given the name of the order parameter
 *     ("paygate.callback:order" checks ?order=…&token=… made by PayGate::callbackUrl()).
 *
 * The parsed payment data is then available as $request->attributes->get('paygate')
 * (a PayGate\Callback\CallbackData).
 */
final class VerifyPayGateCallback
{
    /** @var Client */
    private $client;

    /** @var CallbackToken */
    private $tokens;

    public function __construct(Client $client, CallbackToken $tokens)
    {
        $this->client = $client;
        $this->tokens = $tokens;
    }

    public function handle(Request $request, Closure $next, ?string $orderParam = null): Response
    {
        // The query string exactly as received (Request::getQueryString() re-encodes it).
        $rawQuery = CallbackVerifier::rawQueryFrom($request->server->all());
        $registered = self::registeredUrl($request);
        $result = $this->client->callbacks()->verify(
            $registered,
            $rawQuery,
            $request->header('X-PayGate-Signature'),
            $request->header('X-PayGate-Key-Id')
        );
        if (!$result->isValid() && $request->getPathInfo() === '/') {
            // A callback registered without a path (https://shop.example?order=1) is requested as "/":
            // HTTP cannot send an empty path, so the signature may be over the URL without that "/".
            $result = $this->client->callbacks()->verify(
                rtrim($registered, '/'),
                $rawQuery,
                $request->header('X-PayGate-Signature'),
                $request->header('X-PayGate-Key-Id')
            );
        }
        if (!$result->isValid()) {
            Log::warning('PayGate.to callback rejected: ' . $result->getReason());

            return response('invalid signature', 403)->header('Content-Type', 'text/plain');
        }

        $data = CallbackData::fromQueryString($rawQuery);
        if ($orderParam !== null && $orderParam !== '' && !$this->tokens->verify($data->get($orderParam), $data->get($this->tokens->getParam()))) {
            return response('invalid order token', 403)->header('Content-Type', 'text/plain');
        }

        $request->attributes->set('paygate', $data);

        return $next($request);
    }

    /**
     * The callback URL as registered: APP_URL (not the Host header) plus the request path as
     * received (Request::path() drops a trailing "/", which is part of the signed URL).
     */
    public static function registeredUrl(Request $request): string
    {
        $root = rtrim((string) config('app.url'), '/');
        if ($root === '') {
            $root = $request->getSchemeAndHttpHost();
        }

        return $root . '/' . ltrim($request->getPathInfo(), '/');
    }
}
