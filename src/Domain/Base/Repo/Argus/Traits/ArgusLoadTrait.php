<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\Argus\Traits;

use DDD\Domain\Base\Repo\Argus\ArgusEntity;
use DDD\Domain\Base\Repo\Argus\ArgusSettings;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Enums\ArgusApiOperationType;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiCacheOperation;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiCacheOperations;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperations;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusLoadingParameters;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\ParentChildrenTrait;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Exceptions\UnauthorizedException;
use DDD\Infrastructure\Traits\AfterConstruct\Attributes\AfterConstruct;
use Doctrine\ORM\NonUniqueResultException;
use JsonException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * encapsules all Argus Loading operations
 */
trait ArgusLoadTrait
{
    use ParentChildrenTrait;

    /** @var ArgusSettings|null an instance of the ArgusSettings attribute holding all loading relevant information and procedures related to Argusloading */
    protected ?ArgusSettings $argusSettings;

    /**
     * @var array Properties set here are loaded always on any load operation
     * as the values are static, we store these static associated to the class where setPropertiesToLoadAlways is called
     */
    protected static array $propertiesToLoadAlways = [];

    /**
     * A new worker job starts without the per-class "load these children always" selection a previous job set through
     * {@see self::setPropertiesToLoadAlways()}. Trait static: every class that USES the trait holds its own copy, so
     * the worker reset ({@see \DDD\Symfony\EventListeners\ArgusWorkerStateResetter}) runs this on each of them.
     */
    public static function resetPropertiesToLoadAlwaysForNewJob(): void
    {
        self::$propertiesToLoadAlways = [];
    }

    /**
     * creates ArgusLoad attribute instance
     * @return void
     */
    #[AfterConstruct]
    public function initArgusLoad()
    {
        $this->getArgusSettings();
    }

    public function getArgusSettings(): ArgusSettings
    {
        if ($this->argusSettings ?? null) {
            return $this->argusSettings;
        }
        /** @var ArgusLoad $argusLoadInstance */
        $argusLoadInstance = self::getAttributeInstance(ArgusLoad::class);
        if ($argusLoadInstance) {
            // a COPY: the attribute instance is cached per class, so a repo that changes its own endpoint or timeout
            // would otherwise change it for every later instance of the class in this process
            $this->argusSettings = new ArgusSettings(clone $argusLoadInstance);
        }
        else {
            $this->argusSettings = new ArgusSettings(new ArgusLoad());
        }
        // a repo class (or a trait it uses) can declare a DEFAULT_ARGUS_TIMEOUT constant as its fallback timeout in
        // seconds; a non-zero `timeout:` on #[ArgusLoad] wins; without either the timeout stays 0 = wait forever
        if (!$this->argusSettings->timeout && defined(static::class . '::DEFAULT_ARGUS_TIMEOUT')) {
            $this->argusSettings->timeout = (float)constant(static::class . '::DEFAULT_ARGUS_TIMEOUT');
        }
        return $this->argusSettings;
    }

    /**
     * Initiates loading for current object and children
     * @param bool $useArgusEntityCache if true, argus entity cached is used (lifetime and cachetype is defined on ArgusLoad attribute)
     * @param bool $useApiACallCache if true, a simple cache on call level is used, that simply caches the result of a call using a hash of the call data as cache key
     * @param bool $displayCall if true, the loading calls and parameters are outputed by echo
     * @param bool $displayResponse if true, the loading response is outputed by echo
     * @param string|null $jobLabel job label for logging purposes
     * @param bool $autoloadCurrentObject if true, current object is set to be loaded by default, otherwise the method will not set the current object to be loaded by default
     * @param int|null $apiCallCacheLifetime the lifetime of the simple API call cache
     * @return void
     * @throws InternalErrorException
     * @throws \InvalidArgumentException an endpoint without request method (nothing of the batch is sent)
     */
    public function argusLoad(
        bool $useArgusEntityCache = true,
        bool $useApiACallCache = true,
        bool $displayCall = false,
        bool $displayResponse = false,
        bool $autoloadCurrentObject = true,
        ?int $apiCallCacheLifetime = null
    ): void {
        if (ArgusLoad::$deactivateArgusCache) {
            $useArgusEntityCache = false;
            $useApiACallCache = false;
        }
        if (isset(static::$propertiesToLoadAlways[static::class])) {
            $this->setPropertiesToLoad(...static::$propertiesToLoadAlways[static::class]);
        }
        $this->argusSettings->loadingPrepared = false;
        $this->argusSettings->isLoaded = false;
        $this->argusSettings->resetOperations();
        $this->argusPrepareLoad($useArgusEntityCache, $autoloadCurrentObject);
        if ($this->argusSettings->apiOperations) {
            try {
                $this->argusSettings->apiOperations->execute(
                    $displayCall,
                    $displayResponse,
                    $useApiACallCache,
                    true,
                    ArgusApiOperationType::LOAD,
                    $this->argusSettings?->timeout ?? 0,
                    $this->argusSettings?->keepFulfilledCallsWhenOneIsRejected ?? false,
                );
            } catch (InternalErrorException|\InvalidArgumentException $e) {
                //if execution fails, clean static properties and then throw the error further
                $this->argusSettings->resetOperations();
                throw $e;
            }
        }
        $this->completeArgusLoad();
    }

