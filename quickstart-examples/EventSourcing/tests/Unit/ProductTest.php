<?php

namespace Tests\App\EventSourcing\Unit;

use Ecotone\Lite\EcotoneLiteConfiguration;
use Ecotone\Lite\InMemoryPSRContainer;
use Ecotone\Messaging\Config\ServiceConfiguration;
use PHPUnit\Framework\TestCase;

final class ProductTest extends TestCase
{
    public function test_returning_given_events()
    {
//        tutaj jakaś mała wersja aby stworzyć z InMemoryAnnotationReader i przekazac mu nazwę aggregatu
        EcotoneLiteConfiguration::createWithConfiguration(
            __DIR__ . "/../../",
            InMemoryPSRContainer::createEmpty(),
            ServiceConfiguration::createWithDefaults()
                ->withNamespaces(["App\EventSourcing\Product"]),
            [],
            false
        );
    }
}