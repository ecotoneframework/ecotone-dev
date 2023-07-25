<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Unit;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\Modelling\Fixture\AggregateWithGateway\Bucket;
use Test\Ecotone\Modelling\Fixture\AggregateWithGateway\BucketGateway;

final class AggregateWithGatewayTest extends TestCase
{
    public function test_aggregate_with_gateway(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            configuration: ServiceConfiguration::createWithDefaults()
                ->withNamespaces([
                    'Test\Ecotone\Modelling\Fixture\AggregateWithGateway'
                ])
                ->withSkippedModulePackageNames(ModulePackageList::allPackages())
        );

        $bucketId = Uuid::uuid4();

        $ecotone->sendCommandWithRoutingKey(Bucket::CREATE, $bucketId);

        $gateway = $ecotone->getGateway(BucketGateway::class);

        $uuid = Uuid::uuid4();
        $gateway->add(
            $bucketId,
            [
                $uuid->toString() => 'foo',
                Uuid::uuid4()->toString() => 'bar'
            ]
        );

        self::assertEquals('foo', $gateway->get($bucketId, $uuid));
    }
}
