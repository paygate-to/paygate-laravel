# Changelog

## 1.0.1 - 2026-11-06

- Requires paygate-php 1.1 or later, so `PayGate::card()->providers()` works: with paygate-php 1.0.0 it failed with "HTTP 301". Each provider now also has `getFees()`.
- README: the provider example uses `stripe`.

## 1.0.0 - 2026-10-14

- First release: `PayGate` facade and injectable `PayGate\Client`, configured from `.env`.
- `paygate.callback` middleware: checks PayGate.to's RSA signature against `APP_URL` and, optionally, the order token.
- `paygate_callback_url()` helper for callback URLs with an order token.
- `public_keys` setting to add a new PayGate.to signing key before updating.
