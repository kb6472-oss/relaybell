<?php

declare(strict_types=1);

namespace Relaybell;

use RuntimeException;

/**
 * Thrown by {@see Webhook::verify()} when a webhook request can't be trusted.
 *
 * getReason() says why, as one of the REASON_* constants: "missing_secret",
 * "missing_signature", "malformed_signature", "timestamp_out_of_tolerance",
 * "signature_mismatch" or "invalid_payload".
 */
class WebhookVerificationException extends RuntimeException
{
    public const REASON_MISSING_SECRET = 'missing_secret';
    public const REASON_MISSING_SIGNATURE = 'missing_signature';
    public const REASON_MALFORMED_SIGNATURE = 'malformed_signature';
    public const REASON_TIMESTAMP_OUT_OF_TOLERANCE = 'timestamp_out_of_tolerance';
    public const REASON_SIGNATURE_MISMATCH = 'signature_mismatch';
    public const REASON_INVALID_PAYLOAD = 'invalid_payload';

    private string $reason;

    public function __construct(string $reason, string $message)
    {
        parent::__construct($message);
        $this->reason = $reason;
    }

    /**
     * Why the request was refused (one of the REASON_* constants).
     */
    public function getReason(): string
    {
        return $this->reason;
    }
}
