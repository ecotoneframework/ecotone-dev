<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\ProjectionWithMetadataMatcher;

use Ecotone\EventSourcing\ProjectionRunningConfiguration;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Prooph\EventStore\Metadata\FieldType;
use Prooph\EventStore\Metadata\MetadataMatcher;
use Prooph\EventStore\Metadata\Operator;
use Prooph\EventStore\Pdo\Projection\GapDetection;
use Prooph\EventStore\Pdo\Projection\PdoEventStoreReadModelProjector;
use Test\Ecotone\EventSourcing\Fixture\TicketWithPollingProjection\InProgressTicketList;

final class PollingProjectionWithMetadataMatcherConfig
{
    #[ServiceContext]
    public function enablePollingProjection(): ProjectionRunningConfiguration
    {
        $metadataMatcher = (new MetadataMatcher())
            ->withMetadataMatch('test', Operator::EQUALS(), 'false', FieldType::METADATA())
        ;

        return ProjectionRunningConfiguration::createPolling(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION)
            ->withTestingSetup()
            ->withOption(ProjectionRunningConfiguration::OPTION_GAP_DETECTION, null)
            ->withOption(ProjectionRunningConfiguration::OPTION_METADATA_MATCHER, $metadataMatcher)
        ;
    }
}
