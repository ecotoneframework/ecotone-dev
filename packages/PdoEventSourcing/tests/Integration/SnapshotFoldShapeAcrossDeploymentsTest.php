<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Api\Attribute\Apply;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\MediaTypeConverter;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Modelling\WithAggregateVersioning;
use Ecotone\Messaging\Conversion\Converter;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Test\StubLogger;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\AssignedPersonWasChanged;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;

/**
 * licence Apache-2.0
 * @internal
 */
final class SnapshotFoldShapeAcrossDeploymentsTest extends EventSourcingMessagingTestCase
{
    public function test_a_snapshot_taken_while_the_aggregate_folded_other_events_is_rebuilt_from_its_events(): void
    {
        $this->deployVersionThatDoesNotFoldAssignments();

        $snapshotConverter = new #[MediaTypeConverter] class () implements Converter {
            public function convert($source, Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType)
            {
                if ($targetMediaType->isCompatibleWith(MediaType::createApplicationJson())) {
                    return json_encode($source->toArray());
                }

                return TicketFoldedAcrossDeployments::fromArray(json_decode($source, true));
            }

            public function matches(Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType): bool
            {
                return $sourceType->getTypeHint() === TicketFoldedAcrossDeployments::class || $targetType->getTypeHint() === TicketFoldedAcrossDeployments::class;
            }
        };

        $logger = StubLogger::create();
        $ecotone = $this->bootstrapFlowTesting(
            classesToResolve: [TicketFoldedAcrossDeployments::class, TicketEventConverter::class, $snapshotConverter::class],
            containerOrAvailableServices: [
                new TicketEventConverter(),
                $snapshotConverter,
                'logger' => $logger,
                DbalConnectionFactory::class => $this->getConnectionFactory(),
            ],
            extensionObjects: [
                DbalConfiguration::createWithDefaults()->withDocumentStore(),
                EventSourcingConfiguration::createWithDefaults()->withSnapshotsFor(TicketFoldedAcrossDeployments::class, 1),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE],
            pathToRootCatalog: __DIR__ . '/../../',
            environment: 'prod',
        );

        $this->assertSame('Lucas', $ecotone->sendQueryWithRouting('ticketFoldedAcrossDeployments.assignedPerson', metadata: ['aggregate.id' => '1']));
        $this->assertCount(1, $logger->getError());
    }

    private function deployVersionThatDoesNotFoldAssignments(): void
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/SnapshotFoldShape/earlier-deployment.php', PHPUNIT_COMPOSER_INSTALL],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

        $this->assertSame(0, proc_close($process), $output);
    }
}

#[EventSourcingAggregate]
final class TicketFoldedAcrossDeployments
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $ticketId;

    private string $assignedPerson;

    #[CommandHandler('ticketFoldedAcrossDeployments.register')]
    public static function register(string $ticketId): array
    {
        return [new TicketWasRegistered($ticketId, 'Johnny', 'alert')];
    }

    #[CommandHandler('ticketFoldedAcrossDeployments.assign')]
    public function assign(string $assignedPerson): array
    {
        return [new AssignedPersonWasChanged($this->ticketId, $assignedPerson)];
    }

    #[QueryHandler('ticketFoldedAcrossDeployments.assignedPerson')]
    public function assignedPerson(): string
    {
        return $this->assignedPerson;
    }

    #[Apply]
    public function applyRegistered(TicketWasRegistered $event): void
    {
        $this->ticketId = $event->getTicketId();
        $this->assignedPerson = $event->getAssignedPerson();
    }

    #[Apply]
    public function applyAssigned(AssignedPersonWasChanged $event): void
    {
        $this->assignedPerson = $event->getAssignedPerson();
    }

    public function toArray(): array
    {
        return ['ticketId' => $this->ticketId, 'assignedPerson' => $this->assignedPerson, 'version' => $this->version];
    }

    public static function fromArray(array $data): self
    {
        $ticket = new self();
        $ticket->ticketId = $data['ticketId'];
        $ticket->assignedPerson = $data['assignedPerson'];
        $ticket->version = $data['version'];

        return $ticket;
    }
}
