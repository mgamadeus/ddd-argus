<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\Argus\Utils;

use DDD\Domain\Base\Entities\ValueObject;

/** A recorded call that failed at the transport level (a timeout, a refused connection): the exception class and message */
class ArgusTransportFailure extends ValueObject
{
    /** @var string The exception class the transport failed with */
    public string $exceptionClass = '';

    /** @var string The exception's message */
    public string $message = '';
}