    /**
     * {@see argusLoad()} in two halves, for a caller that has work of its own while the answers travel: this half
     * prepares the load (the caches included) and SENDS its calls; {@see advanceArgusLoadRequests()} moves them forward
     * between the caller's steps and {@see receiveArgusLoadResponses()} waits for the answers and completes the load.
     * Returns false when the load ran to its end here already — the execution cannot be split (more than one call
     * round, a recorded run): then nothing is to be received. That load runs under `$blockingLoadTimeoutSeconds` when
     * given (a caller whose split timeout is longer than its own deadline), else under the settings' timeout.
     *
     * @throws InternalErrorException
     * @throws \InvalidArgumentException an endpoint without request method (nothing of the batch is sent)
     */
    public function sendArgusLoadRequests(
        bool $useArgusEntityCache = true,
        bool $useApiACallCache = true,
        bool $autoloadCurrentObject = true,
        ?float $blockingLoadTimeoutSeconds = null
    ): bool {
        if (ArgusLoad::$deactivateArgusCache) {
            $useArgusEntityCache = false;
            $useApiACallCache = false;
        }
        if (isset(static::$propertiesToLoadAlways[static::class])) {
            $this->setPropertiesToLoad(...static::$propertiesToLoadAlways[static::class]);
        }
        $this->argusSettings->loadingPrepared = false;
        $this->argusSettings->isLoaded = false;
        $this->argusSettings->resetOperations();
        $this->argusPrepareLoad($useArgusEntityCache, $autoloadCurrentObject);
        if ($this->argusSettings->apiOperations) {
            try {
                if ($this->argusSettings->apiOperations->sendExecution(
                    ArgusApiOperationType::LOAD,
                    $this->argusSettings?->timeout ?? 0,
                    $this->argusSettings?->keepFulfilledCallsWhenOneIsRejected ?? false,
                )) {
                    return true;
                }
                $this->argusSettings->apiOperations->execute(
                    false,
                    false,
                    $useApiACallCache,
                    true,
                    ArgusApiOperationType::LOAD,
                    $blockingLoadTimeoutSeconds ?? $this->argusSettings?->timeout ?? 0,
                    $this->argusSettings?->keepFulfilledCallsWhenOneIsRejected ?? false,
                );
            } catch (InternalErrorException|\InvalidArgumentException $e) {
                $this->argusSettings->resetOperations();
                throw $e;
            }
        }
        $this->completeArgusLoad();
        return false;
    }

    /** Move the calls of {@see sendArgusLoadRequests()} forward without waiting for them */
    public function advanceArgusLoadRequests(): void
    {
        $this->argusSettings->apiOperations?->advanceExecution();
    }

    /**
     * Move the calls of {@see sendArgusLoadRequests()} forward until their requests are fully written, or at most
     * `$maxSeconds`; returns whether they are.
     */
    public function advanceArgusLoadRequestsUntilWritten(float $maxSeconds): bool
    {
        return $this->argusSettings->apiOperations?->advanceExecutionUntilRequestsWritten($maxSeconds) ?? false;
    }

