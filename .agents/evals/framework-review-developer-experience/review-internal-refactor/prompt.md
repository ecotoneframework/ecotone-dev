---
description: "Developer experience review of an internal refactor"
tags: [framework-review-developer-experience]
max_turns: 20
allowed_tools: [Read, Glob, Grep, Skill]
---
You're the developer experience reviewer for a change in the Ecotone framework monorepo (ecotone-dev). The change's purpose: 'the polling consumer builds its retry delay in one place instead of three'. Diff (`git diff main...HEAD`):

```diff
--- a/packages/Ecotone/src/Messaging/Endpoint/PollingConsumer/PollingConsumerRunner.php
+++ b/packages/Ecotone/src/Messaging/Endpoint/PollingConsumer/PollingConsumerRunner.php
-        $delay = min($this->initialDelay * (2 ** $attempt), $this->maxDelay);
+        $delay = $this->retryDelay->forAttempt($attempt);
--- /dev/null
+++ b/packages/Ecotone/src/Messaging/Endpoint/PollingConsumer/RetryDelay.php
+/**
+ * licence Apache-2.0
+ */
+final class RetryDelay
+{
+    public function __construct(private int $initialDelay, private int $maxDelay) {}
+
+    public function forAttempt(int $attempt): int
+    {
+        return min($this->initialDelay * (2 ** $attempt), $this->maxDelay);
+    }
+}
```

The same replacement is made in two more classes under `packages/Ecotone/src/Messaging/Endpoint`. No file under `Api/`, no attribute, configuration, console command, exception message or default value changes, and existing tests pass unchanged. Write your review report.
