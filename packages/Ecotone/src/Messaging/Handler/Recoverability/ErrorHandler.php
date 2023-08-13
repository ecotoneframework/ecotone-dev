<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Recoverability;

use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\Logger\LoggingHandlerBuilder;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\MessagingException;
use Ecotone\Messaging\Support\ErrorMessage;
use Ecotone\Messaging\Support\MessageBuilder;
use Psr\Log\LoggerInterface;

class ErrorHandler
{
    public const ECOTONE_RETRY_HEADER = 'ecotone_retry_number';
    public const EXCEPTION_STACKTRACE = 'exception-stacktrace';
    public const EXCEPTION_FILE = 'exception-file';
    public const EXCEPTION_LINE = 'exception-line';
    public const EXCEPTION_CODE = 'exception-code';
    public const EXCEPTION_MESSAGE = 'exception-message';

    public function __construct(
        private RetryTemplate $delayedRetryTemplate,
        private bool $hasDeadLetterOutput
    ) {
    }

    public function handle(
        ErrorMessage $errorMessage,
        ChannelResolver $channelResolver,
        #[Reference(LoggingHandlerBuilder::LOGGER_REFERENCE)] LoggerInterface $logger
    ): ?Message {
        /** @var MessagingException $messagingException */
        $messagingException = $errorMessage->getPayload();
        $failedMessage = $messagingException->getFailedMessage();
        $cause = $messagingException->getCause() ? $messagingException->getCause() : $messagingException;
        $retryNumber = $failedMessage->getHeaders()->containsKey(self::ECOTONE_RETRY_HEADER) ? $failedMessage->getHeaders()->get(self::ECOTONE_RETRY_HEADER) + 1 : 1;

        if (! $failedMessage->getHeaders()->containsKey(MessageHeaders::POLLED_CHANNEL_NAME)) {
            throw $cause;
        }
        /** @var MessageChannel $messageChannel */
        $messageChannel = $channelResolver->resolve($failedMessage->getHeaders()->get(MessageHeaders::POLLED_CHANNEL_NAME));

        $messageBuilder = MessageBuilder::fromMessage($failedMessage);
        if ($messageBuilder->containsKey(MessageHeaders::CONSUMER_ACK_HEADER_LOCATION)) {
            $messageBuilder->removeHeader($messageBuilder->getHeaderWithName(MessageHeaders::CONSUMER_ACK_HEADER_LOCATION));
        }

        $messageBuilder->removeHeaders([
            MessageHeaders::DELIVERY_DELAY,
            MessageHeaders::TIME_TO_LIVE,
            MessageHeaders::CONSUMER_ACK_HEADER_LOCATION,
        ]);

        if ($this->shouldBeSendToDeadLetter($retryNumber)) {
            if (! $this->hasDeadLetterOutput) {
                $logger->critical(
                    sprintf(
                        'Discarding message %s as no dead letter channel was defined. Retried maximum number of `%s` times',
                        $failedMessage->getHeaders()->getMessageId(),
                        $retryNumber
                    ),
                    ['exception' => $cause]
                );

                return null;
            }

            $logger->critical(
                sprintf(
                    'Sending message `%s` to dead letter channel, as retried maximum number of `%s` times',
                    $failedMessage->getHeaders()->getMessageId(),
                    $retryNumber
                ),
                ['exception' => $cause]
            );
            $messageBuilder->removeHeader(self::ECOTONE_RETRY_HEADER);

            return $messageBuilder
                    ->setHeader(ErrorContext::EXCEPTION_MESSAGE, $cause->getMessage())
                    ->setHeader(ErrorContext::EXCEPTION_STACKTRACE, $cause->getTraceAsString())
                    ->setHeader(ErrorContext::EXCEPTION_FILE, $cause->getFile())
                    ->setHeader(ErrorContext::EXCEPTION_LINE, $cause->getLine())
                    ->setHeader(ErrorContext::EXCEPTION_CODE, $cause->getCode())
                    ->build();
        }

        $delayMs = $this->delayedRetryTemplate->calculateNextDelay($retryNumber);
        $logger->info(
            sprintf(
                'Retrying message with id `%s` with delay of `%d` ms. %s',
                $failedMessage->getHeaders()->getMessageId(),
                $delayMs,
                $this->delayedRetryTemplate->getMaxAttempts()
                    ? sprintf("Try %d out of %s", $retryNumber, $this->delayedRetryTemplate->getMaxAttempts())
                    : ''
            ),
            ['exception' => $cause]
        );
        $messageChannel->send(
            $messageBuilder
                ->setHeader(MessageHeaders::DELIVERY_DELAY, $delayMs)
                ->setHeader(self::ECOTONE_RETRY_HEADER, $retryNumber)
                ->build()
        );

        return null;
    }

    private function shouldBeSendToDeadLetter(int $retryNumber): bool
    {
        return ! $this->delayedRetryTemplate->canBeCalledNextTime($retryNumber);
    }
}
