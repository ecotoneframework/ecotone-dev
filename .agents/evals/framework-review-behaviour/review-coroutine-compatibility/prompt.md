---
description: "Behaviour review of an interceptor that keeps the handled message's state in a property"
tags: [framework-review-behaviour]
max_turns: 20
allowed_tools: [Read, Glob, Grep, Skill]
---
You're the feature/behaviour reviewer for a change in the Ecotone framework monorepo (ecotone-dev). Problem reported: 'A handler cannot tell which attempt of a message it is handling, so it cannot skip the notification on a retry.' The change adds a `#[DeliveryAttempt] int $attempt` handler parameter. Diff summary: `packages/Ecotone/Api/Attribute/Parameter/DeliveryAttempt.php` is the new attribute; `packages/Ecotone/src/Messaging/Handler/Recoverability/DeliveryAttemptInterceptor.php` is a container service registered around every message handler; `DeliveryAttemptConverter` resolves the parameter from it. The interceptor:

```php
final class DeliveryAttemptInterceptor
{
    private int $attemptOfHandledMessage = 1;

    public function __construct(private CachedConnectionFactory $connectionFactory)
    {
    }

    public function trackAttempt(MethodInvocation $methodInvocation, Message $message): mixed
    {
        $connection = $this->connectionFactory->createContext()->getDbalConnection();
        $this->attemptOfHandledMessage = $this->attemptsStoredFor($connection, $message) + 1;
        try {
            return $methodInvocation->proceed();
        } finally {
            $this->attemptOfHandledMessage = 1;
        }
    }

    public function attemptOfHandledMessage(): int
    {
        return $this->attemptOfHandledMessage;
    }
}
```

Test added:

```php
public function test_handler_receives_the_attempt_of_the_message(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting(
        [NotificationHandler::class],
        [$handler = new NotificationHandler(failuresBeforeSuccess: 1), DbalConnectionFactory::class => $this->connectionFactory()],
        enableAsynchronousProcessing: [DbalBackedMessageChannelBuilder::create('notifications')],
    );
    $ecotone->sendCommand(new SendNotification('1'));
    $ecotone->run('notifications', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 2, stopOnError: false));
    $this->assertSame([1, 2], $handler->attemptsSeen('1'));
}
```

`NotificationHandler` carries `#[Asynchronous('notifications')]` and `#[CommandHandler]`, and its handler stores a row through a repository before it reads `$attempt`. Write your review report.
