<?php

declare(strict_types=1);

namespace DDD\Tests\Argus\Support;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;

/** A cached Argus repo whose handler treats a non-2xx answer as a transport failure */
#[ArgusLoad(
    loadEndpoint: 'GET:/service/probe',
    cacheLevel: ArgusCache::CACHELEVEL_MEMORY_AND_DB,
    cacheTtl: ArgusCache::CACHE_TTL_TEN_MINUTES
)]
class ArgusCachedProbe extends ValueObject
{
    use ArgusTrait;

    public ?string $probeKey = null;

    public ?string $loadedValue = null;

    protected function getLoadPayload(): ?array
    {
        return ['query' => ['key' => $this->probeKey]];
    }

    public function handleLoadResponse(mixed &$callResponseData = null, ?ArgusApiOperation &$apiOperation = null): void
    {
        if (static::rejectionReasonForNon2xxAnswer($callResponseData, $apiOperation) !== null) {
            $this->postProcessLoadResponse($callResponseData, false, cacheFailure: false);
            return;
        }
        $responseData = $this->getResponseDataFromArgusResponse($callResponseData);
        $this->loadedValue = $responseData->value ?? null;
        $this->postProcessLoadResponse($callResponseData, $responseData !== null);
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic((string)$this->probeKey);
    }
}
