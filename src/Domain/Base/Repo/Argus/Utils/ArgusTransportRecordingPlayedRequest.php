<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\Argus\Utils;

use DDD\Domain\Base\Entities\ValueObject;

/** One request a replay sent through an {@see ArgusTransportRecording}, and the recorded call that answered it */
class ArgusTransportRecordingPlayedRequest extends ValueObject
{
    /** @var string The request's endpoint as sent (method, scheme, host and path) */
    public string $endpoint = '';

    /** @var string The request body as sent */
    public string $requestBody = '';

    /** @var int|null The position of the recorded call that answered it; null when none did */
    public ?int $answeredByPosition = null;

    /** @var string|null The request's kind */
    public ?string $kind = null;

    /** @var int|null The owner turn the request was sent in */
    public ?int $turnOrdinal = null;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
