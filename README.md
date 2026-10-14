# RelayBell PHP SDK

Official server-side PHP SDK for [RelayBell](https://relaybell.com) — send web push
notifications, track behavioural events, record conversions, and read analytics
from your backend.

- **PHP 7.4+**, framework-free.
- Dependency-light: only `ext-curl` and `ext-json` (both bundled with typical PHP builds).
- PSR-4 autoloaded under the `Relaybell\` namespace.

> This is the **server-side** SDK. To collect subscribers in the browser, use the
> [browser SDK](#browser-sdk) instead — it runs on your storefront, not your server.

## Installation

```bash
composer require relaybell/relaybell-php
```

Not published to Packagist yet? Point Composer at the directory:

```json
{
    "repositories": [
        { "type": "path", "url": "sdks/php" }
    ],
    "require": {
        "relaybell/relaybell-php": "*"
    }
}
```

## Quick start

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Relaybell\Client;
use Relaybell\RelaybellException;

$client = new Client('rb_live_xxxx');

try {
    $result = $client->send([
        'title' => 'Flash sale ends tonight',
        'body'  => '20% off everything until midnight.',
        'url'   => 'https://shop.example.com/sale',
        'icon'  => 'https://shop.example.com/icon.png',
    ]);

    echo "Queued notification {$result['notificationId']} (status: {$result['status']})\n";
} catch (RelaybellException $e) {
    fwrite(STDERR, "RelayBell error {$e->getStatusCode()}: {$e->getErrorCode()}\n");
    fwrite(STDERR, $e->getResponseBody() . "\n");
}
```

## Configuration

```php
// Default base URL is https://relaybell.com.
$client = new Client('rb_live_xxxx');

// Override the base URL (staging, self-hosted, etc.).
$client = new Client('rb_live_xxxx', 'http://localhost:8080');

// Tune timeouts (seconds).
$client = new Client('rb_live_xxxx', 'https://relaybell.com', [
    'timeout'        => 30, // total request timeout (default 30)
    'connectTimeout' => 10, // connection phase timeout (default 10)
]);
```

Authentication uses the HTTP header `Authorization: Bearer <API_KEY>`, applied
automatically to every request.

## API

### `send(array $notification): array`

`POST /api/v1/notifications` — send or schedule a push. `title` is required.

```php
$client->send([
    'title'              => 'Back in stock',
    'body'               => 'The item on your wishlist is available again.',
    'url'                => 'https://shop.example.com/p/123',
    'icon'               => 'https://shop.example.com/icon.png',
    'image'              => 'https://shop.example.com/hero.jpg',
    'badge'              => 'https://shop.example.com/badge.png',
    'actions'            => [
        ['action' => 'view', 'title' => 'View item'],
    ],
    'requireInteraction' => true,
    'tag'                => 'restock-123',
    'segmentId'          => 'seg_abc',
    'filters'            => ['country' => 'US'],
    'sendAt'             => '2026-07-06T09:00:00Z', // ISO 8601 for scheduling
    'ttl'                => 86400,                   // seconds
    'perTimezone'        => true,
    'localTime'          => '09:00',                 // "HH:MM"
    'ab'                 => [
        'b' => ['title' => 'Restocked!', 'body' => 'Grab it before it sells out.'],
    ],
]);
// => ['ok' => true, 'notificationId' => 'ntf_...', 'status' => 'queued']
```

### `track(string $event, array $opts = []): array`

`POST /api/v1/events` — track a behavioural event. May trigger automations and
sequence enrolments server-side.

```php
$client->track('product_viewed', [
    'externalId'   => 'user_42',           // your ID for the subscriber
    // 'subscriberId' => 'sub_...',        // or RelayBell's subscriber ID
    'properties'   => [
        'productId' => 'sku_123',
        'price'     => 49.0,
    ],
]);
// => ['ok' => true, 'eventId' => 'evt_...', 'subscriberId' => 'sub_...',
//     'automationsTriggered' => [...], 'sequencesEnrolled' => [...]]
```

### `trackConversion($value, array $props = []): array`

Convenience wrapper over `track()` that emits a `purchase` event with a monetary
`value` in its properties — the same event the browser SDK's `trackConversion()`
sends. It counts as a conversion (the value is credited to the last notification
that subscriber received) and cancels pending cart reminders and journeys set to
stop on `purchase`. `externalId` / `subscriberId` in `$props` are lifted out and
used for subscriber attribution; all other keys become event properties. Before
1.1.0 this method sent an event named `conversion`.

```php
$client->trackConversion(129.99, [
    'externalId' => 'user_42',
    'orderId'    => 'ord_789',
    'currency'   => 'USD',
]);
// Emits event "purchase" with properties { value: 129.99, orderId, currency }.
```

### `stats(): array`

`GET /api/v1/stats` — account analytics.

```php
$stats = $client->stats();
// => ['subscribers' => 12345,
//     'totals' => ['sent' => ..., 'clicks' => ..., 'ctr' => ...,
//                  'conversions' => ..., 'revenue' => ...],
//     'notifications' => [ ... ]]
```

## Identity verification

When your site tells relaybell who a signed-in visitor is (`externalId`, often
their email), sign that id on your server with `Client::userHash()`. Once your
project requires verified ids (dashboard → Settings → Developer → User ID
verification), relaybell ignores an id that comes without a valid hash, so nobody
can pretend to be one of your customers to get their notifications.

```php
use Relaybell\Client;

// Your project's identity secret (Settings → Developer). Keep it on the server only.
$secret = getenv('RELAYBELL_IDENTITY_SECRET');
$email  = $user->email;
$hash   = Client::userHash($secret, $email); // no network request, no client needed
$safe   = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT; // safe inside <script>
?>
<script>
  RelayBell.init({
    projectId: 'proj_xxxx', apiBase: 'https://relaybell.com',
    externalId: <?= json_encode($email, $safe) ?>, userHash: <?= json_encode($hash) ?>
  });
  // or, after the visitor signs in: RelayBell.identify(externalId, userHash);
</script>
```

`Client::userHash(string $secret, string $externalId): string` returns the
lowercase hex HMAC-SHA256 of the id keyed with the secret — the same as
`hash_hmac('sha256', $externalId, $secret)`. Sign the id exactly as you pass it
to the browser. If you rotate the secret, update your server's copy: hashes made
with the old secret stop working at once.

## Error handling

Every non-2xx response throws `Relaybell\RelaybellException`. Transport failures
(DNS, connection, timeout) throw the same exception with a status code of `0`.

```php
try {
    $client->send(['title' => 'Hi']);
} catch (RelaybellException $e) {
    $e->getStatusCode();   // int  — HTTP status, or 0 for transport errors
    $e->getErrorCode();    // ?string — the API's "error" code, e.g. "invalid_api_key"
    $e->getResponseBody(); // string  — raw, undecoded response body
    $e->getResponseData(); // ?array  — decoded JSON body, if any
}
```

The API returns errors as JSON `{ "error": "code" }` with a normal HTTP status.
`getErrorCode()` extracts that code for you.

## Browser SDK

The server SDK is for backend calls only. To subscribe visitors and fire
client-side events, load the browser SDK on your storefront:

```html
<script src="https://relaybell.com/relaybell-sdk.js"></script>
<script>
  RelayBell.init({ projectId: 'proj_xxxx', apiBase: 'https://relaybell.com' });

  // Also available in the browser:
  // RelayBell.identify(externalId, userHash)
  // RelayBell.track(event, properties)
  // RelayBell.trackConversion(value, props)
  // RelayBell.unsubscribe()
  // RelayBell.isSupported()
</script>
```

## Requirements

- PHP >= 7.4
- `ext-curl`, `ext-json`

## Changelog

### 1.1.0

- `trackConversion()` now sends a `purchase` event (it sent `conversion` before),
  the same event as the browser SDK and the WordPress plugin. Purchases recorded
  from your server now cancel cart reminders and journeys set to stop on a
  purchase. Its arguments haven't changed. If you built an automation or journey
  on the `conversion` event, switch it to `purchase`.
- New `Client::userHash($secret, $externalId)` for identity verification: signs a
  visitor's id on your server so the browser SDK can prove it. No network request.
  See [Identity verification](#identity-verification).

## License

MIT