    /** When the last request of {@see sendArgusLoadRequests()} was written; null while one is not written yet */
    public function findArgusLoadRequestsWrittenAtHr(): int|float|null
    {
        return $this->argusSettings->apiOperations?->findRequestsWrittenAtHr();
    }

    /**
     * Wait for the answers of {@see sendArgusLoadRequests()} (only after it returned true) and complete the load exactly
     * as {@see argusLoad()} does. With `$deadlineHr` (an hrtime) the calls still pending then are cancelled.
     *
     * @throws InternalErrorException
     */
    public function receiveArgusLoadResponses(int|float|null $deadlineHr = null): void
    {
        try {
            $this->argusSettings->apiOperations?->receiveExecution($deadlineHr);
        } catch (InternalErrorException $e) {
            $this->argusSettings->resetOperations();
            throw $e;
        }
        $this->completeArgusLoad();
    }

    /** The end of every load: the operations are dropped, a load without an endpoint of its own gets its callback */
    protected function completeArgusLoad(): void
    {
        $this->argusSettings->resetOperations();
        // in special cases, like Domain, no basis properties have to be loaded, just children. in this case we set it as
        // loaded and call the callback function, otherwise e.g. db caching would not be executed
        if (!$this->argusSettings->loadedFromCache && $this->argusSettings->isToBeLoaded(
            ) && !$this->argusSettings->getLoadEndpoint()) {
            $this->handleLoadResponse();
        }
        $this->argusSettings->resetOperations();
    }

    /**
     * Initializes API operations, constructs api Cache operations and loads them instantly
     * Constructs API operations
     * @param bool $forceCacheRefresh
     * @return $this
     */
    public function argusPrepareLoad(bool $useArgusEntityCache = true, bool $autoloadCurrentObject = true): void
    {
        if ($this->argusSettings->isLoaded || $this->argusSettings->loadingPrepared) {
            return;
        } //already loaded
        $this->argusSettings->initOperations();

        $this->argusSettings->toBeLoaded = $autoloadCurrentObject;
        $null = null;
        //echo json_encode($this->getObjectStructure());die();
        // construct and load cached operations
        $this->constructApiOperations([], $useArgusEntityCache, $null, $this->argusSettings->cacheOperations, true);
        $this->argusSettings->cacheOperations->execute();
        // construct api operations
        $this->constructApiOperations([], $useArgusEntityCache, $this->argusSettings->apiOperations, $null, false);
        $this->argusSettings->loadingPrepared = true;
    }

