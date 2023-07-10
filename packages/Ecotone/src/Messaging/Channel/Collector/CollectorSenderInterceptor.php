<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\Collector;

use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Handler\MessageHandlingException;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplate;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\Support\ErrorMessage;
use Ecotone\Messaging\Support\MessageBuilder;
use Psr\Log\LoggerInterface;

final class CollectorSenderInterceptor
{
    public function __construct(private Collector $collector, private string $targetChannel, private ?string $defaultErrorChannel)
    {
    }

    public function send(MethodInvocation $methodInvocation, #[Reference] ConfiguredMessagingSystem $configuredMessagingSystem, #[Reference("logger")] LoggerInterface $logger): mixed
    {
        /** For example Command Bus inside Command Bus */
        if ($this->collector->isEnabled()) {
            return $methodInvocation->proceed();
        }

        $this->collector->enable();
        try {
            $result = $methodInvocation->proceed();
            $collectedMessages = $this->collector->getCollectedMessages();
            $this->collector->disable();
            if ($collectedMessages !== []) {
                $messageChannel = $this->getTargetChannel($configuredMessagingSystem);

                try {
                    $this->sendCollectedMessages(
                        $messageChannel,
                        $logger,
                        $collectedMessages,
                        RetryTemplateBuilder::exponentialBackoff(1, 10)
                            ->maxRetryAttempts(2)
                            ->build(),
                        1
                    );
                }catch (\Exception $exception) {
                    try {
                        if ($this->defaultErrorChannel !== null) {
                            $errorChannel = $configuredMessagingSystem->getMessageChannelByName($this->defaultErrorChannel);
                            foreach ($collectedMessages as $collectedMessage) {
                                $errorChannel->send(
                                    ErrorMessage::create(MessageHandlingException::fromOtherException(
                                        $exception,
                                        $collectedMessage->getMessage())
                                    )
                                );
                            }
                        }
                    } finally {
                        $this->logException($logger, $exception, $collectedMessages);
                    }
                }
            }
        } finally {
            $this->collector->disable();
        }

        return $result;
    }

    private function getTargetChannel(ConfiguredMessagingSystem $configuredMessagingSystem): MessageChannel
    {
        return $configuredMessagingSystem->getMessageChannelByName($this->targetChannel);
    }

    private function sendCollectedMessages(MessageChannel $messageChannel, LoggerInterface $logger, array $collectedMessages, RetryTemplate $retryTemplate, int $attempt): void
    {
        $retryNumber = $attempt - 1;
        if (!$retryTemplate->canBeCalledNextTime($retryNumber)) {
            throw new \InvalidArgumentException("Sending messages to asynchronous channel exceed number of possible tries {$retryNumber} of {$retryTemplate->getMaxAttempts()}.");
        }

        $delay = $retryNumber === 0 ? 0 : $retryTemplate->calculateNextDelay($retryNumber);
        usleep($delay * 1000);

        try {
            $this->tryCatchDelay($messageChannel, $collectedMessages);
        }catch (\Exception $exception) {
            if ($attempt === 1) {
                $logger->info("Failed to send messages to {$this->targetChannel} channel and can't serialize messages to add them to exception", [
                    'exception' => $exception->getMessage()
                ]);
            }elseif ($attempt === 2) {
                $logger->warning("Failed to send messages to {$this->targetChannel} channel and can't serialize messages to add them to exception", [
                    'exception' => $exception->getMessage()
                ]);
            }
            $this->sendCollectedMessages($messageChannel, $logger, $collectedMessages, $retryTemplate, $attempt + 1);
        }
    }

    private function tryCatchDelay(MessageChannel $messageChannel, array $collectedMessages): void
    {
        $messageChannel->send(MessageBuilder::withPayload($collectedMessages)->build());
    }

    private function logException(LoggerInterface $logger, \Exception $exception, array $collectedMessages): void
    {
        try {
            $logger->critical("Failed to send messages to {$this->targetChannel} channel", [
                    'exception' => $exception->getMessage(),
                    'messages' => \json_encode(array_map(function (CollectedMessage $collectedMessage) {
                        return ['targetChannel' => $collectedMessage->getChannelName(), 'payload' => $collectedMessage->getMessage()->getPayload(), 'headers' => $collectedMessage->getMessage()->getHeaders()->headers()];
                    }, $collectedMessages), JSON_THROW_ON_ERROR)]
            );
        } catch (\Exception $exception) {
            /** This may happen for example, when the payload will be too large to send it to the logger */
            $logger->critical("Failed to send messages to {$this->targetChannel} channel and can't serialize messages to add them to exception", [
                'exception' => $exception->getMessage()
            ]);
        }
    }
}