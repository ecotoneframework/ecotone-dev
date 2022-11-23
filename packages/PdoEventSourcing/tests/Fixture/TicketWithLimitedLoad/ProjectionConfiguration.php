<?php

namespace Test\Ecotone\EventSourcing\Fixture\TicketWithLimitedLoad;

use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\EventSourcing\ProjectionRunningConfiguration;
use Ecotone\EventSourcing\Prooph\ProophProjectionRunningOption;
use Ecotone\Messaging\Attribute\ServiceContext;
use Test\Ecotone\EventSourcing\Fixture\TicketWithSynchronousEventDrivenProjection\InProgressTicketList;

class ProjectionConfiguration
{
    #[ServiceContext]
    public function configureProjection()
    {
        return [
            EventSourcingConfiguration::createWithDefaults(),
            ProjectionRunningConfiguration::createEventDriven(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION)
                ->withTestingSetup()
                ->withOption(ProophProjectionRunningOption::OPTION_LOAD_COUNT, 2),
        ];
    }
}
