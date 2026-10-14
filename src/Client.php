<?php

declare(strict_types=1);

namespace Relaybell;

/**
 * RelayBell server-side SDK client.
 *
 * A thin, dependency-light wrapper over the RelayBell REST API built on top of
 * ext-curl. All write methods send JSON and expect JSON back; every non-2xx
 * response is surfaced as a {@see RelaybellException} carrying the HTTP status
 * and the raw response body.
 *
 * Usage:
 *
 *     $client = new \Relaybell\Client('rb_live_xxxx');
 *     $res = $client->send(['title' => 'Hello', 'body' => 'World']);
 *     echo $res['notificationId'];
 *
 * The base URL defaults to https://relaybell.com but can be overridden via the
 * constructor (useful for staging environments or self-hosted deployments).
 */
class Client
{
    /** Default API base URL. Overridable via the constructor. */
    public const DEFAULT_BASE_URL = 'https://relaybell.com';

    /** SDK version, sent in the User-Agent header for observability. */
    public const VERSION = '1.1.0';

    /** Bearer token used to authenticate every request (e.g. rb_live_xxxx). */
    private string $apiKey;

    /** Base URL without a trailing slash, e.g. https://relaybell.com. */
    private string $baseUrl;

    /** Total request timeout in seconds. */
    private int $timeout;

    /** Connection-establishment timeout in seconds. */
    private int $connectTimeout;

