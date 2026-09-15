<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp;

use Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Api\Asynchronous;
use Ecotone\Api\ExecutionPollingMetadata;
use Ecotone\Api\ServiceActivator;
use Ecotone\Api\ServiceConfiguration;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
use Ecotone\Messaging\Message;
use Exception;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class FinalFailureStrategyTest extends AmqpMessagingTestCase
{
    public function test_reject_failure_strategy_rejects_message_on_exception()
    {
        $ecotoneTestSupport = $this->bootstrapFlowTesting(
            [FailingService::class],
            array_merge(
                [new FailingService()],
                $this->getConnectionFactoryReferences()
            ),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::AMQP_PACKAGE, ])
                ->withExtensionObjects([
                    \Ecotone\Api\InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
                    AmqpBackedMessageChannelBuilder::create(channelName: 'async')
                        ->withFinalFailureStrategy(FinalFailureStrategy::IGNORE)
                        ->withReceiveTimeout(100),
                ])
        );

        $ecotoneTestSupport->sendDirectToChannel('executionChannel', 'some');
        $ecotoneTestSupport->run('async', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, stopOnError: false));

        $messageChannel = $ecotoneTestSupport->getMessageChannel('async');
        $this->assertNull($messageChannel->receive());
    }

    public function test_resend_failure_strategy_rejects_message_on_exception()
    {
        $ecotoneTestSupport = $this->bootstrapFlowTesting(
            [FailingService::class],
            array_merge(
                [new FailingService()],
                $this->getConnectionFactoryReferences()
            ),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::AMQP_PACKAGE, ])
                ->withExtensionObjects([
                    \Ecotone\Api\InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
                    AmqpBackedMessageChannelBuilder::create(channelName: 'async')
                        ->withFinalFailureStrategy(FinalFailureStrategy::RESEND)
                        ->withReceiveTimeout(100),
                ])
        );

        $ecotoneTestSupport->sendDirectToChannel('executionChannel', 'some_1');
        $ecotoneTestSupport->sendDirectToChannel('executionChannel', 'some_2');
        $ecotoneTestSupport->run('async', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, stopOnError: false));

        $messageChannel = $ecotoneTestSupport->getMessageChannel('async');
        $this->assertSame('some_2', $messageChannel->receive()->getPayload());
    }

    public function test_release_failure_strategy_releases_message_on_exception()
    {
        $ecotoneTestSupport = $this->bootstrapFlowTesting(
            [FailingService::class],
            array_merge(
                [new FailingService()],
                $this->getConnectionFactoryReferences()
            ),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::AMQP_PACKAGE, ])
                ->withExtensionObjects([
                    \Ecotone\Api\InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
                    AmqpBackedMessageChannelBuilder::create(channelName: 'async')
                        ->withFinalFailureStrategy(FinalFailureStrategy::RELEASE)
                        ->withReceiveTimeout(100),
                ])
        );

        $ecotoneTestSupport->sendDirectToChannel('executionChannel', 'some_1');
        $ecotoneTestSupport->sendDirectToChannel('executionChannel', 'some_2');
        $ecotoneTestSupport->run('async', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, stopOnError: false));

        $messageChannel = $ecotoneTestSupport->getMessageChannel('async');
        $this->assertSame('some_1', $messageChannel->receive()->getPayload());
    }
}


class FailingService
{
    private Message $message;

    #[Asynchronous('async')]
    #[ServiceActivator('executionChannel', endpointId: 'failingServiceHandle')]
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
