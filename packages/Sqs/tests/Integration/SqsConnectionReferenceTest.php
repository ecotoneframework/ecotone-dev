<?php

declare(strict_types=1);

namespace Test\Ecotone\Sqs\Integration;

use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Sqs\SqsBackedMessageChannelBuilder;
use Ecotone\Api\Sqs\SqsConnectionReference;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Sqs\Connection\SqsConnectionFactory;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\Sqs\ConnectionTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class SqsConnectionReferenceTest extends ConnectionTestCase
{
    public function test_channel_publishes_and_consumes_using_only_ecotone_owned_sqs_connection_classes(): void
    {
        $queue_name = Uuid::v7()->toRfc4122();
        $connection_factory = new SqsConnectionFactory(
            getenv('SQS_DSN') ?: 'sqs:?key=key&secret=secret&region=us-east-1&endpoint=http://localhost:4566&version=latest'
        );

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [],
            [
                SqsConnectionReference::DEFAULT => $connection_factory,
            ],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::SQS_PACKAGE])
                ->withExtensionObjects([
                    SqsBackedMessageChannelBuilder::create($queue_name),
                ])
        );

        $ecotone->getMessageChannel($queue_name)->send(MessageBuilder::withPayload('milk')->build());

        self::assertSame('milk', $ecotone->getMessageChannel($queue_name)->receive()->getPayload());
    }
}
