<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\Argus\Utils;

use DDD\Infrastructure\Traits\Serializer\SerializerTrait;

/**
 * Class used for storing data to pass to setElementsToBeLoaded function
 * loadingParameters are passed to setLoadingParameters function, if applicable
 */
class ArgusLoadingParameters
{
    use SerializerTrait;

    /** @var string class name to be loaded, used in setElementsToBeLoaded */
    public string $classNameToBeLoaded;
    /** @var array parameters passed to setLoadingParameters function */
    public array $loadingParameters = [];

    /**
     * @param string $classNameToBeLoaded
     * @param mixed ...$loadingParameters
     */
    public function __construct(string $classNameToBeLoaded, mixed ...$loadingParameters)
    {
        $this->classNameToBeLoaded = $classNameToBeLoaded;
        $this->loadingParameters = $loadingParameters;
    }


    /**
     * returns
     * @param string $classNameToBeLoaded
     * @param ...$loadingParameter
     * @return void
     */
    public static function create(string $classNameToBeLoaded, mixed ...$loadingParameters): ArgusLoadingParameters{
        return new ArgusLoadingParameters($classNameToBeLoaded, ...$loadingParameters);
    }
}
