<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\Argus\Utils;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The calls of an {@see ArgusTransportRecording}, in the order they were sent; an entry's position is its index.
 *
 * @property ArgusTransportRecordingEntry[] $elements
 * @method ArgusTransportRecordingEntry[] getElements()
 * @method ArgusTransportRecordingEntry|null first()
 */
class ArgusTransportRecordingEntries extends ObjectSet
{
    /** The entry at a position, or null */
    public function findEntryAtPosition(int $position): ?ArgusTransportRecordingEntry
    {
        return $this->elements[$position] ?? null;
    }
}
