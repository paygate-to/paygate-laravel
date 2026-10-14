<?php

return [

    /*
    | Your free dedicated API endpoint (api-yourshop-1a2b3c.paygate.to). Empty = api.paygate.to.
    */
    'api_domain' => env('PAYGATE_API_DOMAIN'),

    /*
    | Your white-label checkout domain. Empty = checkout.paygate.to.
    */
    'checkout_domain' => env('PAYGATE_CHECKOUT_DOMAIN'),

    /*
    | Payout wallets. Card payments are paid out in USDC on Polygon (an 0x address).
    | For crypto, one wallet per network you accept (keys as in PayGate\Crypto\CryptoApi::HOSTED_NETWORKS).
    */
    'wallets' => [
        'usdc_polygon' => env('PAYGATE_USDC_WALLET'),
        'evm' => env('PAYGATE_EVM_WALLET'),
        'btc' => env('PAYGATE_BTC_WALLET'),
        'ltc' => env('PAYGATE_LTC_WALLET'),
        'solana' => env('PAYGATE_SOLANA_WALLET'),
        'trc20' => env('PAYGATE_TRC20_WALLET'),
        'bitcoincash' => env('PAYGATE_BCH_WALLET'),
        'doge' => env('PAYGATE_DOGE_WALLET'),
        'xmr' => env('PAYGATE_XMR_WALLET'),
        'zec' => env('PAYGATE_ZEC_WALLET'),
        'ton' => env('PAYGATE_TON_WALLET'),
    ],

    /*
    | Secret for the order token in callback URLs (see PayGate\Callback\CallbackToken).
    | Empty = derived from APP_KEY, so it works out of the box.
    */
    'callback_secret' => env('PAYGATE_CALLBACK_SECRET'),

    /*
    | Extra PayGate.to public keys for callback signatures, as key id => PEM text or path to a .pem
    | file. The current key (v1) is bundled; add a new key here when PayGate.to publishes one,
    | before updating the package.
    */
    'public_keys' => [],

    'timeout' => env('PAYGATE_TIMEOUT', 30),

    'retries' => env('PAYGATE_RETRIES', 1),
];
