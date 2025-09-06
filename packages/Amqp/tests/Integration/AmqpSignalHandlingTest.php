<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Amqp\AmqpQueue;
use Ecotone\Amqp\Configuration\AmqpMessageConsumerConfiguration;
use Ecotone\Amqp\Publisher\AmqpMessagePublisherConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\MessageConsumer;
use Ecotone\Messaging\Attribute\Parameter\Payload;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Enqueue\AmqpLib\AmqpConnectionFactory;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\Amqp\AmqpMessagingTestCase;

/**
 * @licence Apache-2.0
 * @internal
 */
final class AmqpSignalHandlingTest extends AmqpMessagingTestCase
{
    public function test_consumer_stops_after_current_message_when_signal_sent_during_processing(): void
    {
        $endpointId = 'signal_test_endpoint';
        $queueName = Uuid::uuid4()->toString();
        $signalHandler = new AmqpSignalSendingMessageHandler();

        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            [AmqpSignalSendingMessageHandler::class],
            [
                $signalHandler,
                AmqpConnectionFactory::class => $this->getCachedConnectionFactory(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    AmqpMessageConsumerConfiguration::create($endpointId, $queueName),
                    AmqpQueue::createWith($queueName),
                    AmqpMessagePublisherConfiguration::create()
                        ->withDefaultRoutingKey($queueName),
                ])
        );

        $messagePublisher = $ecotoneLite->getMessagePublisher();
        $messagePublisher->send('message-1');
        $messagePublisher->send('message-2');
        $messagePublisher->send('message-3');

        $ecotoneLite->run(
            $endpointId,
            ExecutionPollingMetadata::createWithTestingSetup(
                amountOfMessagesToHandle: 10,
                maxExecutionTimeInMilliseconds: 30000
            )
        );

        $processedMessages = $ecotoneLite->getQueryBus()->sendWithRouting('signal_handler.getProcessedMessages');
        $this->assertEquals(['message-1'], $processedMessages);
    }

    public function test_asynchronous_command_handler_stops_after_current_command_when_signal_sent(): void
    {
        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            [AmqpAsyncCommandHandler::class],
            [
                new AmqpAsyncCommandHandler(),
                AmqpConnectionFactory::class => $this->getCachedConnectionFactory(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    AmqpBackedMessageChannelBuilder::create(
                        'async_commands_unique',
                        queueName: Uuid::uuid4()->toString()
                    ),
                ])
        );

        $commandBus = $ecotoneLite->getCommandBus();
        $commandBus->send(new ProcessCommand('command-1'));
        $commandBus->send(new ProcessCommand('command-2'));
        $commandBus->send(new ProcessCommand('command-3'));

        $ecotoneLite->run(
            'async_commands_unique',
            ExecutionPollingMetadata::createWithTestingSetup(
                amountOfMessagesToHandle: 10,
                maxExecutionTimeInMilliseconds: 30000
            )
        );

        $processedCommands = $ecotoneLite->getQueryBus()->sendWithRouting('async_command_handler.getProcessedCommands');
        $this->assertEquals(['command-1'], $processedCommands);
    }

    public function test_asynchronous_command_handler_stops_after_current_command_when_signal_sent_with_defaults(): void
    {
        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            [AmqpAsyncCommandHandler::class],
            [
                new AmqpAsyncCommandHandler(),
                AmqpConnectionFactory::class => $this->getCachedConnectionFactory(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    AmqpBackedMessageChannelBuilder::create(
                        'async_commands_unique',
                        queueName: Uuid::uuid4()->toString()
                    ),
                ])
        );

        $commandBus = $ecotoneLite->getCommandBus();
        $commandBus->send(new ProcessCommand('command-1'));
        $commandBus->send(new ProcessCommand('command-2'));
        $commandBus->send(new ProcessCommand('command-3'));

        $ecotoneLite->run(
            'async_commands_unique',
            ExecutionPollingMetadata::createWithDefaults()
                ->withExecutionTimeLimitInMilliseconds(0),
        );

        $processedCommands = $ecotoneLite->getQueryBus()->sendWithRouting('async_command_handler.getProcessedCommands');
        $this->assertEquals(['command-1'], $processedCommands);
    }

    //    public function test_asynchronous_command_handler_stops_even_if_there_was_no_message(): void
    //    {
    //        $channelName = 'async_commands_timeout_' . Uuid::uuid4()->toString();
    //
    //        $ecotoneLite = EcotoneLite::bootstrapForTesting(
    //            [AmqpAsyncCommandHandler::class],
    //            [
    //                new AmqpAsyncCommandHandler(),
    //                AmqpConnectionFactory::class => $this->getCachedConnectionFactory(),
    //            ],
    //            ServiceConfiguration::createWithDefaults()
    //                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
    //                ->withExtensionObjects([
    //                    AmqpBackedMessageChannelBuilder::create($channelName, queueName: Uuid::uuid4()->toString()),
    //                ])
    //        );
    //
    //        $ecotoneLite->run(
    //            $channelName,
    //            ExecutionPollingMetadata::createWithDefaults(),
    //        );
    //
    //        $this->assertTrue(true);
    //    }
}

class ProcessCommand
{
    public function __construct(public readonly string $data)
    {
    }
}

class AmqpSignalSendingMessageHandler
{
    private array $processedMessages = [];

    #[MessageConsumer('signal_test_endpoint')]
    public function handle(#[Payload] string $message): void
    {
        $this->processedMessages[] = $message;

        if (count($this->processedMessages) === 1) {
            usleep(100000);
            posix_kill(posix_getpid(), SIGTERM);
            usleep(100000);
        }
    }

    #[QueryHandler('signal_handler.getProcessedMessages')]
    public function getProcessedMessages(): array
    {
        return $this->processedMessages;
    }
}

class AmqpAsyncCommandHandler
{
    private array $processedCommands = [];

    #[Asynchronous('async_commands_unique')]
    #[CommandHandler(endpointId: 'async_command_handler')]
    public function handle(ProcessCommand $command): void
    {
        $this->processedCommands[] = $command->data;

        if (count($this->processedCommands) === 1) {
            usleep(100000);
            posix_kill(posix_getpid(), SIGTERM);
            usleep(100000);
        }
    }

    #[QueryHandler('async_command_handler.getProcessedCommands')]
    public function getProcessedCommands(): array
    {
        return $this->processedCommands;
    }

    public function reset(): void
    {
        $this->processedCommands = [];
    }
}
