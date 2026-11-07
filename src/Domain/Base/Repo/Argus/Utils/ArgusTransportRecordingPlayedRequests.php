<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\Argus\Utils;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The requests a replay sent through an {@see ArgusTransportRecording}, in order; a request's position is its index.
 *
 * @property ArgusTransportRecordingPlayedRequest[] $elements
 * @method ArgusTransportRecordingPlayedRequest[] getElements()
 * @method ArgusTransportRecordingPlayedRequest|null first()
 */
class ArgusTransportRecordingPlayedRequests extends ObjectSet
{
}