    /**
     * Constructs recursively api operations by checking current object if it is to be laoded
     * if yes, checks if object has all necessities to be loaded (loadEndpoint and getLoadPayload())
     * if all prequisites are satisfied an API operation (either a cache or a normal api operation) is created for this object
     * the same procedure is done recursively for the object's children
     * @param array $path
     * @param bool $useArgusEntityCache
     * @param ArgusApiOperations|null $apiOperations
     * @param ArgusApiCacheOperations|null $cacheOperations
     * @param bool $executeForCachedElements
     * @return void
     */
    public function constructApiOperations(
        array $path = [],
        bool $useArgusEntityCache = true,
        ?ArgusApiOperations &$apiOperations = null,
        ?ArgusApiCacheOperations &$cacheOperations = null,
        bool $executeForCachedElements = true
    ) {
        if (isset($path[spl_object_hash($this)])) {
            return;
        }
        $path[spl_object_hash($this)] = true;
        //echo 'constructApiOperations: ' . $this->cacheKey() . "<br />";
        if ($executeForCachedElements) {
            if ($this->argusSettings->isCachable() && $useArgusEntityCache && $this->argusSettings->isToBeLoaded()) {
                // For Cachelevel Memory, loading is instant and we do not need to commission multiple elements at once
                if (($this->argusSettings->getCacheLevel(
                    ) == ArgusCache::CACHELEVEL_MEMORY || $this->argusSettings->getCacheLevel(
                    ) == ArgusCache::CACHELEVEL_MEMORY_AND_DB)) {
                    $cached = ArgusCache::get($this->cacheKey(), ArgusCache::CACHELEVEL_MEMORY);
                    //echo $this->cacheKey() . ' try load ' . $cached->loaded. '<br />';
                    //echo $this->cacheKey() . ' try to load from cache<br />';
                    if ($cached && $cached->loaded) {
                        if ($cached->validUntil < time() || !$cached->data) {
                            ArgusCache::delete($this->cacheKey());
                        } else {
                            // this must be set before, since in loadFromObject handleloadingCallback can be triggered already
                            $this->argusLoadFromCache($cached->data);
                            unset($cached);
                        }
                    }
                }
                // on CACHELEVEL_DB or CACHELEVEL_MEMORY_AND_DB (in our case Redis) we commission multiple cache keys in order to reduce
                // latency and we load all elements at once
                if (!$this->argusSettings->loadedFromCache && ($this->argusSettings->getCacheLevel(
                        ) == ArgusCache::CACHELEVEL_DB || $this->argusSettings->getCacheLevel(
                        ) == ArgusCache::CACHELEVEL_MEMORY_AND_DB)) {
                    $apiOperation = new ArgusApiCacheOperation($this);
                    $cacheOperations->addOperation($apiOperation);
                }
            }
        }
        //Recusrive construct operations for children
        if ($this->children && $this->children->count()) {
            foreach ($this->getChildren() as $child) {
                /** @var ArgusEntity $child */
                if (isset($child->argusSettings) && ($child->argusSettings->isToBeLoaded(
                        ) || $child->argusSettings->isLoaded) && !$executeForCachedElements) {
                    $this->argusSettings->childrenToLoad++;
                }
                if (method_exists($child, 'constructApiOperations')) {
                    $child->constructApiOperations(
                        $path,
                        $useArgusEntityCache,
                        $apiOperations,
                        $cacheOperations,
                        $executeForCachedElements
                    );
                }
            }
        }
        // operations for object itself: loading of the object itself has to be completed after loading of the children
        if (!$executeForCachedElements && $this->argusSettings->isToBeLoaded(
            ) && ($loadEndpoint = $this->argusSettings->getLoadEndpoint()) && ($loadPayload = $this->getLoadPayload(
            ))) {
            //echo "NOT LOADED from cache: ". $this->cacheKey()."\n <br /
            $loadEndpoints = [];
            // load endpoints can either be a string or an array of endpoints
            $loadEndpoints = is_array($loadEndpoint) ? $loadEndpoint : [$loadEndpoint];
            foreach ($loadEndpoints as $loadEndpoint) {
                $cacheKeyAppendix = '';
                $currentLoadPayload = $loadPayload;
                if (is_array($loadEndpoint)) {
                    [$loadEndpoint, $additionalLoadPayload] = $loadEndpoint;
                    $currentLoadPayload = array_merge_recursive($loadPayload, $additionalLoadPayload);
                    $loadEndpoint = ArgusApiOperation::getEndpointWithPathParametersApplied(
                        $loadEndpoint,
                        $currentLoadPayload
                    );
                    $cacheKeyAppendix = $loadEndpoint . '_' . md5(json_encode($additionalLoadPayload));
                } else {
                    $loadEndpoint = ArgusApiOperation::getEndpointWithPathParametersApplied(
                        $loadEndpoint,
                        $currentLoadPayload
                    );
                    $cacheKeyAppendix = $loadEndpoint;
                }

                $timeout = $this->argusSettings->timeout ?? 0;
                $apiOperation = new ArgusApiOperation(
                    $this,
                    $this->cacheKey() . $cacheKeyAppendix,
                    $loadEndpoint,
                    $currentLoadPayload,
                    $timeout
                );
                $apiOperations->addOperation($apiOperation);
            }
        }
        return;
    }

