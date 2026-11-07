<?php

declare(strict_types=1);

namespace DDD\Tests\Argus\Support;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;

#[ArgusLoad(loadEndpoint: 'GET:/service/timeout', timeout: 12)]
class ArgusTimeoutFromAttributeProbe extends ValueObject
{
    use ArgusTrait;

    public const int DEFAULT_ARGUS_TIMEOUT = 30;
}
