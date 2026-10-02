<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration\Tagging;

use Closure;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\Fetch;
use Ecotone\Api\EventSourcing\DecisionModelConcurrencyException;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\EventSourcing\Event;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\EventStore;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * licence Enterprise
 * @internal
 */
final class DecisionModelOnInMemoryEventStoreTest extends TestCase
{
    public function test_a_decision_on_the_default_in_memory_event_store_sees_the_events_carrying_its_tag(): void
    {
        $ecotone = $this->bootstrap($this->registration());
        $ecotone->sendCommand(new ClaimUsernameOnInMemoryEventStore('ada'));

        try {
            $ecotone->sendCommand(new ClaimUsernameOnInMemoryEventStore('ada'));
            self::fail('Expected the second claim of the same username to be refused');
        } catch (UsernameTakenOnInMemoryEventStore) {
        }

        self::assertSame(['ada'], $this->claimedUsernamesOf($ecotone, 'ada'));
    }

    public function test_a_decision_on_the_default_in_memory_event_store_is_rejected_once_an_event_carrying_its_tag_is_appended(): void
    {
        $registration = $this->registration();
        $ecotone = $this->bootstrap($registration);
        $registration->beforeDeciding = static fn () => $ecotone->getGateway(EventStore::class)->appendTo('usernames', [new UsernameClaimedOnInMemoryEventStore('ada')]);

        try {
            $ecotone->sendCommand(new ClaimUsernameOnInMemoryEventStore('ada'));
            self::fail('Expected the decision taken on a stale view of the tag to be rejected');
        } catch (DecisionModelConcurrencyException $exception) {
            self::assertStringContainsString('on tag username:ada', $exception->getMessage());
        }

        self::assertSame(['ada'], $this->claimedUsernamesOf($ecotone, 'ada'));
    }

    public function test_an_append_whose_event_cannot_be_recorded_on_the_default_in_memory_event_store_leaves_the_tag_unmoved(): void
    {
        $ecotone = $this->bootstrap($this->registration());
        $eventStore = $ecotone->getGateway(EventStore::class);
        $eventStore->appendTo('usernames', [new UsernameClaimedOnInMemoryEventStore('ada')]);
        $loaded = $eventStore->loadByCriteria(EventCriteria::tag('username', 'ada'));

        try {
            $eventStore->appendTo('usernames', [new UsernameReleasedOnInMemoryEventStore('ada')], $loaded->appendCondition);
            self::fail('Expected an event its converter refuses to be refused');
        } catch (UnrecordableEventOnInMemoryEventStore) {
        }

        $eventStore->appendTo('usernames', [new UsernameClaimedOnInMemoryEventStore('ada')], $loaded->appendCondition);

        self::assertSame(['ada', 'ada'], $this->claimedUsernamesOf($ecotone, 'ada'));
    }

    private function registration(): object
    {
        return new class () {
            public ?Closure $beforeDeciding = null;

            #[CommandHandler]
            public function claim(ClaimUsernameOnInMemoryEventStore $command, #[Fetch("{'username': payload.username}")] UsernameAvailabilityOnInMemoryEventStore $availability): array
            {
                if ($availability->taken) {
                    throw new UsernameTakenOnInMemoryEventStore();
                }

                if ($this->beforeDeciding !== null) {
                    ($this->beforeDeciding)();
                    $this->beforeDeciding = null;
                }

                return [new UsernameClaimedOnInMemoryEventStore($command->username)];
            }
        };
    }

    private function bootstrap(object $registration): FlowTestSupport
    {
        $converter = new class () {
            #[Converter]
            public function fromClaimed(UsernameClaimedOnInMemoryEventStore $event): array
            {
                return ['username' => $event->username];
            }

            #[Converter]
            public function toClaimed(array $event): UsernameClaimedOnInMemoryEventStore
            {
                return new UsernameClaimedOnInMemoryEventStore($event['username']);
            }

            #[Converter]
            public function fromReleased(UsernameReleasedOnInMemoryEventStore $event): array
            {
                throw new UnrecordableEventOnInMemoryEventStore();
            }
        };

        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [UsernameAvailabilityOnInMemoryEventStore::class, UsernameClaimedOnInMemoryEventStore::class, UsernameReleasedOnInMemoryEventStore::class, $registration::class, $converter::class],
            containerOrAvailableServices: [$registration, $converter],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults()]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    /**
     * @return string[]
     */
    private function claimedUsernamesOf(FlowTestSupport $ecotone, string $username): array
    {
        return array_map(
            static fn (Event $event): string => $event->getPayload()->username,
            $ecotone->getGateway(EventStore::class)->loadByCriteria(EventCriteria::tag('username', $username))->events,
        );
    }
}

/**
 * @internal
 */
final readonly class ClaimUsernameOnInMemoryEventStore
{
    public function __construct(public string $username)
    {
    }
}

/**
 * @internal
 */
final readonly class UsernameClaimedOnInMemoryEventStore
{
    public function __construct(#[EventTag('username')] public string $username)
    {
    }
}

/**
 * @internal
 */
final readonly class UsernameReleasedOnInMemoryEventStore
{
    public function __construct(#[EventTag('username')] public string $username)
    {
    }
}

/**
 * @internal
 */
final class UsernameTakenOnInMemoryEventStore extends RuntimeException
{
}

/**
 * @internal
 */
final class UnrecordableEventOnInMemoryEventStore extends RuntimeException
{
}

/**
 * @internal
 */
#[DecisionModel(tags: ['username'])]
final class UsernameAvailabilityOnInMemoryEventStore
{
    public bool $taken = false;

    #[EventSourcingHandler]
    public function claimed(UsernameClaimedOnInMemoryEventStore $event): void
    {
        $this->taken = true;
    }
}
