<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\ProjectionWithMetadataMatcher;

use Ecotone\EventSourcing\ProjectionRunningConfiguration;
use Ecotone\Messaging\Attribute\ServiceContext;
use Prooph\EventStore\Metadata\FieldType;
use Prooph\EventStore\Metadata\MetadataMatcher;
use Prooph\EventStore\Metadata\Operator;
use Prooph\EventStore\Pdo\Projection\GapDetection;
use Test\Ecotone\EventSourcing\Fixture\TicketWithSynchronousEventDrivenProjection\InProgressTicketList;

final class EventDrivenProjectionWithMetadataMatcherConfig
{
    #[ServiceContext]
    public function enableProjection(): ProjectionRunningConfiguration
    {
        $metadataMatcher = (new MetadataMatcher())
            ->withMetadataMatch('test', Operator::EQUALS(), 'false', FieldType::METADATA())
        ;

        return ProjectionRunningConfiguration::createEventDriven(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION)
            ->withTestingSetup()
            ->withOption(ProjectionRunningConfiguration::OPTION_GAP_DETECTION, null)
            ->withOption(ProjectionRunningConfiguration::OPTION_METADATA_MATCHER, $metadataMatcher)
        ;
    }
}
