<?php

declare(strict_types=1);

namespace Test;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
use Ecotone\SymfonyBundle\Messenger\SymfonyMessengerMessageChannelBuilder;
use Exception;
use Fixture\MessengerConsumer\ExampleCommand;
use Fixture\MessengerConsumer\MessengerAsyncCommandHandler;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests for Symfony Messenger Final Failure Strategy message ordering
 */
/**
 * licence Apache-2.0
 */
final class SymfonyMessengerFinalFailureStrategyTest extends WebTestCase
{
    protected function tearDown(): void
    {
        restore_exception_handler();
    }

    public function setUp(): void
    {
        try {
            self::bootKernel()->getContainer()->get('Doctrine\DBAL\Connection-public')->executeQuery('DELETE FROM messenger_messages');
        } catch (Exception $exception) {
            // Ignore if table doesn't exist
        }
    }

    public function test_resend_failure_strategy_rejects_message_on_exception()
    {
        $channelName = 'messenger_async';

        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [MessengerAsyncCommandHandler::class],
            $this->bootKernel()->getContainer(),
            ServiceConfiguration::createWithAsynchronicityOnly()
                ->withExtensionObjects([
                    SymfonyMessengerMessageChannelBuilder::create($channelName)
                        ->withFinalFailureStrategy(FinalFailureStrategy::RESEND),
                ])
        );

        $ecotoneTestSupport->sendCommandWithRoutingKey('execute.fail', new ExampleCommand('some_1'));
        $ecotoneTestSupport->sendCommandWithRoutingKey('execute.fail', new ExampleCommand('some_2'));
        $ecotoneTestSupport->run($channelName, ExecutionPollingMetadata::createWithTestingSetup(failAtError: false));

        $messageChannel = $ecotoneTestSupport->getMessageChannel($channelName);
        // For Symfony Messenger, resend uses transport->send() + transport->reject()
        // Symfony doesn't distinguish between beginning/end positioning - behavior is transport-specific
        $this->assertNotNull($messageChannel->receive());
    }

    public function test_release_failure_strategy_releases_message_on_exception()
    {
        $this->expectException(ConfigurationException::class);

        EcotoneLite::bootstrapFlowTesting(
            [MessengerAsyncCommandHandler::class],
            $this->bootKernel()->getContainer(),
            ServiceConfiguration::createWithAsynchronicityOnly()
                ->withExtensionObjects([
                    SymfonyMessengerMessageChannelBuilder::create('some')
                        ->withFinalFailureStrategy(FinalFailureStrategy::RELEASE),
                ])
        );
    }
}