    /**
     * Populates current object from cache load
     * @param $cacheObject
     * @param $setNotExistingProperties
     * @param $considerPropertyDocCommentRules
     * @return void
     */
    public function argusLoadFromCache(
        mixed &$cacheObject,
        bool $setNotExistingProperties = true,
        bool $considerPropertyDocCommentRules = false
    ): void {
        $this->argusSettings->loadedFromCache = true;
        if (!($this instanceof DefaultObject)) {
            return;
        }
        $unserialized = $this->argusSettings->getSerializationMethod() == ArgusLoad::SERIALIZATION_METHOD_TO_OBJECT ? json_decode(
            $cacheObject
        ) : unserialize(
            $cacheObject
        );
        if (!$unserialized) {
            return;
        }
        if (!is_object($unserialized)) {
            return;
        }
        //$this->setPropertiesFromObject($cacheObject);
        if ($this->argusSettings->getSerializationMethod() == ArgusLoad::SERIALIZATION_METHOD_TO_OBJECT) {
            $this->setPropertiesFromObject($unserialized);
        } else {
            $this->setPropertiesFromSerializedObject($unserialized);
        }
        $this->postProcessLoadResponse(successfull: true);
    }

    /**
     * @param bool $displayCall
     * @param bool $displayResponse
     * @return void
     * @throws ReflectionException
     */
    public function argusCreate(
        bool $displayCall = false,
        bool $displayResponse = false
    ): void {
        $this->executeArgusApiOperation(
            ArgusApiOperationType::CREATE,
            $displayCall,
            $displayResponse,
            $this->argusSettings->getCreateEndpoint(),
            $this->getCreatePayload(),
            $this->argusSettings->timeout ?? 0
        );
    }

    /**
     * @param bool $displayCall
     * @param bool $displayResponse
     * @return void
     * @throws InternalErrorException
     * @throws ReflectionException
     * @throws BadRequestException
     * @throws NotFoundException
     * @throws UnauthorizedException
     * @throws NonUniqueResultException
     * @throws JsonException
     * @throws InvalidArgumentException
     */
    public function argusUpdate(
        bool $displayCall = false,
        bool $displayResponse = false
    ): void {
        $this->executeArgusApiOperation(
            ArgusApiOperationType::UPDATE,
            $displayCall,
            $displayResponse,
            $this->argusSettings->getUpdateEndpoint(),
            $this->getUpdatePayload(),
            $this->argusSettings->timeout ?? 0
        );
    }

    /**
     * @param bool $displayCall
     * @param bool $displayResponse
     * @return void
     * @throws ReflectionException
     */
    public function argusDelete(
        bool $displayCall = false,
        bool $displayResponse = false
    ): void {
        $this->executeArgusApiOperation(
            ArgusApiOperationType::DELETE,
            $displayCall,
            $displayResponse,
            $this->argusSettings->getDeleteEndpoint(),
            $this->getDeletePayload(),
            $this->argusSettings->timeout ?? 0
        );
    }

    /**
     * @param bool $displayCall
     * @param bool $displayResponse
     * @return void
     * @throws ReflectionException
     */
    public function argusSynchronize(
        bool $displayCall = false,
        bool $displayResponse = false
    ): void {
        $this->executeArgusApiOperation(
            ArgusApiOperationType::SYNCHRONIZE,
            $displayCall,
            $displayResponse,
            $this->argusSettings->getSynchronizeEndpoint(),
            $this->getSynchronizePayload(),
            $this->argusSettings->timeout ?? 0
        );
    }

    /**
     * Executes an Argus related operation with the given parameters
     * @param ArgusApiOperationType $argusApiOperationType
     * @param bool $displayCall
     * @param bool $displayResponse
     * @param string|array|null $endpoint
     * @param array|null $payload
     * @param float $timeout
     * @return void
     * @throws ReflectionException
     */
    protected function executeArgusApiOperation(
        ArgusApiOperationType $argusApiOperationType,
        bool $displayCall = false,
        bool $displayResponse = false,
        string|array|null $endpoint = null,
        ?array $payload = null,
        float $timeout = 0
    ): void {
        if (!$payload || !$endpoint) {
            return;
        }
        $apiOperations = new ArgusApiOperations();
        $apiOperation = new ArgusApiOperation($this, $this->cacheKey(), $endpoint, $payload);
        $apiOperations->addOperation($apiOperation);
        $apiOperations->execute(
            $displayCall,
            $displayResponse,
            false,
            true,
            $argusApiOperationType,
            $timeout
        );
        $this->clearArgusCache();
    }

    /**
     * Returns the payload for LOAD call
     */
    protected function getLoadPayload(): ?array
    {
        if (method_exists($this, 'getLoadPayloadInternal')) {
            return $this->getLoadPayloadInternal();
        }
        return null;
    }

