<?php

declare (strict_types=1);

namespace DDD\Domain\Base\Repo\Argus\Utils;

/**
 * Multiple ArgusCache Calls are combined into a single Call e.g. to Redis cluster
 * and by this latency is reduced.
 * All ArgusCache Calls are encapsuled into Atomic ArgusCache operations which are then sent at once
 *
 */
class ArgusApiCacheOperations
{
    /** @var ArgusApiCacheOperation[]|null */
    public ?array $operations;

    /** @var ArgusApiCacheOperation[]|null */
    public ?array $operationsByUniqueKey;

    public function __construct()
    {
        $this->operations = [];
        $this->operationsByUniqueKey = [];
    }

    /**
     * @param ArgusApiCacheOperation $operation
     */
    public function addOperation(ArgusApiCacheOperation &$operation)
    {
        $uniqueKey = $operation->uniqueKey();
        if (isset($this->operations_unique_key[$uniqueKey])) // keep sure, we are not adding duplicates
        {
            return;
        }
        $this->operationsByUniqueKey[$uniqueKey] = $operation;
        $this->operations[] = $operation;
    }

    /**
     * Loads all cache operation at once and sets results to Argus Objects
     * @return void
     */
    public function execute(): void
    {
        if (!count($this->operations)) {
            return;
        }
        $keys = [];
        foreach ($this->operationsByUniqueKey as $key => $value) {
            $keys[] = $key;
        }

        $multiResult = ArgusCache::getMulti($keys);
        if (!$multiResult) {
            return;
        }
        foreach ($multiResult as $key => $result) {
            if (isset($this->operationsByUniqueKey[$key])) {
                $this->operationsByUniqueKey[$key]->handleResponse($result);
            }
        }
    }
}
