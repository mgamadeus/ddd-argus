<?php

declare(strict_types=1);

/**
 * Framework objects assume a booted kernel (DefaultObject's lazy-load reflection reaches for DDDService, which reaches
 * for the container). The suite installs a MINIMAL container: real parameters, and services resolved by instantiating
 * the requested class.
 */

use DDD\Tests\Argus\Support\TestContainer;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

require __DIR__ . '/../vendor/autoload.php';

if (!defined('APP_ROOT_DIR')) {
    define('APP_ROOT_DIR', dirname(__DIR__));
}

$testCacheDir = sys_get_temp_dir() . '/ddd_argus_phpunit_cache';
@mkdir($testCacheDir, 0777, true);
@touch($testCacheDir . '/service_class_map.php');
$testContainer = new TestContainer(new ParameterBag([
    'kernel.cache_dir' => $testCacheDir,
    'kernel.project_dir' => dirname(__DIR__),
    'kernel.environment' => 'test',
]));
(new ReflectionProperty(DDD\DDDBundle::class, 'defaultContainer'))->setValue(null, $testContainer);
