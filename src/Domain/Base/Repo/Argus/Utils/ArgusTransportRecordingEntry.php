<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\Argus\Utils;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One call an {@see ArgusTransportRecording} recorded: the request, and the response or the transport failure it got.
 * Stored and read back through {@see toStorageArray()} / {@see fromStorageArray()} (the keys of a stored recording).
 */
class ArgusTransportRecordingEntry extends ValueObject
{
    /** @var string The call's endpoint: method, scheme, host and path (no query) */
    public string $endpoint = '';

    /** @var string The request body as sent */
    public string $requestBody = '';

    /** @var int The response's HTTP status; 0 until the response arrived, and for a transport failure */
    public int $statusCode = 0;

    /** @var string[][] The response headers kept for playing (header name => values) */
    public array $responseHeaders = [];

    /** @var string The response body */
    public string $responseBody = '';

    /** @var bool Whether the call was made inside a tool execution */
    public bool $duringTool = false;

    /** @var string|null The scripted tool call the call belongs to */
    public ?string $toolCallLabel = null;

    /** @var string|null The call's kind (see {@see ArgusTransportRecording::kindOf()}) */
    public ?string $kind = null;

    /** @var int|null The owner turn the call belongs to */
    public ?int $turnOrdinal = null;

    /** @var ArgusTransportFailure|null The transport failure the call ended with, instead of a response */
    public ?ArgusTransportFailure $transportFailure = null;

    public function uniqueKey(): string
    {
        // the same call can be recorded several times: every one is an entry of its own
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }

    /** The entry in the stored recording format */
    public function toStorageArray(): array
    {
        $storageArray = [
            'endpoint' => $this->endpoint,
            'requestBody' => $this->requestBody,
            'status' => $this->statusCode,
            'headers' => $this->responseHeaders,
            'responseBody' => $this->responseBody,
            'duringTool' => $this->duringTool,
        ];
        if ($this->kind !== null) {
            $storageArray['kind'] = $this->kind;
        }
        if ($this->turnOrdinal !== null) {
            $storageArray['turn'] = $this->turnOrdinal;
        }
        if ($this->toolCallLabel !== null) {
            $storageArray['toolCallLabel'] = $this->toolCallLabel;
        }
        if ($this->transportFailure !== null) {
            $storageArray['transportFailure'] = [
                'class' => $this->transportFailure->exceptionClass,
                'message' => $this->transportFailure->message,
            ];
        }
        return $storageArray;
    }

    /** An entry from the stored recording format */
    public static function fromStorageArray(array $storageArray): static
    {
        $argusTransportRecordingEntry = new static();
        $argusTransportRecordingEntry->endpoint = (string)($storageArray['endpoint'] ?? '');
        $argusTransportRecordingEntry->requestBody = (string)($storageArray['requestBody'] ?? '');
        $argusTransportRecordingEntry->statusCode = (int)($storageArray['status'] ?? 0);
        $argusTransportRecordingEntry->responseHeaders = is_array($storageArray['headers'] ?? null) ? $storageArray['headers'] : [];
        $argusTransportRecordingEntry->responseBody = (string)($storageArray['responseBody'] ?? '');
        $argusTransportRecordingEntry->duringTool = (bool)($storageArray['duringTool'] ?? false);
        $argusTransportRecordingEntry->toolCallLabel = isset($storageArray['toolCallLabel']) ? (string)$storageArray['toolCallLabel'] : null;
        $argusTransportRecordingEntry->kind = isset($storageArray['kind']) ? (string)$storageArray['kind'] : null;
        $argusTransportRecordingEntry->turnOrdinal = isset($storageArray['turn']) ? (int)$storageArray['turn'] : null;
        if (is_array($storageArray['transportFailure'] ?? null)) {
            $argusTransportFailure = new ArgusTransportFailure();
            $argusTransportFailure->exceptionClass = (string)($storageArray['transportFailure']['class'] ?? '');
            $argusTransportFailure->message = (string)($storageArray['transportFailure']['message'] ?? '');
            $argusTransportRecordingEntry->transportFailure = $argusTransportFailure;
        }
        return $argusTransportRecordingEntry;
    }
}
