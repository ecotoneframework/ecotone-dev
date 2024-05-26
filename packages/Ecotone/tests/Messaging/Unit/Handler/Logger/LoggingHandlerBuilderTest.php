<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Handler\Logger;

use Ecotone\Messaging\Channel\QueueChannel;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\Logger\LoggingHandlerBuilder;
use Ecotone\Messaging\Handler\Logger\LoggingInterceptor;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\MessageConverterBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\PayloadBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodArgumentsFactory;
use Ecotone\Messaging\Handler\ServiceActivator\ServiceActivatorBuilder;
use Ecotone\Messaging\Support\MessageBuilder;

use Ecotone\Test\ComponentTestBuilder;

use Test\Ecotone\Messaging\Fixture\Service\ServiceExpectingOneArgument;
use function json_encode;

use Psr\Log\LoggerInterface;
use Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\ServiceActivator\WithLogger\ServiceActivatorWithLoggerExample;
use Test\Ecotone\Messaging\Unit\MessagingTest;

/**
 * Class LoggingHandlerBuilderTest
 * @package Test\Ecotone\Messaging\Unit\Handler\Logger
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 *
 * @internal
 */
class LoggingHandlerBuilderTest extends MessagingTest
{
    public function test_logger_passing_messaging_through()
    {
        $messaging = ComponentTestBuilder::create()
            ->withReference(LoggingHandlerBuilder::LOGGER_REFERENCE, $logger = $this->createMock(LoggerInterface::class))
            ->withMessageHandler(
                ServiceActivatorBuilder::createWithDirectReference(ServiceExpectingOneArgument::create(), 'withReturnMixed')
                    ->withInputChannelName($inputChannel = 'inputChannel')
                    ->withMethodParameterConverters([
                        PayloadBuilder::create('value')
                    ])
            )
            ->build();

        $this->assertEquals(
            100,
            $messaging->sendDirectToChannel($inputChannel, 100)
        );

        $this->assertMessages(
            $message,
            $queueChannel->receive()
        );

        $messaging = ComponentTestBuilder::create()
            ->withReference(LoggingHandlerBuilder::LOGGER_REFERENCE, $logger)
            ->withMessageHandler(
                ServiceActivatorBuilder::createWithDirectReference(ServiceExpectingOneArgument::create(), 'withReturnMixed')
                    ->withInputChannelName($inputChannel = 'inputChannel')
                    ->withMethodParameterConverters([
                        PayloadBuilder::create('value')
                    ])
            )
            ->build();

        $this->assertEquals(
            100,
            $messaging->sendDirectToChannel($inputChannel, 100)
        );
    }

    public function test_given_payload_is_string_when_logging_without_debug_level_then_default_debug_level_should_be_used()
    {
        $logParameter = InterfaceToCall::create(LoggingInterceptor::class, 'logBefore')->getParameterWithName('log');
        $logger = $this
            ->getMockBuilder(LoggerInterface::class)
            ->getMock();

        $queueChannel = QueueChannel::create();
        $componentTest = ComponentTestBuilder::create()
            ->withChannel('outputChannel', $queueChannel)
            ->withReference(LoggingHandlerBuilder::LOGGER_REFERENCE, $logger)
        ;
        $loggingHandler = $componentTest->build(LoggingHandlerBuilder::createForBefore()
            ->withOutputMessageChannel('outputChannel')
            ->withMethodParameterConverters([
                MessageConverterBuilder::create('message'),
                MethodArgumentsFactory::getAnnotationValueConverter($logParameter, InterfaceToCall::create(ServiceActivatorWithLoggerExample::class, 'sendMessage'), []),
            ]));

        $message = MessageBuilder::withPayload('some')->build();

        $logger
            ->expects($this->once())
            ->method('info')
            ->with('some', [
                'headers' => json_encode([
                    'id' => $message->getHeaders()->getMessageId(),
                    'timestamp' => $message->getHeaders()->getTimestamp(),
                    'correlationId' => $message->getHeaders()->getCorrelationId(),
                ]),
            ]);

        $loggingHandler->handle($message);
    }
}
