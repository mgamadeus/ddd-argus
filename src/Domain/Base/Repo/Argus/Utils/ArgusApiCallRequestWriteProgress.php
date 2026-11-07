<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\Argus\Utils;

/**
 * Whether the request bytes of ONE call {@see ArgusApiOperations::sendExecution()} sent are fully written, and when —
 * handed to the call as Guzzle's `progress` option, which cURL invokes from inside its transfer. The request counts as
 * written once every upload byte left or the first answer byte arrived. A plain runtime object, never persisted.
 */
class ArgusApiCallRequestWriteProgress
{
    public bool $requestWritten = false;

    /** @var int|float|null The hrtime the request was seen fully written at */
    public int|float|null $requestWrittenAtHr = null;

    /** Guzzle's `progress` callback: download total, downloaded, upload total, uploaded (bytes) */
    public function __invoke(int $downloadTotal, int $downloaded, int $uploadTotal, int $uploaded): void
    {
        if ($this->requestWritten) {
            return;
        }
        if (($uploadTotal > 0 && $uploaded >= $uploadTotal) || $downloaded > 0 || $downloadTotal > 0) {
            $this->requestWritten = true;
            $this->requestWrittenAtHr = hrtime(true);
        }
    }
}
