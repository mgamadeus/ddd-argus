<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\Argus\Utils;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One HTTP request the Argus executor sent, as recorded in {@see ArgusApiOperations::getCallJournal()}: the endpoint
 * (`METHOD:path` without query), the transfer time and the status code. Start and end are on the process clock
 * (hrtime in milliseconds), so a reader can tell concurrent requests (overlapping spans) from serial ones.
 */
class ArgusSentCallJournalEntry extends ValueObject
{
    /** @var string The endpoint as `METHOD:path`, without query, at most 160 characters */
    public string $endpoint = '';

    /** @var int The transfer time in milliseconds, as measured by the HTTP client */
    public int $transferMilliseconds = 0;

    /** @var int|null The HTTP status code; null for a call without a response (rejected at the transport level) */
    public ?int $statusCode = null;

    /** @var int When the transfer started, on the process clock in milliseconds */
    public int $startedAtMilliseconds = 0;

    /** @var int When the transfer ended, on the process clock in milliseconds */
    public int $endedAtMilliseconds = 0;

    public function uniqueKey(): string
    {
        // identical requests are legitimate: every one of them is an entry of its own
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
