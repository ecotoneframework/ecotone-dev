---
description: "Handling many slow notifications at once in one consumer process on OpenSwoole"
tags: [ecotone-open-swoole]
max_turns: 15
allowed_tools: [Read, Glob, Grep, Skill]
---
We run a Symfony app with Ecotone 2.0. Our Docker image has PHP 8.4 with the `openswoole` and `amqp` extensions, the database is PostgreSQL and the queue is RabbitMQ. This handler calls a slow SMS provider (about 300 ms per call) and we currently run 30 consumer processes to keep up, which costs too much memory:

```php
<?php

namespace App\Notifications;

use Doctrine\DBAL\Connection;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\EventHandler;

final class SmsNotifications
{
    public function __construct(private SmsProvider $smsProvider, private Connection $connection) {}

    #[Asynchronous('notifications')]
    #[EventHandler(endpointId: 'notifications.sms')]
    public function send(OrderWasShipped $event): void
    {
        $this->smsProvider->send($event->phoneNumber, 'Your order is on its way');
        $this->connection->insert('sent_sms', ['order_id' => $event->orderId]);
    }
}
```

```php
#[ServiceContext]
public function connections(): array
{
    return [
        SymfonyConnectionReference::defaultManagerRegistry('default'),
        AmqpBackedMessageChannelBuilder::create('notifications'),
    ];
}
```

`Enqueue\AmqpExt\AmqpConnectionFactory` is registered as a service for RabbitMQ.

I want one consumer process to handle 30 of these messages at the same time instead of 30 processes. Show me everything I need to change: the handler, the configuration, the services and the command that starts the consumer. We can change the database engine if we have to. Don't ask questions.
