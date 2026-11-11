---
description: "Guidelines review of a diff that edits one skill and changes code another skill governs"
tags: [framework-review-guidelines]
max_turns: 20
allowed_tools: [Read, Glob, Grep, Skill]
---
You're the guidelines reviewer for a change in the Ecotone framework monorepo (ecotone-dev). The change's purpose: 'remember which commands a handler already processed, so a redelivered command is skipped'. Here is the full diff (`git diff main...HEAD`):

```diff
--- a/.agents/skills/framework-testing/SKILL.md
+++ b/.agents/skills/framework-testing/SKILL.md
@@
 ### 12. Pin time in tests
-Use the test clock, never `sleep()`.
+Use the test clock, never `sleep()` or `usleep()`.
--- a/packages/Dbal/src/Deduplication/ProcessedCommandInterceptor.php
+++ b/packages/Dbal/src/Deduplication/ProcessedCommandInterceptor.php
@@
     public function intercept(MethodInvocation $invocation, Message $message): mixed
     {
+        $connection = $this->connectionFactory->createContext()->getDbalConnection();
+        $connection->executeStatement('CREATE TABLE IF NOT EXISTS ecotone_processed_commands (command_id VARCHAR(255) PRIMARY KEY)');
+        $result = $invocation->proceed();
+        $connection->executeStatement('INSERT INTO ecotone_processed_commands (command_id) VALUES (?)', [$message->getHeaders()->getMessageId()]);
+        return $result;
     }
```

Write your review report.
