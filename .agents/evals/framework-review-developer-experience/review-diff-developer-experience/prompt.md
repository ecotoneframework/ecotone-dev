---
description: "Developer experience review of a new retry feature"
tags: [framework-review-developer-experience]
max_turns: 20
allowed_tools: [Read, Glob, Grep, Skill]
---
You're the developer experience reviewer for a change in the Ecotone framework monorepo (ecotone-dev). The change's purpose: 'let an application retry a failed asynchronous handler with a backoff before giving up'. Diff (`git diff main...HEAD`):

```diff
--- /dev/null
+++ b/packages/Ecotone/Api/Recoverability/RtyCfg.php
+final class RtyCfg
+{
+    public function __construct(
+        public readonly int $maxAtt,
+        public readonly int $initMs,
+        public readonly float $mult,
+        public readonly bool $noDlq = true,
+    ) {}
+}
--- /dev/null
+++ b/packages/Ecotone/Api/Recoverability/HandlerRetryRegistry.php
+interface HandlerRetryRegistry
+{
+    public function register(string $handlerClass, string $methodName, string $endpointId, RtyCfg $cfg): void;
+}
--- a/packages/Ecotone/src/Messaging/Handler/Recoverability/RetryRunner.php
+++ b/packages/Ecotone/src/Messaging/Handler/Recoverability/RetryRunner.php
+        if ($attempt >= $cfg->maxAtt) {
+            if ($cfg->noDlq) {
+                return;
+            }
+            $this->deadLetter->store($message);
+        }
```

The test shows how an application uses it:

```php
$ecotone = EcotoneLite::bootstrapFlowTesting(
    [OrderHandler::class],
    [new OrderHandler(), HandlerRetryRegistry::class => $registry = new InMemoryHandlerRetryRegistry()],
);
$registry->register(OrderHandler::class, 'placeOrder', 'order.place', new RtyCfg(3, 100, 2.0));
$ecotone->sendCommand(new PlaceOrder('1'));
```

`OrderHandler::placeOrder` already carries `#[Asynchronous('orders')]` and `#[CommandHandler(endpointId: 'order.place')]`. Write your review report.
