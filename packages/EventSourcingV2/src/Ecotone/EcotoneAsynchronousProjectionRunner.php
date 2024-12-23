<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcingV2\Ecotone;

use Ecotone\EventSourcingV2\EventStore\Subscription\PersistentSubscriptions;
use Ecotone\Messaging\Attribute\ServiceActivator;
use Psr\Container\ContainerInterface;

class EcotoneAsynchronousProjectionRunner
{
    public const PROJECTION_RUNNER_CHANNEL = "ecotone.event-sourcing.projections-runner";

    public function __construct(
        private PersistentSubscriptions $eventStore,
        private ContainerInterface $ecotoneProjectors,
    ) {
    }

    public function run(EcotoneAsynchronousProjectionRunnerCommand $command): void
    {
        if (! $this->ecotoneProjectors->has($command->subscription)) {
            return;
        }
        /** @var EcotoneProjector $projector */
        $projector = $this->ecotoneProjectors->get($command->subscription);

        while(true) {
            $eventPage = $this->eventStore->readFromSubscription($command->subscription);
            foreach ($eventPage->events as $event) {
                $projector->project($event);
            }
            $this->eventStore->ack($eventPage);

            if ($command->until !== null && $eventPage->endPosition->isBefore($command->until)) {
                \usleep(10000);
            } else {
                break;
            }
        }
    }
}