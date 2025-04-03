<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\ProjectionWithMetadataMatcher;

use Ecotone\EventSourcing\ProjectionRunningConfiguration;
use Ecotone\EventSourcing\Prooph\Metadata\FieldType;
use Ecotone\EventSourcing\Prooph\Metadata\MetadataMatcher;
use Ecotone\EventSourcing\Prooph\Metadata\Operator;
use Ecotone\Messaging\Attribute\ServiceContext;
use Test\Ecotone\EventSourcing\Fixture\TicketWithPollingProjection\InProgressTicketList;

final class PollingProjectionWithMetadataMatcherConfig
{
    #[ServiceContext]
    public function enablePollingProjection(): ProjectionRunningConfiguration
    {
        $metadataMatcher = (new MetadataMatcher())
            ->withMetadataMatch('test', Operator::EQUALS, 'false', FieldType::METADATA)
        ;

        return ProjectionRunningConfiguration::createPolling(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION)
            ->withTestingSetup()
            ->withOption(ProjectionRunningConfiguration::OPTION_GAP_DETECTION, null)
            ->withOption(ProjectionRunningConfiguration::OPTION_METADATA_MATCHER, $metadataMatcher)
        ;
    }
}