    /**
     * Returns the payload for CREATE call
     */
    protected function getCreatePayload(): ?array
    {
        return null;
    }

    /**
     * Returns the payload for UPDATE call
     */
    protected function getUpdatePayload(): ?array
    {
        return null;
    }

    /**
     * Returns the payload for DELETE call
     */
    protected function getDeletePayload(): ?array
    {
        return null;
    }

    /**
     * Returns the payload for SYNCHRONIZE call
     */
    protected function getSynchronizePayload(): ?array
    {
        return null;
    }

    /**
     * Sets parameters for controlling of loading, e.g. startTime and endTime for time series data.
     */
    public function setLoadingParameters(): void
    {
    }

    /**
     * Populates this object from Argus Loading Response
     * @param mixed|null $callResponseData
     * @param ArgusApiOperation|null $apiOperation
     * @return void
     */
    public function handleLoadResponse(
        mixed &$callResponseData = null,
        ?ArgusApiOperation &$apiOperation = null
    ): void {
        if (method_exists($this, 'handleLoadResponseInternal')) {
            $this->handleLoadResponseInternal($callResponseData, $apiOperation);
        }
    }

    /**
     * Used for handling response of UPDATE calls on Argus
     * Usually this method is overridden on the Argus entity but in some cases we may use the default load behaviour
     * @param mixed $callResponseData
     * @param ArgusApiOperation|null $apiOperation
     * @return void
     */
    public function handleUpdateResponse(mixed &$callResponseData, ?ArgusApiOperation &$apiOperation = null): void
    {
    }

    /**
     * Used for handling response of DELETE calls on Argus
     * @param mixed $callResponseData
     * @param ArgusApiOperation|null $apiOperation
     * @return void
     */
    public function handleDeleteResponse(mixed &$callResponseData, ?ArgusApiOperation &$apiOperation = null): void
    {
    }

    /**
     * Used for handling response of CREATE calls on Argus
     * @param mixed $callResponseData
     * @param ArgusApiOperation|null $apiOperation
     * @return void
     */
    public function handleCreateResponse(mixed &$callResponseData, ?ArgusApiOperation &$apiOperation = null): void
    {
    }

    /**
     * Used for handling response of SYNCHRONIZE calls on Argus
     * @param mixed $callResponseData
     * @param ArgusApiOperation|null $apiOperation
     * @return void
     */
    public function handleSynchronizeResponse(mixed &$callResponseData, ?ArgusApiOperation &$apiOperation = null): void
    {
    }

    /**
     * increments loaded children count and and triggers populateDataFromLoadingCallback if no loading function is present
     * and all children are loaded
     * @return void
     */
    public function incLoadedChildren(): void
    {
        $this->argusSettings->childrenLoaded++;
        if ($this->argusSettings->childrenToLoad == $this->argusSettings->childrenLoaded && !$this->argusSettings->getLoadEndpoint(
            )) {
            $this->handleLoadResponse();
        }
    }

    /**
     * Sets all properties to be loaded by recurisvely passing through currents objects children.
     * Accepts class names as string or ArgusLoadingParameters, in case of ArgusLoadingParameters passed, it tries to pass the parameters
     * to setElementsToBeLoaded function.
     * @param string|ArgusLoadingParameters ...$classNamesOrArgusLoadingParameters
     * @return void
     */
    public function setPropertiesToLoad(string|ArgusLoadingParameters ...$classNamesOrArgusLoadingParameters): void
    {
        /** @var ArgusLoadingParameters[] $parameters */
        $parameters = [];
        // normalize all to ArgusLoadingParameters
        foreach ($classNamesOrArgusLoadingParameters as $classNameOrArgusLoadingParameter) {
            $parameters[] = is_string($classNameOrArgusLoadingParameter) ? ArgusLoadingParameters::create(
                $classNameOrArgusLoadingParameter
            ) : $classNameOrArgusLoadingParameter;
        }
        $this->setPropertiesToLoadInternal([], ...$parameters);
    }

