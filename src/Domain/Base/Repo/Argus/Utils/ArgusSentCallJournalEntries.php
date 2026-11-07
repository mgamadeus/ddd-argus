<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\Argus\Utils;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The HTTP requests the Argus executor sent, in the order they ended ({@see ArgusApiOperations::getCallJournal()}).
 *
 * @property ArgusSentCallJournalEntry[] $elements
 * @method ArgusSentCallJournalEntry[] getElements()
 * @method ArgusSentCallJournalEntry|null first()
 */
class ArgusSentCallJournalEntries extends ObjectSet
{
}
