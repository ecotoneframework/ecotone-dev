<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Integration;

use Ecotone\Kafka\Channel\KafkaMessageChannelBuilder;
use Ecotone\Kafka\Configuration\KafkaBrokerConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\ServiceActivator;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
use Ecotone\Messaging\Message;
use Ecotone\Test\LicenceTesting;
use Exception;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Nonstandard\Uuid;
use Test\Ecotone\Kafka\ConnectionTestCase;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class FinalFailureStrategyTest extends TestCase
{
    public function test_reject_failure_strategy_rejects_message_on_exception()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [FailingService::class],
            [new FailingService(), KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::KAFKA_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    KafkaMessageChannelBuilder::create(channelName: 'async', topicName: Uuid::uuid4()->toString())
                        ->withFinalFailureStrategy(FinalFailureStrategy::IGNORE)
                        ->withReceiveTimeout(3000),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotoneTestSupport->sendDirectToChannel('executionChannel', 'some');
        $ecotoneTestSupport->run('async', ExecutionPollingMetadata::createWithTestingSetup(failAtError: false));

        $messageChannel = $ecotoneTestSupport->getMessageChannel('async');
        $this->assertNull($messageChannel->receive());
    }

    public function test_resend_failure_strategy_rejects_message_on_exception()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [FailingService::class],
            [new FailingService(), KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::KAFKA_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    KafkaMessageChannelBuilder::create(channelName: 'async', topicName: Uuid::uuid4()->toString())
                        ->withFinalFailureStrategy(FinalFailureStrategy::RESEND)
                        ->withReceiveTimeout(3000),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotoneTestSupport->sendDirectToChannel('executionChannel', 'some');
        $ecotoneTestSupport->run('async', ExecutionPollingMetadata::createWithTestingSetup(failAtError: false));

        $messageChannel = $ecotoneTestSupport->getMessageChannel('async');
        $this->assertNotNull($messageChannel->receive());
    }
}


class FailingService
{
    private Message $message;

    #[Asynchronous('async')]
    #[ServiceActivator('executionChannel')]
    public function handle(Message $message): void
    {
        $this->message = $message;

        throw new Exception('Service failed');
    }

    public function getMessage(): Message
    {
        return $this->message;
    }
}
