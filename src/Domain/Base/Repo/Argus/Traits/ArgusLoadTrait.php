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
            $this->argusSettings = new ArgusSettings($argusLoadInstance);
        }
        else {
            $this->argusSettings = new ArgusSettings(new ArgusLoad());
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
                );
            } catch (InternalErrorException $e) {
                //if execution fails, clean static properties and then throw the error further
                $this->argusSettings->resetOperations();
                throw $e;
            }
        }
        $this->argusSettings->resetOperations();
        // in special cases, like Domain, no basis properties have to be loaded, just children. in this case we set it as
        // loaded and call the callback function, otherwise e.g. db caching would not be executed
        if (!$this->argusSettings->loadedFromCache && $this->argusSettings->isToBeLoaded(
            ) && !$this->argusSettings->getLoadEndpoint()) {
            $this->handleLoadResponse();
        }
        $this->argusSettings->resetOperations();
        return;
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
     * Is to be called AFTER individual handleLoadResponse implementation
     * handles aspects as setting then object loaded and caching
     * @param mixed|null $callResponseData response data data
     * @param bool $successfull loading was successful
     * @return void
     */
    protected function postProcessLoadResponse(
        mixed &$callResponseData = null,
        bool $successfull = true
    ) {
        if ($this->parent && method_exists($this->parent, 'incLoadedChildren')) {
            $this->parent->incLoadedChildren();
        }
        $this->argusSettings->isLoaded = true;
        $this->argusSettings->toBeLoaded = false;
        $this->argusSettings->isLoadedSuccessfully = $successfull;
        if ($this->argusSettings->isCachable() && !$this->argusSettings->loadedFromCache) {
            if (!$successfull) {
                ArgusCache::set(
                    $this->cacheKey(),
                    '',
                    $this->argusSettings->getCacheTTL(),
                    $this->argusSettings->getCacheLevel()
                );
            } else {
                $serialized = $this->argusSettings->getSerializationMethod(
                ) == ArgusLoad::SERIALIZATION_METHOD_TO_OBJECT ? $this->toJSON(
                    true
                ) : serialize($this);
                //echo $this->cacheKey() . ' Set<br />';
                ArgusCache::set(
                    $this->cacheKey(),
                    $serialized,
                    $this->argusSettings->getCacheTTL(),
                    $this->argusSettings->getCacheLevel()
                );
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
