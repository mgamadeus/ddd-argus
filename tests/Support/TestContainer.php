<?php

declare(strict_types=1);

namespace DDD\Tests\Argus\Support;

use ReflectionClass;
use Symfony\Component\DependencyInjection\Container;

/** The suite's minimal container: services are resolved by instantiating the requested class */
class TestContainer extends Container
{
    protected array $testInstances = [];

    public function get(string $id, int $invalidBehavior = self::EXCEPTION_ON_INVALID_REFERENCE): ?object
    {
        if (!isset($this->testInstances[$id])) {
            if (!class_exists($id)) {
                return null;
            }
            $reflectionClass = new ReflectionClass($id);
            $this->testInstances[$id] = $reflectionClass->getConstructor()
            && $reflectionClass->getConstructor()->getNumberOfRequiredParameters() > 0
                ? $reflectionClass->newInstanceWithoutConstructor()
                : new $id();
        }
        return $this->testInstances[$id];
    }

    public function has(string $id): bool
    {
        return class_exists($id) || parent::has($id);
    }
}
