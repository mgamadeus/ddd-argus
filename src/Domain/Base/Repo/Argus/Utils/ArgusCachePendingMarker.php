<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\Argus\Utils;

use DDD\Domain\Base\Entities\ValueObject;

/** The announcement of a running computation of one cached Argus entity ({@see ArgusCacheInFlightComputation}) */
class ArgusCachePendingMarker extends ValueObject
{
    /** @var int The Unix time the announcement expires at */
    public int $expiresAt = 0;

    /** @var string The token of the computation that wrote it */
    public string $ownerToken = '';

    /** The marker as it is stored: `<expiresAt>:<ownerToken>` */
    public function toStoredValue(): string
    {
        return $this->expiresAt . ':' . $this->ownerToken;
    }

    /** A marker from its stored value, null for an empty or unreadable value */
    public static function fromStoredValue(mixed $storedValue): ?static
    {
        if (!is_string($storedValue) || $storedValue === '') {
            return null;
        }
        [$expiresAtText, $ownerToken] = array_pad(explode(':', $storedValue, 2), 2, '');
        $expiresAt = (int)$expiresAtText;
        if ($expiresAt <= 0) {
            return null;
        }
        $argusCachePendingMarker = new static();
        $argusCachePendingMarker->expiresAt = $expiresAt;
        $argusCachePendingMarker->ownerToken = $ownerToken;
        return $argusCachePendingMarker;
    }
}
