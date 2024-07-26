<?php

namespace Ecotone\Messaging\Channel;

use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\MessageHandler;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\SubscribableChannel;
use Ecotone\Messaging\Support\MessageBuilder;

class InProcessChannel implements SubscribableChannel, DefinedObject
{
    private array $messageStack = [];
    private string|MessageChannel|null $currentReplyChannel = null;

    public function __construct(private string $messageChannelName, private ?MessageHandler $messageHandler = null)
    {
    }

    public static function create(string $messageChannelName = ''): self
    {
        return new self($messageChannelName);
    }

    /**
     * @inheritDoc
     */
    public function send(Message $message): void
    {
        if (! $this->messageHandler) {
            throw MessageDispatchingException::create("There is no message handler registered for dispatching Message {$message}");
        }
        if ($message->getHeaders()->containsKey(MessageHeaders::IN_PROCESS_EXECUTOR)) {
            $executor = $message->getHeaders()->get(MessageHeaders::IN_PROCESS_EXECUTOR);
            if ($executor->canHandleMessage($message)) {
                $executor->push($this->messageHandler, $message);
                return;
            }
        }

        if ($message->getHeaders()->containsKey(MessageHeaders::REPLY_CHANNEL)) {
            $this->currentReplyChannel = $message->getHeaders()->get(MessageHeaders::REPLY_CHANNEL);
        }
        $message = MessageBuilder::fromMessage($message)
            ->setHeader(MessageHeaders::IN_PROCESS_EXECUTOR, $this)
            ->build();
        $this->messageHandler->handle($message);
        while ([$messageHandler, $message] = array_shift($this->messageStack)) {
            $messageHandler->handle($message);
        }
    }

    public function push(MessageHandler $messageHandler, Message $message): void
    {
        $this->messageStack[] = [$messageHandler, $message];
    }

    public function canHandleMessage(Message $message): bool
    {
        if ($message->getHeaders()->containsKey(MessageHeaders::REPLY_CHANNEL)) {
            return $this->currentReplyChannel === $message->getHeaders()->get(MessageHeaders::REPLY_CHANNEL);
        }
        return true;
    }

    /**
     * @inheritDoc
     */
    public function subscribe(MessageHandler $messageHandler): void
    {
        if ($this->messageHandler) {
            throw WrongHandlerAmountException::create("Direct channel {$this->messageChannelName} have registered more than one handler. {$messageHandler} can't be registered as second handler for unicasting dispatcher. The first is {$this->messageHandler}");
        }

        $this->messageHandler = $messageHandler;
    }

    /**
     * @inheritDoc
     */
    public function unsubscribe(MessageHandler $messageHandler): void
    {
        $this->messageHandler = null;
    }

    public function __toString()
    {
        return 'direct: ' . $this->messageChannelName;
    }

    public function getDefinition(): Definition
    {
        return new Definition(self::class, [$this->messageChannelName]);
    }
}