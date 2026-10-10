<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\MediaTypeConverter;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Modelling\WithAggregateVersioning;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Messaging\Conversion\Converter;
use Ecotone\Messaging\Handler\Type;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\AssignedPersonWasChanged;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;

require $argv[1];

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

    #[EventSourcingHandler]
    public function applyRegistered(TicketWasRegistered $event): void
    {
        $this->ticketId = $event->getTicketId();
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

EcotoneLite::bootstrapFlowTestingWithEventStore(
    classesToResolve: [TicketFoldedAcrossDeployments::class, TicketEventConverter::class, $snapshotConverter::class],
    containerOrAvailableServices: [
        new TicketEventConverter(),
        $snapshotConverter,
        DbalConnectionFactory::class => EventSourcingMessagingTestCase::getConnectionFactory(),
    ],
    configuration: ServiceConfiguration::createWithDefaults()
        ->withEnvironment('prod')
        ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
        ->withExtensionObjects([
            DbalConfiguration::createWithDefaults()->withDocumentStore(),
            EventSourcingConfiguration::createWithDefaults()->withSnapshotsFor(TicketFoldedAcrossDeployments::class, 1),
        ]),
    pathToRootCatalog: __DIR__ . '/../../../',
    runForProductionEventStore: true,
)
    ->setupDatabaseTables()
    ->sendCommandWithRouting('ticketFoldedAcrossDeployments.register', '1')
    ->sendCommandWithRouting('ticketFoldedAcrossDeployments.assign', 'Lucas', metadata: ['aggregate.id' => '1']);
