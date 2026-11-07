<?php

declare(strict_types=1);

namespace DDD\Tests\Argus;

use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperations;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusTransportRecording;
use DDD\Symfony\EventListeners\ArgusWorkerStateResetter;
use DDD\Tests\Argus\Support\ArgusPropertiesToLoadAlwaysProbe;
use DDD\Tests\Argus\Support\ArgusTimeoutFromConstantProbe;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

/** A worker job starts without the previous job's executor statics and per-class selections */
class ArgusWorkerStateResetterTest extends TestCase
{
    public function testTheResetClearsTheExecutorStaticsAndThePerClassSelections(): void
    {
        ArgusApiOperations::$transportRecording = ArgusTransportRecording::forRecording();
        ArgusApiOperations::$journalRequests = true;
        ArgusApiOperations::journalSentRequest('GET:/service/a', 0.1, 200);
        ArgusLoad::$logArgusCalls = true;
        ArgusPropertiesToLoadAlwaysProbe::setPropertiesToLoadAlways(ArgusTimeoutFromConstantProbe::class);
        self::assertNotSame([], ArgusPropertiesToLoadAlwaysProbe::getPropertiesToLoadAlways());

        (new ArgusWorkerStateResetter())->resetArgusStateForNewJob();

        self::assertNull(ArgusApiOperations::$transportRecording);
        self::assertFalse(ArgusApiOperations::$journalRequests);
        self::assertSame(0, ArgusApiOperations::getCallJournal()->count());
        self::assertFalse(ArgusLoad::$logArgusCalls);
        self::assertSame([], ArgusPropertiesToLoadAlwaysProbe::getPropertiesToLoadAlways());
    }

    public function testTheResetRunsBeforeEveryMessageAWorkerReceived(): void
    {
        self::assertSame(
            [WorkerMessageReceivedEvent::class => 'resetArgusStateForNewJob'],
            ArgusWorkerStateResetter::getSubscribedEvents()
        );
    }
}
