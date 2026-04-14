<?php

declare (strict_types=1);

namespace DDD\Domain\Base\Repo\Argus\Utils;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Infrastructure\Traits\Serializer\SerializerTrait;

/**
 * Encapules an Argus API Operation call which can be cached
 */
class ArgusApiCacheOperation
{
    use SerializerTrait;

    /** @var DefaultObject|ArgusTrait|null */
    public DefaultObject|null $entity;

    public ?string $id;

    public ?string $function;

    public ?array $params;

    public ?array $generalParams;

    public ?array $results;

    public int $mergelimit = 1;

    public function __construct(DefaultObject &$entity)
    {
        $this->entity = $entity;
    }

    public function handleResponse(&$results)
    {
        if (empty($results)) {
            return;
        }
        if ($argusLoad = $this->entity->getArgusSettings()) {
            $this->entity->argusLoadFromCache($results);
        }
    }

    public function uniqueKey()
    {
        return $this->entity->cacheKey();
    }
}
