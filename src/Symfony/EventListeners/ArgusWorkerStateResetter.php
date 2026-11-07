<?php

declare(strict_types=1);

namespace DDD\Symfony\EventListeners;

use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusLoadTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperations;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Puts the Argus process state back before a Messenger worker handles the next message, so a job never inherits the
 * previous job's Argus state:
 *
 * - the executor statics ({@see ArgusApiOperations::resetForNewJob()}): executed-calls log, request uid, transport
 *   recording, call journal (the sent-call counts are kept);
 * - {@see ArgusLoad::$logArgusCalls};
 * - the per-class "load these children always" selection of every loaded class using {@see ArgusLoadTrait} (a trait
 *   static: every using class holds its own copy, so each one is reset).
 *
 * Runs on {@see WorkerMessageReceivedEvent} — before every message a worker consumed, not for a synchronous dispatch —
 * and, once instantiated, as a `kernel.reset` service through Symfony's `services_resetter` (worker runtimes, after a
 * handled message). `ArgusLoad::$deactivateArgusCache` is left to the application, which sets it from its own cache
 * state. Registered by the module's service registration (autoconfigured); nothing to wire in an application.
 */
class ArgusWorkerStateResetter implements EventSubscriberInterface, ResetInterface
{
    public static function getSubscribedEvents(): array
    {
        return [WorkerMessageReceivedEvent::class => 'resetArgusStateForNewJob'];
    }

    public function resetArgusStateForNewJob(): void
    {
        ArgusApiOperations::resetForNewJob();
        ArgusLoad::$logArgusCalls = false;
        $this->resetPropertiesToLoadAlwaysOfLoadedArgusClasses();
    }

    public function reset(): void
    {
        $this->resetArgusStateForNewJob();
    }

    /** @return int The number of classes whose selection was reset */
    protected function resetPropertiesToLoadAlwaysOfLoadedArgusClasses(): int
    {
        $resetClassesCount = 0;
        foreach (get_declared_classes() as $declaredClassName) {
            if (!method_exists($declaredClassName, 'resetPropertiesToLoadAlwaysForNewJob')
                || !self::isUsingArgusLoadTrait($declaredClassName)) {
                continue;
            }
            $declaredClassName::resetPropertiesToLoadAlwaysForNewJob();
            $resetClassesCount++;
        }
        return $resetClassesCount;
    }

    /** Whether a class itself (not only a parent) uses ArgusLoadTrait, directly or through another trait */
    protected static function isUsingArgusLoadTrait(string $className): bool
    {
        $traitNamesToVisit = class_uses($className) ?: [];
        $visitedTraitNames = [];
        while ($traitNamesToVisit !== []) {
            $traitName = array_pop($traitNamesToVisit);
            if (isset($visitedTraitNames[$traitName])) {
                continue;
            }
            if ($traitName === ArgusLoadTrait::class) {
                return true;
            }
            $visitedTraitNames[$traitName] = true;
            foreach (class_uses($traitName) ?: [] as $usedTraitName) {
                $traitNamesToVisit[] = $usedTraitName;
            }
        }
        return false;
    }
}