    /**
     * Set Properties set that shall be loaded on any load operation
     * as the values are static, we store these static associated to the class where this method is called
     * @param string|ArgusLoadingParameters ...$classNamesOrArgusLoadingParameters
     * @return void
     */
    public static function setPropertiesToLoadAlways(
        string|ArgusLoadingParameters ...$classNamesOrArgusLoadingParameters
    ): void {
        self::$propertiesToLoadAlways[static::class] = $classNamesOrArgusLoadingParameters;
    }

    /**
     * Sets all elements to be loaded by recurisvely passing through currents objects children.
     * Accepts cArgusLoadingParameters, it tries to pass the parameters contained
     * to setElementsToBeLoaded function.
     *
     * In order to avoid recursion it uses $path wich contains ids of all objects this function
     * has been called on directly before and returns if it is called again on th same object
     * @param array $path
     * @param string ...$loadingParameters
     * @return void
     */
    public function setPropertiesToLoadInternal(
        array $path = [],
        ArgusLoadingParameters &...$loadingParameters
    ) {
        if (isset($path[spl_object_hash($this)])) {
            return;
        }
        $path[spl_object_hash($this)] = true;

        if (!$loadingParameters) {
            $this->argusSettings->setToBeLoaded(false);
        }
        foreach ($loadingParameters as $loadingParameter) {
            if (static::class == $loadingParameter->classNameToBeLoaded) {
                $this->argusSettings->setToBeLoaded(true);
                if ($loadingParameter->loadingParameters) {
                    $this->setLoadingParameters(...$loadingParameter->loadingParameters);
                }
            }
        }
        // Lazy instance the children that are not instantiated yet
        // in the context of an Argus Repo Entity Lazyload does not load but instead creates an instance of the property
        foreach ($this->getProperties(ReflectionProperty::IS_PUBLIC) as $reflectionProperty) {
            $propertyName = $reflectionProperty->getName();
            if (isset($this->$propertyName)) {
                continue;
            }
            $reflectionType = $reflectionProperty->getType();
            if ($reflectionType instanceof ReflectionNamedType && !$reflectionType->isBuiltin(
                ) && !isset($this->$propertyName)) {
                $typeName = $reflectionType->getName();
                if (!method_exists($typeName, 'getRepoClass')) {
                    continue;
                }
                $argusRepoClass = $this->getRepoClassForProperty(LazyLoadRepo::ARGUS, $propertyName);
                //$typeName::getRepoClass(Repository::REPOTYPE_ARGUS);
                if (!$argusRepoClass) {
                    continue;
                }
                foreach ($loadingParameters as $loadingParameter) {
                    if ($argusRepoClass == $loadingParameter->classNameToBeLoaded
                        // avoid recursive lazyload / lazy instance
                        && static::class != $argusRepoClass) {
                        // LazyLoad removed the property => we just touch it
                        $this->$propertyName = new $argusRepoClass();
                        $this->$propertyName->setParent($this);
                        $this->addChildren($this->$propertyName);
                    }
                }
            }
        }
        foreach ($this->getChildren() as $child) {
            /** @var ArgusLoadTrait $child */
            if ($child instanceof DefaultObject && method_exists($child, 'setPropertiesToLoadInternal')) {
                $child->setPropertiesToLoadInternal($path, ...$loadingParameters);
            }
        }
    }

    /**
     * Why a FULFILLED answer is a failure of the upstream call rather than an empty result: its HTTP status (the
     * recorded {@see ArgusApiOperation::$responseStatusCode}, else the envelope's numeric `status`) is not 2xx. Null for
     * a 2xx answer or when no status is known. A handler passes `cacheFailure: false` to
     * {@see postProcessLoadResponse()} for such an answer, so it is not cached as empty:
     *
     * ```php
     * if (static::rejectionReasonForNon2xxAnswer($callResponseData, $apiOperation) !== null) {
     *     $this->postProcessLoadResponse($callResponseData, false, cacheFailure: false);
     *     return;
     * }
     * ```
     */
    public static function rejectionReasonForNon2xxAnswer(mixed $callResponseData, ?ArgusApiOperation $apiOperation): ?string
    {
        $statusCode = $apiOperation?->responseStatusCode;
        if ($statusCode === null && is_object($callResponseData) && isset($callResponseData->status) && is_numeric($callResponseData->status)) {
            $statusCode = (int)$callResponseData->status;
        }
        if ($statusCode === null || ($statusCode >= 200 && $statusCode < 300)) {
            return null;
        }
        $upstreamMessage = '';
        if (is_object($callResponseData)) {
            // the message, or an error that is a string or an object carrying one (`{"error": {"message": …}}`)
            $upstreamError = $callResponseData->message ?? $callResponseData->error ?? null;
            if (is_object($upstreamError)) {
                $upstreamError = $upstreamError->message ?? null;
            }
            $upstreamMessage = is_scalar($upstreamError) ? trim((string)$upstreamError) : '';
        }
        return 'http ' . $statusCode . ' from the upstream call' . ($upstreamMessage !== '' ? ': ' . mb_substr($upstreamMessage, 0, 160) : '');
    }

