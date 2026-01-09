<?php
/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Monorepo\ExampleAppEventSourcing\EcotoneProjection;

use Ecotone\EventSourcing\Projecting\StreamSource\EventStoreGlobalStreamSourceBuilder;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Projecting\Config\ProjectionComponentBuilder;
use Ecotone\Projecting\StreamFilter;
use Monorepo\ExampleAppEventSourcing\Common\Product;

class EcotoneConfiguration
{
    #[ServiceContext]
    public function enableStreamSourceFromEventStore(): ProjectionComponentBuilder
    {
        return new EventStoreGlobalStreamSourceBuilder(new StreamFilter(Product::class), [PriceChangeOverTimeProjectionWithEcotoneProjection::NAME]);
    }
}