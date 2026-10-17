<?php

declare(strict_types=1);

namespace Test\Ecotone\Sqs\Integration;

use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Sqs\SqsBackedMessageChannelBuilder;
use Ecotone\Api\Sqs\SqsConnectionFactory;
use Ecotone\Api\Sqs\SqsConnectionReference;
use Ecotone\Test\ChannelTestSupport;
use Ecotone\Test\StaticMessageBuilder;
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
            extensionObjects: [SqsBackedMessageChannelBuilder::create($queue_name)],
            enabledModules: [ModulePackageList::SQS_PACKAGE],
        );

        ChannelTestSupport::of($ecotone)->getMessageChannel($queue_name)->send(StaticMessageBuilder::withPayload('milk')->build());

        self::assertSame('milk', ChannelTestSupport::of($ecotone)->getMessageChannel($queue_name)->receive()->getPayload());
    }
}