    /**
     * Is to be called AFTER individual handleLoadResponse implementation
     * handles aspects as setting then object loaded and caching
     * @param mixed|null $callResponseData response data data
     * @param bool $successfull loading was successful
     * @param bool $cacheFailure false for a TRANSPORT failure (a rejected call, a non-2xx answer — see
     *        {@see rejectionReasonForNon2xxAnswer()}): it is not cached as an empty entry, the next load asks again
     * @return void
     */
    protected function postProcessLoadResponse(
        mixed &$callResponseData = null,
        bool $successfull = true,
        bool $cacheFailure = true
    ) {
        if ($this->parent && method_exists($this->parent, 'incLoadedChildren')) {
            $this->parent->incLoadedChildren();
        }
        $this->argusSettings->isLoaded = true;
        $this->argusSettings->toBeLoaded = false;
        $this->argusSettings->isLoadedSuccessfully = $successfull;
        if ($this->argusSettings->isCachable() && !$this->argusSettings->loadedFromCache) {
            if (!$successfull && $cacheFailure) {
                // the empty entry of a failed load lives at most an hour, however long the class caches its answers:
                // a failure must not stick for the day or month a successful answer is kept
                ArgusCache::set(
                    $this->cacheKey(),
                    '',
                    min($this->argusSettings->getCacheTTL(), ArgusCache::FAILED_LOAD_CACHE_TTL_MAX),
                    $this->argusSettings->getCacheLevel()
                );
            } elseif ($successfull) {
                // Serialize the cache entry like ValueObjectTrait::mapToRepository() — a fresh,
                // persistence-correct pass (ignoreHideAttributes: true, cached: false, forPersistence: true)
                // rather than toJSON(true) (= toObject(cached: true, ...)). cached:true could reuse a
                // serialization computed earlier in the same render while a display-hide was active, persisting
                // a structurally-incomplete blob (missing the union objectType discriminator). cached:false
                // forces a clean re-serialization. The json_encode flags match SerializerTrait::toJSON() so the
                // cached string stays byte-compatible with the argusLoadFromCache() read path.
                // JSON_INVALID_UTF8_SUBSTITUTE: one invalid byte in a third-party payload must not drop the whole
                // entry; a failure is logged instead of swallowed.
                $serialized = $this->argusSettings->getSerializationMethod(
                ) == ArgusLoad::SERIALIZATION_METHOD_TO_OBJECT ? json_encode(
                    $this->toObject(ignoreHideAttributes: true, cached: false, forPersistence: true),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
                ) : serialize($this);
                if ($serialized === false) {
                    error_log(
                        'ArgusLoadTrait: cache entry not written for ' . static::class . ' (' . $this->cacheKey()
                        . '): json_encode failed — ' . json_last_error_msg()
                    );
                } else {
                    ArgusCache::set(
                        $this->cacheKey(),
                        $serialized,
                        $this->argusSettings->getCacheTTL(),
                        $this->argusSettings->getCacheLevel()
                    );
                }
            }
        }
        $this->postProcessObjectAfterLoading();
    }

    /**
     * Override this function in order to manipulate the object after it is loaded either from cache or from argus,
     * e.g. usefully in order to do manipulations on parent objects or on this object that shall not be persisted in caching
     * @return void
     */
    protected function postProcessObjectAfterLoading(): void
    {
    }

    public function clearArgusCache()
    {
        ArgusCache::delete($this->cacheKey());
    }
}
