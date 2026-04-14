<?php

declare(strict_types=1);

namespace DDD\Modules\Argus;

use DDD\Infrastructure\Modules\DDDModule;

class ArgusModule extends DDDModule
{
    public static function getSourcePath(): string
    {
        return __DIR__;
    }

    public static function getConfigPath(): ?string
    {
        return null;
    }

    public static function getPublicServiceNamespaces(): array
    {
        return [];
    }
}
