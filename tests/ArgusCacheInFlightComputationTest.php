<?php

declare(strict_types=1);

namespace DDD\Tests\Argus;

use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCacheInFlightComputation;
use DDD\Tests\Argus\Support\InMemoryRecordingCache;
use PHPUnit\Framework\TestCase;

/** A second reader joins a running computation instead of starting it again */
class ArgusCacheInFlightComputationTest extends TestCase
{
    protected function setUp(): void
    {
        InMemoryRecordingCache::installForArgusCacheGroups();
    }

    protected function tearDown(): void
    {
        InMemoryRecordingCache::uninstall();
    }

    public function testTheFirstReaderClaimsAndASecondReaderFindsItPending(): void
    {
        $computingSide = new ArgusCacheInFlightComputation('entity-key');
        self::assertTrue($computingSide->joinPendingComputationOrClaim(0, 1));

        $joiningSide = new ArgusCacheInFlightComputation('entity-key');
        self::assertTrue($joiningSide->isPending());

        ArgusCache::set('entity-key', '{"value":1}', 600);
        self::assertFalse($joiningSide->joinPendingComputationOrClaim(0, 1));
    }

    public function testOnlyTheOwnerReleasesTheMarker(): void
    {
        $computingSide = new ArgusCacheInFlightComputation('entity-key-2');
        $computingSide->claimComputation();

        (new ArgusCacheInFlightComputation('entity-key-2'))->releaseComputation();
        self::assertTrue($computingSide->isPending());

        $computingSide->releaseComputation();
        self::assertFalse($computingSide->isPending());
    }
}
