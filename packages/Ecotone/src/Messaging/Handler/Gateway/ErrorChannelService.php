<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Gateway;

use Ecotone\Messaging\Channel\PollableChannel\Serialization\OutboundMessageConverter;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Handler\MessageHandlingException;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\ErrorMessage;
use Ecotone\Messaging\Support\MessageBuilder;

/**
 * licence Apache-2.0
 */
final class ErrorChannelService
{
    public function __construct(
        private LoggingGateway $loggingGateway,
        private OutboundMessageConverter $outboundMessageConverter,
        private ConversionService $conversionService,
    )
    {
    }

    public function handle(
        Message $requestMessage,
        \Throwable $exception,
        MessageChannel $errorChannel,
        ?string $relatedPolledChannelName,
        ?string $routingSlip = null,
    )
    {
        $this->loggingGateway->error(
            'Error occurred during handling message. Sending Message to handle it in predefined Error Channel.',
            $requestMessage,
            ['exception' => $exception],
        );

        $outboundMessage = $this->outboundMessageConverter->prepare($requestMessage, $this->conversionService);
        $messageBuilder = MessageBuilder::withPayload($outboundMessage->getPayload())
            ->setMultipleHeaders($outboundMessage->getHeaders());

        if ($relatedPolledChannelName) {
            $messageBuilder = $messageBuilder->setHeader(MessageHeaders::POLLED_CHANNEL_NAME, $relatedPolledChannelName);
        }

        if ($routingSlip) {
            $messageBuilder = $messageBuilder->prependRoutingSlip([$routingSlip]);
        }

        $errorChannel->send(ErrorMessage::create(
            MessageHandlingException::fromOtherException(
                $exception,
                $messageBuilder
                    ->build()
            )
        ));

        $this->loggingGateway->info(
            'Message was sent to Error Channel successfully.',
            $requestMessage,
            ['exception' => $exception],
        );
    }
}