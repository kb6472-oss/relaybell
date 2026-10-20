<?php

declare(strict_types=1);

namespace Relaybell;

/**
 * Verify the webhooks relaybell sends to your server.
 *
 *     $raw = file_get_contents('php://input');
 *     try {
 *         $event = \Relaybell\Webhook::verify($raw, getallheaders(), getenv('RELAYBELL_WEBHOOK_SECRET'));
 *     } catch (\Relaybell\WebhookVerificationException $e) {
 *         http_response_code(401);
 *         exit;
 *     }
 *     // $event = ['event' => ..., 'data' => [...], 'projectId' => ..., 'at' => ...]
 */
final class Webhook
{
    /** Allowed difference between the signature's timestamp and now, in seconds. */
    public const DEFAULT_TOLERANCE = 300;

    public const SIGNATURE_HEADER = 'X-Relaybell-Webhook-Signature';
    public const LEGACY_SIGNATURE_HEADER = 'X-Relaybell-Signature';

    private function __construct()
    {
    }

    /**
     * Check that a webhook request really comes from relaybell and return its event.
     *
     * Verifies `X-Relaybell-Webhook-Signature: t=<unix seconds>,v1=<hex>`: `v1` is the
     * HMAC-SHA256 of "<t>.<raw body>" keyed with your webhook secret (the whole whsec_…
     * string). The timestamp must be within $tolerance seconds of now, so a captured
     * request can't be replayed later, and the signature is compared in constant time
     * (hash_equals). The older `X-Relaybell-Signature` header (the body only, no
     * timestamp) is accepted only with $allowLegacy = true and only when the request has
     * no v1 signature.
     *
     * After it passes, skip events whose `X-Relaybell-Delivery` header you've already
     * handled: retries reuse it.
     *
     * @param string               $payload     The raw request body, exactly as received
     *                                          (file_get_contents('php://input')) — never
     *                                          JSON you decoded and re-encoded.
     * @param array<string, mixed> $headers     The request headers: getallheaders(), a PSR-7
     *                                          getHeaders() array, or $_SERVER
     *                                          (HTTP_X_RELAYBELL_WEBHOOK_SIGNATURE). Any case.
     * @param string|null|false    $secret      Your webhook signing secret (whsec_…). Empty,
     *                                          null or false (an unset getenv()) throws
     *                                          missing_secret: it never verifies without one.
     * @param int                  $tolerance   Allowed clock difference in seconds (default 300).
     * @param bool                 $allowLegacy Also accept the older X-Relaybell-Signature.
     * @param int|null             $now         "Now" as unix seconds (for tests).
     *
     * @return array<string, mixed> The event: ['event', 'data', 'projectId', 'at'].
     *
     * @throws WebhookVerificationException When the request can't be trusted (see getReason()).
     * @throws \InvalidArgumentException    When $tolerance isn't positive.
     */
    public static function verify(
        string $payload,
        array $headers,
        $secret,
        int $tolerance = self::DEFAULT_TOLERANCE,
        bool $allowLegacy = false,
        ?int $now = null
    ): array {
        if (!is_string($secret) || $secret === '') {
            throw new WebhookVerificationException(
                WebhookVerificationException::REASON_MISSING_SECRET,
                'Webhook::verify(): the secret is empty — set your webhook signing secret (whsec_…). Nothing is verified without it.'
            );
        }
        if ($tolerance <= 0) {
            throw new \InvalidArgumentException('Webhook::verify(): $tolerance must be a positive number of seconds.');
        }

        $header = self::header($headers, self::SIGNATURE_HEADER);
        if ($header !== null && $header !== '') {
            $t = null;
            $v1 = [];
            foreach (explode(',', $header) as $part) {
                $kv = explode('=', $part, 2);
                if (count($kv) !== 2) {
                    continue;
                }
                $key = trim($kv[0]);
                $value = trim($kv[1]);
                if ($key === 't') {
                    $t = $value;
                } elseif ($key === 'v1') {
                    $v1[] = $value;
                }
            }
            if ($t === null || preg_match('/\A[0-9]{1,12}\z/', $t) !== 1 || count($v1) === 0) {
                throw new WebhookVerificationException(
                    WebhookVerificationException::REASON_MALFORMED_SIGNATURE,
                    'Webhook::verify(): the X-Relaybell-Webhook-Signature header has no valid t / v1.'
                );
            }
            $current = $now ?? time();
            if (abs($current - (int) $t) > $tolerance) {
                throw new WebhookVerificationException(
                    WebhookVerificationException::REASON_TIMESTAMP_OUT_OF_TOLERANCE,
                    sprintf("Webhook::verify(): the signature's timestamp is more than %d seconds away from now.", $tolerance)
                );
            }
            $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
            $matched = false;
            foreach ($v1 as $candidate) {
                // hash_equals is constant-time; lowercase so an upper-case hex value matches too.
                if (hash_equals($expected, strtolower($candidate))) {
                    $matched = true;
                }
            }
            if (!$matched) {
                throw new WebhookVerificationException(
                    WebhookVerificationException::REASON_SIGNATURE_MISMATCH,
                    'Webhook::verify(): the signature does not match (wrong secret, or the body was changed).'
                );
            }
        } else {
            $legacy = self::header($headers, self::LEGACY_SIGNATURE_HEADER);
            if ($legacy === null || $legacy === '' || !$allowLegacy) {
                throw new WebhookVerificationException(
                    WebhookVerificationException::REASON_MISSING_SIGNATURE,
                    ($legacy !== null && $legacy !== '')
                        ? 'Webhook::verify(): only the older X-Relaybell-Signature header was sent; pass $allowLegacy = true to accept it.'
                        : 'Webhook::verify(): the request has no X-Relaybell-Webhook-Signature header.'
                );
            }
            $legacy = trim($legacy);
            if (preg_match('/\Asha256=([0-9a-fA-F]{64})\z/', $legacy, $m) !== 1) {
                throw new WebhookVerificationException(
                    WebhookVerificationException::REASON_MALFORMED_SIGNATURE,
                    'Webhook::verify(): the X-Relaybell-Signature header is malformed.'
                );
            }
            if (!hash_equals(hash_hmac('sha256', $payload, $secret), strtolower($m[1]))) {
                throw new WebhookVerificationException(
                    WebhookVerificationException::REASON_SIGNATURE_MISMATCH,
                    'Webhook::verify(): the signature does not match (wrong secret, or the body was changed).'
                );
            }
        }

        $event = json_decode($payload, true);
        if (!is_array($event)) {
            throw new WebhookVerificationException(
                WebhookVerificationException::REASON_INVALID_PAYLOAD,
                'Webhook::verify(): the body is not a JSON object.'
            );
        }

        return $event;
    }

    /**
     * One header, matched without regard to case; also finds the $_SERVER form
     * (HTTP_X_RELAYBELL_WEBHOOK_SIGNATURE). An array value (PSR-7) gives its first entry.
     *
     * @param array<string, mixed> $headers
     */
    private static function header(array $headers, string $name): ?string
    {
        $wanted = strtolower($name);
        $server = 'http_' . str_replace('-', '_', $wanted);
        foreach ($headers as $key => $value) {
            $k = strtolower((string) $key);
            if ($k !== $wanted && $k !== $server) {
                continue;
            }
            if (is_array($value)) {
                $value = $value[0] ?? null;
            }
            if ($value === null) {
                return null;
            }

            return is_scalar($value) ? (string) $value : null;
        }

        return null;
    }
}
