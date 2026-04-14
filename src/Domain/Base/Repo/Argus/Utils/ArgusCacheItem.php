<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\Argus\Utils;

use DDD\Infrastructure\Traits\Serializer\SerializerTrait;

/**
 * @todo replace Api_Cache Class by symfony cache and config
 * getMulti is essential here, it loads multiple cache elements with a single redis query and
 * by thid reduces roundtrip times significantly
 */
class ArgusCacheItem
{
    use SerializerTrait;

    public bool $loaded = false;
    public ?int $validUntil;
    public mixed $data;
    public ?int $cacheSource = ArgusCache::CACHELEVEL_MEMORY;
}
