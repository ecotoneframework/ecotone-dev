<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Gateway;

use Ecotone\Messaging\Channel\PollableChannel\Serialization\OutboundMessageConverter;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Handler\MessageHandlingException;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\ErrorMessage;
use Ecotone\Messaging\Support\MessageBuilder;
use Throwable;

/**
 * licence Apache-2.0
 */
class ErrorChannelInterceptor
{
    public function __construct(
        private ErrorChannelService $errorChannelService,
        private MessageChannel $errorChannel,
        private ?string $relatedPolledChannelName = null,
    )
    {
    }

    public function handle(MethodInvocation $methodInvocation, Message $requestMessage)
    {
        try {
            return $methodInvocation->proceed();
        } catch (Throwable $exception) {
            $this->errorChannelService->handle(
                $requestMessage,
                $exception,
                $this->errorChannel,
                $this->relatedPolledChannelName,
            );
        }
    }
}
