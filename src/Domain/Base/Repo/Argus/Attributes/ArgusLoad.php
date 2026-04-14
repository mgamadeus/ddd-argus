<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\Argus\Attributes;

use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperations;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use DDD\Infrastructure\Traits\Serializer\SerializerTrait;

/**
 * encapsules all relevant properties for argus loading
 * used by Trait ArgusLoad
 */
#[Attribute(Attribute::TARGET_CLASS)]
class ArgusLoad
{
    use SerializerTrait, BaseAttributeTrait;

    public const SERIALIZATION_METHOD_SERIALIZE = 'serialize';
    public const SERIALIZATION_METHOD_TO_OBJECT = 'toObject';


    /** @var bool If set to true, all caching is globally deactivated, ignoring individual parameters passed on load() calls */
    public static bool $deactivateArgusCache = false;

    /**
     * @var bool If set to true, all calls and reponses are stored locally in ArgusApiOperations and can be access by ArgusApiOperations::getExecutedArgusCalls();
     */
    public static bool $logArgusCalls = false;

    /** @var string|array|null Endpoint called for LOAD operations */
    public string|array|null $loadEndpoint;

    /** @var string|array|null Endpoint called for UPDATE operations */
    public string|array|null $updateEndpoint;

    /** @var string|array|null Endpoint called for DELETE operations */
    public string|array|null $deleteEndpoint;

    /** @var string|array|null Endpoint called for CREATE operations */
    public string|array|null $createEndpoint;

    /** @var string|array|null Endpoint called for SYNCHRONIZE operations */
    public string|array|null $synchronizeEndpoint;


    /** @var int|mixed cachelevel of the oject */
    public int $cacheLevel = ArgusCache::CACHELEVEL_NONE;

    /** @var string The way we serialize the object on caching operations */
    public string $serializationMethod = self::SERIALIZATION_METHOD_TO_OBJECT;

    /** @var int caching lifetime in seconds */
    public int $cacheTtl = 0;

    /** @var float The time after the load times out */
    public float $timeout = 0;

    public function __construct(
        string|array|null $loadEndpoint = null,
        string|array|null $createEndpoint = null,
        string|array|null $updateEndpoint = null,
        string|array|null $deleteEndpoint = null,
        string|array|null $synchronizeEndpoint = null,
        $cacheLevel = ArgusCache::CACHELEVEL_MEMORY_AND_DB,
        $cacheTtl = 0,
        float $timeout = 0
    ) {
        $this->loadEndpoint = $loadEndpoint;
        $this->createEndpoint = $createEndpoint;
        $this->updateEndpoint = $updateEndpoint;
        $this->deleteEndpoint = $deleteEndpoint;
        $this->synchronizeEndpoint = $synchronizeEndpoint;
        $this->cacheLevel = $cacheLevel;
        $this->cacheTtl = $cacheTtl;
        $this->timeout = $timeout;
    }
}
