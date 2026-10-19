<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Recoverability;

use Ecotone\Api\Messaging\ErrorContext;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageChannel;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Dbal\Tracing\FlowTrackingScope;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ErrorChannelExceptionMessages;
use Ecotone\Messaging\Gateway\MessagingEntrypointService;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Messaging\Support\MessageBuilder;

/**
 * licence Apache-2.0
 */
final class DeadLetterHandler
{
    public function __construct(
        private DeadLetterStore $deadLetterStore,
        private MessageBuilder $messageBuilder,
        private FlowTrackingScope $flowTracking,
    ) {
    }

    /**
     * @return ErrorContext[]
     */
    public function list(int $limit, int $offset): array
    {
        return $this->deadLetterStore->list($limit, $offset);
    }

    public function show(string $messageId, ?MessageChannel $replyChannel = null): Message
    {
        $message = $this->deadLetterStore->find($messageId);
        if ($message === null) {
            throw InvalidArgumentException::create(
                "The dead letter holds no message with id {$messageId}: it was replayed or deleted already, or it was never stored here. "
                . 'List the messages it holds with DeadLetterGateway::list() or the ecotone:deadletter:list console command, '
                . 'and check that you are asking the dead letter gateway the message was stored through.'
            );
        }

        if (! $replyChannel) {
            return $message;
        }

        return $this->messageBuilder->fromMessage($message)
            ->removeHeader(MessageHeaders::ROUTING_SLIP)
            ->setHeader(MessageHeaders::REPLY_CHANNEL, $replyChannel)
            ->build();
    }

    public function count(): int
    {
        return $this->deadLetterStore->count();
    }

    public function replay(string|array $messageId, MessagingEntrypointService $messagingEntrypoint): void
    {
        foreach ((array) $messageId as $id) {
            $this->replayMessage($id, $messagingEntrypoint);
        }
    }

    public function replayAll(MessagingEntrypointService $messagingEntrypoint): void
    {
        while ($errorContexts = $this->deadLetterStore->list(100, 0)) {
            foreach ($errorContexts as $errorContext) {
                $this->replayMessage($errorContext->getMessageId(), $messagingEntrypoint);
            }
        }
    }

    public function delete(string|array $messageId): void
    {
        foreach ((array) $messageId as $id) {
            $this->deadLetterStore->delete($id);
        }
    }

    public function deleteAll(): void
    {
        $this->deadLetterStore->deleteAll();
    }

    public function store(Message $message): void
    {
        $this->deadLetterStore->store($message);
    }

    private function replayMessage(string $messageId, MessagingEntrypointService $messagingEntrypoint): void
    {
        $message = $this->show($messageId);
        $hasPolledChannel = $message->getHeaders()->containsKey(MessageHeaders::POLLED_CHANNEL_NAME);
        $hasInboundRequestChannel = $message->getHeaders()->containsKey(MessageHeaders::INBOUND_REQUEST_CHANNEL);
        $hasRoutingSlip = $message->getHeaders()->containsKey(MessageHeaders::ROUTING_SLIP);

        if (! $hasPolledChannel && ! $hasInboundRequestChannel && ! $hasRoutingSlip) {
            throw InvalidArgumentException::create(ErrorChannelExceptionMessages::cannotReplyToDeadLetterMessage($messageId));
        }

        if ($hasPolledChannel) {
            $entrypoint = $message->getHeaders()->get(MessageHeaders::POLLED_CHANNEL_NAME);
        } elseif ($hasInboundRequestChannel) {
            $entrypoint = $message->getHeaders()->get(MessageHeaders::INBOUND_REQUEST_CHANNEL);
        } else {
            $routingSlip = $message->getHeaders()->resolveRoutingSlip();
            $entrypoint = array_shift($routingSlip);

            $message = $this->messageBuilder->fromMessage($message)
                ->setRoutingSlip($routingSlip)
                ->build();
        }

        $message = $this->messageBuilder->fromMessage($message)
            ->removeHeaders(ErrorContext::WHOLE_ERROR_CONTEXT)
            ->setHeader(ErrorContext::DLQ_MESSAGE_REPLIED, '1')
            ->setHeader(
                MessagingEntrypointService::ENTRYPOINT,
                $entrypoint
            )
            ->build();

        $this->flowTracking->publishingStepsAfterCommit(fn () => $this->flowTracking->storingSpansOf(function () use ($messagingEntrypoint, $message, $messageId): void {
            $messagingEntrypoint->sendMessage($message);
            $this->deadLetterStore->delete($messageId);
        }));
    }
}
