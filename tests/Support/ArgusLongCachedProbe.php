<?php

declare(strict_types=1);

namespace DDD\Tests\Argus\Support;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;

/** A repo cached for a month whose handler caches every failure as empty (the plain postProcessLoadResponse(…, false)) */
#[ArgusLoad(
    loadEndpoint: 'GET:/service/long-cached',
    cacheLevel: ArgusCache::CACHELEVEL_MEMORY_AND_DB,
    cacheTtl: ArgusCache::CACHE_TTL_ONE_MONTH
)]
class ArgusLongCachedProbe extends ValueObject
{
    use ArgusTrait;

    public ?string $probeKey = null;

    protected function getLoadPayload(): ?array
    {
        return ['query' => ['key' => $this->probeKey]];
    }

    public function handleLoadResponse(mixed &$callResponseData = null, ?ArgusApiOperation &$apiOperation = null): void
    {
        $responseData = $this->getResponseDataFromArgusResponse($callResponseData);
        $this->postProcessLoadResponse($callResponseData, $responseData !== null);
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic((string)$this->probeKey);
    }
}
