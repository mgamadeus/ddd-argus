<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\Argus;

use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiCacheOperations;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperations;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DDD\Infrastructure\Traits\Serializer\SerializerTrait;

/**
 * encapsules all relevant properties for argus loading
 * used by Trait ArgusLoad
 */
class ArgusSettings
{
    use SerializerTrait;

    /** @var ArgusLoad ArgusLoad Attribute instance */
    public ArgusLoad $argusLoad;

    /** @var bool determines if the current object shall be laoded or not */
    public bool $toBeLoaded = false;

    /** @var bool shall this object be automatically loaded */
    public bool $autoload = false;

    /** @var bool loaded state */
    public bool $isLoaded = false;

    /** @var bool loaded state */
    public bool $isLoadedSuccessfully = false;

    /** @var bool has this oject been loaded from cache */
    public bool $loadedFromCache = false;

    /** @var bool has loading been prepared */
    public bool $loadingPrepared = false;

    /** @var int the count of loaded child objects */
    public int $childrenLoaded = 0;

    /** @var int the total count of child that shall be loaded */
    public int $childrenToLoad = 0;

    /** @var float The time after the load times out */
    public float $timeout = 0;

    /** @var ArgusApiOperations|null Api operations object storing all Argus call operations and loading logic */
    public ?ArgusApiOperations $apiOperations;

    /** @var ArgusApiOperations|null Api cache operations object storing all Argus cache call operations and loading logic */
    public ?ArgusApiCacheOperations $cacheOperations;

    public function __construct(
        ArgusLoad $argusLoad = null,
    ) {
        $this->argusLoad = $argusLoad;
    }

    /**
     * @return array|string|null Returns endpoint for LOAD operations
     */
    public function getLoadEndpoint(): array|string|null
    {
        return $this->argusLoad->loadEndpoint;
    }

    /**
     * @return array|string|null Returns endpoint for CREATE operations
     */
    public function getCreateEndpoint(): array|string|null
    {
        return $this->argusLoad->createEndpoint;
    }

    /**
     * @return array|string|null Returns endpoint for UPDATE operations
     */
    public function getUpdateEndpoint(): array|string|null
    {
        return $this->argusLoad->updateEndpoint;
    }

    /**
     * @return array|string|null Returns endpoint for DELETE operations
     */
    public function getDeleteEndpoint(): array|string|null
    {
        return $this->argusLoad->deleteEndpoint;
    }

    /**
     * @return string|array|null Returns endpoint(s) for SYNCHRONIZE operations
     */
    public function getSynchronizeEndpoint(): string|array|null
    {
        return $this->argusLoad->synchronizeEndpoint;
    }

    /**
     * @return int Returns Cache Level
     */
    public function getCacheLevel(): int
    {
        return $this->argusLoad->cacheLevel;
    }

    public function getSerializationMethod(): string
    {
        return $this->argusLoad->serializationMethod;
    }

    public function getCacheTTL(): int
    {
        return $this->argusLoad->cacheTtl;
    }

    /**
     * @return bool Returns if Entity is Cachable
     */
    public function isCachable(): bool
    {
        return $this->argusLoad->cacheTtl > 0 && $this->argusLoad->cacheLevel != ArgusCache::CACHELEVEL_NONE;
    }

    public function isToBeLoaded(): bool
    {
        return ($this->toBeLoaded || $this->autoload) && !$this->isLoaded;
    }

    /**
     * initialize API Operations & ArgusCache Operations
     * @return void
     */
    public function initOperations(): void
    {
        if (!$this->apiOperations) {
            $this->apiOperations = new ArgusApiOperations();
        }
        if (!$this->cacheOperations) {
            $this->cacheOperations = new ArgusApiCacheOperations();
        }
    }

    /**
     * @return void
     */
    public function resetOperations()
    {
        $this->apiOperations = null;
        $this->cacheOperations = null;
    }

    public function setToBeLoaded(bool $toBeLoaded)
    {
        $this->toBeLoaded = $toBeLoaded;
        if (!$toBeLoaded) {
            $this->autoload = false;
        }
    }

}
