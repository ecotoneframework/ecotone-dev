<?php

namespace Ecotone\Messaging\Channel;

use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\MessageHandler;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\SubscribableChannel;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Messaging\Support\MessageBuilder;

class InProcessChannel implements SubscribableChannel, DefinedObject
{
    /**
     * @var MessageHandler[]
     */
    protected array $messageHandlers = [];

    private array $messageStack = [];
    private string|MessageChannel|null $currentReplyChannel = null;

    private bool $processing = false;

    public function __construct(private string $messageChannelName, private bool $isDirectChannel = false)
    {
    }

    public static function createDirectChannel(string $messageChannelName = '', ?MessageHandler $messageHandler = null): self
    {
        $channel = new self($messageChannelName, true);
        if ($messageHandler) {
            $channel->subscribe($messageHandler);
        }
        return $channel;
    }

    public static function createPublishSubscribeChannel(string $messageChannelName = ''): self
    {
        return new self($messageChannelName, false);
    }

    /**
     * @inheritDoc
     */
    public function send(Message $message): void
    {
        if ($this->isDirectChannel && \count($this->messageHandlers) !== 1) {
            throw MessageDispatchingException::create("There is no message handler registered for dispatching Message {$message}");
        }

        if ($message->getHeaders()->containsKey(MessageHeaders::IN_PROCESS_EXECUTOR_INTERCEPTING)) {
            Assert::isTrue(empty($this->messageStack), "Cannot send message to in process channel when it is already intercepted");
            foreach ($this->messageHandlers as $mainMessageHandler) {
                $mainMessageHandler->handle($message);
            }
            return;
        }
        if (
            $message->getHeaders()->containsKey(MessageHeaders::IN_PROCESS_EXECUTOR)
        ) {
            /** @var self $executor */
            $executor = $message->getHeaders()->get(MessageHeaders::IN_PROCESS_EXECUTOR);
            if ($executor->canHandleMessage($message)) {
                $executor->push($message, ...$this->messageHandlers);
                return;
            }
        }

        if ($message->getHeaders()->containsKey(MessageHeaders::REPLY_CHANNEL)) {
            $this->currentReplyChannel = $message->getHeaders()->get(MessageHeaders::REPLY_CHANNEL);
        }
        $message = MessageBuilder::fromMessage($message)
            ->setHeader(MessageHeaders::IN_PROCESS_EXECUTOR, $this)
            ->build();

        $this->processing = true;

        foreach ($this->messageHandlers as $mainMessageHandler) {
            $mainMessageHandler->handle($message);
            while ([$stackedMessageHandler, $stackedMessage] = array_shift($this->messageStack)) {
                $stackedMessageHandler->handle($stackedMessage);
            }
        }
        $this->processing = false;
    }

    public function push(Message $message, MessageHandler ...$messageHandlers): void
    {
        if (! $this->processing) {
            throw new \InvalidArgumentException("Cannot push message to in process channel when it is already handled");
        }
        foreach ($messageHandlers as $messageHandler) {
            $this->messageStack[] = [$messageHandler, $message];
        }
    }

    public function canHandleMessage(Message $message): bool
    {
        if ($message->getHeaders()->containsKey(MessageHeaders::REPLY_CHANNEL)) {
            return $this->processing && $this->currentReplyChannel === $message->getHeaders()->get(MessageHeaders::REPLY_CHANNEL);
        }
        return true;
    }

    /**
     * @inheritDoc
     */
    public function subscribe(MessageHandler $messageHandler): void
    {
        if ($this->isDirectChannel && \count($this->messageHandlers) === 1) {
            throw WrongHandlerAmountException::create("Direct channel {$this->messageChannelName} have registered more than one handler. {$messageHandler} can't be registered as second handler for unicasting dispatcher. The first is {$this->messageHandler}");
        }
        $this->messageHandlers[] = $messageHandler;
    }

    /**
     * @inheritDoc
     */
    public function unsubscribe(MessageHandler $messageHandler): void
    {
        $handlers = [];
        foreach ($this->messageHandlers as $messageHandlerToCompare) {
            if ($messageHandlerToCompare === $messageHandler) {
                continue;
            }

            $handlers[] = $messageHandlerToCompare;
        }

        $this->messageHandlers = $handlers;
    }

    public function __toString()
    {
        $type = $this->isDirectChannel ? "direct" : "publish-subscribe";
        return "{$type}: {$this->messageChannelName}";
    }

    public function getDefinition(): Definition
    {
        return new Definition(self::class, [$this->messageChannelName, $this->isDirectChannel]);
    }
}