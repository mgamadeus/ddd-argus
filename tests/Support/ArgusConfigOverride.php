<?php

declare(strict_types=1);

namespace DDD\Tests\Argus\Support;

use DDD\Infrastructure\Libs\Config;
use ReflectionProperty;

/** Sets the `Base.Repo.argus` config of a test and restores the config tree afterwards */
class ArgusConfigOverride
{
    protected static ?array $configTreeBefore = null;

    public static function apply(array $argusConfig): void
    {
        $configTreeProperty = new ReflectionProperty(Config::class, 'configTree');
        $configTree = $configTreeProperty->isInitialized() ? $configTreeProperty->getValue() : [];
        self::$configTreeBefore ??= $configTree;
        $configTree['Base']['Repo']['argus'] = $argusConfig;
        $configTreeProperty->setValue(null, $configTree);
    }

    public static function restore(): void
    {
        if (self::$configTreeBefore === null) {
            return;
        }
        (new ReflectionProperty(Config::class, 'configTree'))->setValue(null, self::$configTreeBefore);
        self::$configTreeBefore = null;
    }
}
