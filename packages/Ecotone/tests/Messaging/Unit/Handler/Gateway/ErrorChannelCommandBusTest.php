<?php

declare(strict_types=1);

namespace Messaging\Unit\Handler\Gateway;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Handler\MessageHandlingException;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\ErrorMessage;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Modelling\Config\InstantRetry\InstantRetryConfiguration;
use Ecotone\Modelling\Config\MessageBusChannel;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Lazy\LazyUuidFromString;
use Test\Ecotone\Messaging\Fixture\Service\Gateway\ErrorChannelCommandBus;
use Test\Ecotone\Messaging\Fixture\Service\Gateway\ErrorChannelWithAsyncChannel;
use Test\Ecotone\Messaging\Fixture\Service\Gateway\TicketService;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\Messaging\SerializationSupport;

/**
 * licence Enterprise
 * @internal
 */
final class ErrorChannelCommandBusTest extends TestCase
{
    public function test_it_throws_when_using_in_non_enterprise_mode(): void
    {
        $this->expectException(LicensingException::class);

        EcotoneLite::bootstrapFlowTesting(
            [TicketService::class, ErrorChannelCommandBus::class],
            [new TicketService()],
            enableAsynchronousProcessing: [
                SimpleMessageChannelBuilder::createQueueChannel('async'),
                SimpleMessageChannelBuilder::createQueueChannel('someErrorChannel'),
            ],
        );
    }

    public function test_using_custom_error_channel_on_gateway(): void
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [TicketService::class, ErrorChannelCommandBus::class],
            [new TicketService()],
            enableAsynchronousProcessing: [
                SimpleMessageChannelBuilder::createQueueChannel('async'),
                SimpleMessageChannelBuilder::createQueueChannel('someErrorChannel'),
            ],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $commandBus = $ecotoneLite->getGateway(ErrorChannelCommandBus::class);

        $payload = Uuid::uuid4();
        $commandBus->sendWithRouting(
            'createViaCommand', $payload,
            metadata: [
                'throwException' => true,
            ]
        );

        $this->assertEquals(
            [],
            $ecotoneLite->sendQueryWithRouting('getTickets')
        );


        $message = $ecotoneLite->getMessageChannel('someErrorChannel')->receive();
        /** @var MessageHandlingException $messagingException */
        $messagingException = $message->getPayload();
        $this->assertInstanceOf(MessageHandlingException::class, $messagingException);
        $this->assertInstanceOf(\RuntimeException::class, $messagingException->getCause());
        $failedMessage = $messagingException->getFailedMessage();
        /** It should be converted to serializable payload */
        $this->assertSame(MediaType::createApplicationXPHPSerialized(), $failedMessage->getHeaders()->getContentType());
        $this->assertSame(LazyUuidFromString::class, $failedMessage->getHeaders()->get(MessageHeaders::TYPE_ID));
        $this->assertSame(SerializationSupport::withPHPSerialization($payload), $failedMessage->getPayload());

        $this->assertSame(MessageBusChannel::COMMAND_CHANNEL_NAME_BY_NAME, $failedMessage->getHeaders()->get(MessageHeaders::POLLED_CHANNEL_NAME));
    }

    public function test_using_custom_error_channel_with_reply_channel(): void
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [TicketService::class, ErrorChannelWithAsyncChannel::class],
            [new TicketService()],
            enableAsynchronousProcessing: [
                SimpleMessageChannelBuilder::createQueueChannel('async'),
                SimpleMessageChannelBuilder::createQueueChannel('someErrorChannel'),
            ],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $commandBus = $ecotoneLite->getGateway(ErrorChannelWithAsyncChannel::class);

        $payload = Uuid::uuid4();
        $commandBus->sendWithRouting(
            'createViaCommand', $payload,
            metadata: [
                'throwException' => true,
            ]
        );

        $this->assertEquals(
            [],
            $ecotoneLite->sendQueryWithRouting('getTickets')
        );


        $message = $ecotoneLite->getMessageChannel('async')->receive();
        $this->assertNotNull($message);
    }
}