    /**
     * @param string $apiKey  Your RelayBell API key, e.g. "rb_live_xxxx".
     * @param string $baseUrl API base URL. Defaults to https://relaybell.com.
     * @param array{timeout?: int, connectTimeout?: int} $options
     *        Optional tuning: `timeout` (total, default 30s) and
     *        `connectTimeout` (connect phase, default 10s).
     *
     * @throws \InvalidArgumentException When the API key is empty.
     */
    public function __construct(string $apiKey, string $baseUrl = self::DEFAULT_BASE_URL, array $options = [])
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            throw new \InvalidArgumentException('RelayBell API key must not be empty.');
        }

        // Ensure ext-curl is present up front rather than failing mid-request.
        if (!\extension_loaded('curl')) {
            throw new \RuntimeException('The RelayBell SDK requires the PHP cURL extension (ext-curl).');
        }

        $this->apiKey = $apiKey;
        // Normalise the base URL by stripping any trailing slash so path
        // concatenation is unambiguous.
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = isset($options['timeout']) ? (int) $options['timeout'] : 30;
        $this->connectTimeout = isset($options['connectTimeout']) ? (int) $options['connectTimeout'] : 10;
    }

    /**
     * The `userHash` for identity verification: proof, made on your server, that
     * a visitor really is the user with this id. Print it next to the id for the
     * browser SDK (`RelayBell.init({ externalId, userHash })` or
     * `RelayBell.identify(externalId, userHash)`). Once your project requires
     * verified ids, relaybell ignores an id without a valid hash, so nobody can
     * claim to be one of your users to get their notifications.
     *
     * Lowercase hex HMAC-SHA256 of `$externalId`, keyed with your project's
     * identity secret (dashboard → Settings → Developer). Makes no network
     * request and needs no client instance. Never send the secret to the browser.
     *
     *     $hash = \Relaybell\Client::userHash($secret, $user->email);
     *
     * @param string $secret     Your project's identity secret (64 hex characters).
     * @param string $externalId The id you pass to the browser SDK, exactly as sent.
     *
     * @return string 64 lowercase hex characters.
     *
     * @throws \InvalidArgumentException When the secret is empty.
     */
    public static function userHash(string $secret, string $externalId): string
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('RelayBell identity secret must not be empty.');
        }

        return hash_hmac('sha256', $externalId, $secret);
    }

    /**
     * Send (or schedule) a push notification.
     *
     * POST /api/v1/notifications
     *
     * @param array<string, mixed> $notification Notification payload. `title` is
     *        required. Recognised keys mirror the API contract: title, body, url,
     *        icon, image, badge, actions, requireInteraction, tag, segmentId,
     *        filters, sendAt (ISO 8601), ttl (seconds), perTimezone (bool),
     *        localTime ("HH:MM"), ab ({ b: { title, body } }).
     *
     * @return array<string, mixed> Decoded 202 response: { ok, notificationId, status }.
     *
     * @throws RelaybellException On a non-2xx response or transport failure.
     * @throws \InvalidArgumentException When `title` is missing/empty.
     */
    public function send(array $notification): array
    {
        if (!isset($notification['title']) || !is_string($notification['title']) || trim($notification['title']) === '') {
            throw new \InvalidArgumentException('send(): a non-empty "title" is required.');
        }

        return $this->request('POST', '/api/v1/notifications', $notification);
    }

    /**
     * Track a behavioural event. Events may trigger automations and enrol
     * subscribers into sequences server-side.
     *
     * POST /api/v1/events
     *
     * @param string $event The event name (required), e.g. "product_viewed".
     * @param array{externalId?: string, subscriberId?: string, properties?: array<string, mixed>} $opts
     *        Optional context. Provide `externalId` or `subscriberId` to attribute
     *        the event to a known subscriber, and `properties` for arbitrary
     *        event metadata.
     *
     * @return array<string, mixed> Decoded 202 response:
     *         { ok, eventId, subscriberId, automationsTriggered, sequencesEnrolled }.
     *
     * @throws RelaybellException On a non-2xx response or transport failure.
     * @throws \InvalidArgumentException When `$event` is empty.
     */
    public function track(string $event, array $opts = []): array
    {
        $event = trim($event);
        if ($event === '') {
            throw new \InvalidArgumentException('track(): "event" must not be empty.');
        }

        // Build the payload from the documented fields only; unknown keys in
        // $opts are ignored to avoid sending anything outside the contract.
        $payload = ['event' => $event];

        if (isset($opts['externalId'])) {
            $payload['externalId'] = $opts['externalId'];
        }
        if (isset($opts['subscriberId'])) {
            $payload['subscriberId'] = $opts['subscriberId'];
        }
        if (isset($opts['properties'])) {
            $payload['properties'] = $opts['properties'];
        }

        return $this->request('POST', '/api/v1/events', $payload);
    }

    /**
     * Convenience helper that tracks a purchase (conversion) carrying a monetary
     * value. This is sugar over {@see track()} — it emits an event named
     * "purchase" whose properties include the supplied `value` merged with any
     * additional properties. "purchase" is the event the browser SDK's
     * trackConversion() sends too: it counts as a conversion and cancels cart
     * reminders and journeys set to stop on a purchase. (Before 1.1.0 this sent
     * an event named "conversion".)
     *
     * @param int|float|string     $value Conversion value (e.g. order total).
     * @param array<string, mixed> $props Extra event properties and/or the
     *        documented context keys (`externalId`, `subscriberId`). Context keys
     *        are lifted out and forwarded to the events endpoint; everything else
     *        is treated as event properties.
     *
     * @return array<string, mixed> Decoded 202 response from the events endpoint.
     *
     * @throws RelaybellException On a non-2xx response or transport failure.
     */
    public function trackConversion($value, array $props = []): array
    {
        $opts = [];

        // Allow callers to pass subscriber attribution alongside properties.
        if (isset($props['externalId'])) {
            $opts['externalId'] = $props['externalId'];
            unset($props['externalId']);
        }
        if (isset($props['subscriberId'])) {
            $opts['subscriberId'] = $props['subscriberId'];
            unset($props['subscriberId']);
        }

        // The conversion value lives inside the event properties object.
        $opts['properties'] = array_merge(['value' => $value], $props);

        return $this->track('purchase', $opts);
    }

    /**
     * Fetch account analytics.
     *
     * GET /api/v1/stats
     *
     * @return array<string, mixed> Decoded response:
     *         { subscribers, totals: { sent, clicks, ctr, conversions, revenue }, notifications: [...] }.
     *
     * @throws RelaybellException On a non-2xx response or transport failure.
     */
    public function stats(): array
    {
        return $this->request('GET', '/api/v1/stats');
    }

    /**
     * Perform an HTTP request against the API and decode the JSON response.
     *
     * @param string                    $method HTTP verb (GET, POST, ...).
     * @param string                    $path   Path beginning with "/".
     * @param array<string, mixed>|null $body   JSON body for write requests, or null.
     *
     * @return array<string, mixed> Decoded JSON response body (empty array if the
     *         body was empty, e.g. a 204).
     *
     * @throws RelaybellException On a non-2xx response or transport failure.
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $url = $this->baseUrl . $path;

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
            'User-Agent: relaybell-php/' . self::VERSION . ' (php ' . PHP_VERSION . ')',
        ];

        $ch = curl_init();
        if ($ch === false) {
            throw new RelaybellException('Failed to initialise a cURL handle.');
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        // Enforce TLS verification — never disable in production.
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        // Attach a JSON body for write requests.
        if ($body !== null) {
            $encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                curl_close($ch);
                throw new RelaybellException('Failed to JSON-encode the request body: ' . json_last_error_msg());
            }
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $rawResponse = curl_exec($ch);
        $errno = curl_errno($ch);
        $errmsg = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Transport-level failure: no HTTP response was received.
        if ($rawResponse === false || $errno !== 0) {
            throw new RelaybellException(
                sprintf('HTTP request to %s failed: %s (cURL errno %d).', $url, $errmsg !== '' ? $errmsg : 'unknown error', $errno)
            );
        }

        /** @var string $rawResponse */
        $decoded = $this->decodeJson($rawResponse);

        // Any non-2xx status is an error; surface status + raw body to the caller.
        if ($statusCode < 200 || $statusCode >= 300) {
            $message = sprintf('RelayBell API returned HTTP %d for %s %s.', $statusCode, $method, $path);
            if (is_array($decoded) && isset($decoded['error']) && is_string($decoded['error'])) {
                $message .= ' Error: ' . $decoded['error'];
            }

            throw new RelaybellException($message, $statusCode, $rawResponse, is_array($decoded) ? $decoded : null);
        }

        // Success. An empty body (e.g. 204) decodes to an empty array.
        if (trim($rawResponse) === '') {
            return [];
        }

        if (!is_array($decoded)) {
            throw new RelaybellException(
                'Expected a JSON object from the RelayBell API but received something else.',
                $statusCode,
                $rawResponse
            );
        }

        return $decoded;
    }

    /**
     * Decode a JSON string, returning null on failure rather than throwing so the
     * caller can decide how to react (error responses may not always be JSON).
     *
     * @return array<string, mixed>|null
     */
    private function decodeJson(string $json): ?array
    {
        if (trim($json) === '') {
            return null;
        }

        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }
}
