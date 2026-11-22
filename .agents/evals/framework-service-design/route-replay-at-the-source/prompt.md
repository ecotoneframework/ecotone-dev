---
description: "Making replayed messages run synchronously on an in-process channel that normally hands over to a background consumer"
tags: [framework-service-design]
max_turns: 15
allowed_tools: [Read, Glob, Grep, Skill]
---
I'm working in the Ecotone framework monorepo (ecotone-dev), in `packages/Ecotone/src`. We have an in-process channel: a send puts the message on a queue and wakes a background consumer, which runs the handler's endpoint later in the same process.

```php
<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\InProcess;

final class InProcessMessageChannel extends QueueChannel
{
    public function __construct(private string $name, private BackgroundConsumers $consumers)
    {
        parent::__construct($name);
    }

    public function send(Message $message): void
    {
        $delay = $message->getHeaders()->containsKey(MessageHeaders::DELIVERY_DELAY) ? $message->getHeaders()->get(MessageHeaders::DELIVERY_DELAY) : 0;
        if ($delay > 0) {
            $this->consumers->wakeAfter($this->name, $delay, $message);
            return;
        }
        parent::send($message);
        $this->consumers->wake($this->name);
    }
}
```

`BackgroundConsumers::wake()` only works while the event loop runs. Problem: an operator replays a failed message from the dead letter with the `ecotone:deadletter:replay` console command. The dead letter sends the replayed message to the channel it was originally polled from, this one, with the header `ErrorContext::DLQ_MESSAGE_REPLIED` set:

```php
// Ecotone\Dbal\Recoverability\DeadLetterHandler::replayMessage(), shortened
$entrypoint = $targetChannel ?? $message->getHeaders()->get(MessageHeaders::POLLED_CHANNEL_NAME);
$message = $this->messageBuilder->fromMessage($message)
    ->removeHeaders(ErrorContext::WHOLE_ERROR_CONTEXT)
    ->setHeader(ErrorContext::DLQ_MESSAGE_REPLIED, '1')
    ->setHeader(MessagingEntrypointService::ENTRYPOINT, $entrypoint)
    ->build();
$messagingEntrypoint->send($message);
```

In the console there is no event loop, so the replayed message sits on the queue and the command exits without the handler having run. I want a replayed message to be handled synchronously, before the console command returns.

What a background consumer does with each message it takes off the queue is this class of the same feature, which our module registers in the container under `'ecotone.in_process.endpoint.' . $channelName`:

```php
namespace Ecotone\Messaging\Channel\InProcess;

final class InProcessEndpoint
{
    /** runs the handler of the channel's endpoint for one message: its transaction, retries and error channel */
    public function handle(Message $message): void
    {
        // ...
    }
}
```

Make the change: show the code you change or add, in full, and say why you put it where you did. Don't ask me questions.
