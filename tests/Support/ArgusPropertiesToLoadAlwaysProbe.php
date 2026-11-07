<?php

declare(strict_types=1);

namespace DDD\Tests\Argus\Support;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;

/** An Argus repo whose per-class "load always" selection a worker reset must clear */
class ArgusPropertiesToLoadAlwaysProbe extends ValueObject
{
    use ArgusTrait;

    /** @return array The class's per-class selection */
    public static function getPropertiesToLoadAlways(): array
    {
        return static::$propertiesToLoadAlways;
    }
}
