---
description: "Breaking-changes review of a diff touching Api/ and a table"
tags: [framework-review-breaking-changes]
max_turns: 20
allowed_tools: [Read, Glob, Grep, Skill]
---
You're the breaking-changes reviewer for a change in the Ecotone framework monorepo (ecotone-dev). Latest tag is `2.3.1`; `dev-main` branch alias is `2.4.x-dev`. The change's purpose: 'clean up dead letter naming'. Diff (`git diff main...HEAD`):

```diff
--- a/packages/Dbal/Api/Recoverability/DeadLetterGateway.php
+++ b/packages/Dbal/Api/Recoverability/DeadLetterGateway.php
-    public function list(int $limit, int $offset): array;
+    public function list(int $pageSize, int $offset, bool $includeReplayed): array;
--- a/packages/Dbal/src/Recoverability/DeadLetterTableManager.php
+++ b/packages/Dbal/src/Recoverability/DeadLetterTableManager.php
-        $table->addColumn('failed_at', Types::BIGINT);
+        $table->addColumn('failure_time', Types::DATETIME_IMMUTABLE);
--- a/packages/Dbal/src/Recoverability/DbalDeadLetterHandler.php
+++ b/packages/Dbal/src/Recoverability/DbalDeadLetterHandler.php
-    const RETRY_COUNT_HEADER = 'ecotone_dbal_deadletter_retry_count';
+    const RETRY_COUNT_HEADER = 'ecotone_dbal_deadletter_retries';
-        $this->replayAll(limit: 100);
+        $this->replayAll(limit: 1000);
```

No upgrade note was added. Write your review report.
