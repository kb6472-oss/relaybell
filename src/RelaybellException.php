<?php

declare(strict_types=1);

namespace Relaybell;

use RuntimeException;
use Throwable;

/**
 * Thrown whenever the RelayBell API responds with a non-2xx status, or when a
 * transport-level error (e.g. a cURL failure) prevents a request from completing.
 *
 * The HTTP status code and the raw, undecoded response body are always available
 * so callers can inspect the server's error payload — typically JSON of the shape
 * { "error": "code" }.
 */
class RelaybellException extends RuntimeException
{
    /**
     * HTTP status code returned by the API, or 0 for transport-level failures
     * (network error, DNS failure, timeout, etc.) where no response was received.
     */
    private int $statusCode;

    /**
     * Raw, undecoded response body as returned by the server. May be an empty
     * string when no body was received.
     */
    private string $responseBody;

    /**
     * Decoded response body when it was valid JSON, otherwise null. Provided as a
     * convenience so callers do not have to json_decode() the raw body themselves.
     *
     * @var array<string, mixed>|null
     */
    private ?array $responseData;

    /**
     * @param string                    $message      Human-readable error message.
     * @param int                       $statusCode   HTTP status (0 for transport errors).
     * @param string                    $responseBody Raw response body.
     * @param array<string, mixed>|null $responseData Decoded JSON body, if any.
     * @param Throwable|null            $previous     Previous throwable for chaining.
     */
    public function __construct(
        string $message,
        int $statusCode = 0,
        string $responseBody = '',
        ?array $responseData = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
        $this->statusCode = $statusCode;
        $this->responseBody = $responseBody;
        $this->responseData = $responseData;
    }

    /**
     * HTTP status code, or 0 when the failure was transport-level.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Raw, undecoded response body.
     */
    public function getResponseBody(): string
    {
        return $this->responseBody;
    }

    /**
     * Decoded response body when it was valid JSON, otherwise null.
     *
     * @return array<string, mixed>|null
     */
    public function getResponseData(): ?array
    {
        return $this->responseData;
    }

    /**
     * Convenience accessor for the API's machine-readable error code, i.e. the
     * value of the top-level "error" field in the JSON body ({ "error": "code" }).
     * Returns null when the body was not JSON or contained no such field.
     */
    public function getErrorCode(): ?string
    {
        if (is_array($this->responseData) && isset($this->responseData['error']) && is_string($this->responseData['error'])) {
            return $this->responseData['error'];
        }

        return null;
    }
}
