---
description: "Making a framework store's read-check-write and the user's callback atomic"
tags: [framework-storage-and-transactions]
max_turns: 15
allowed_tools: [Read, Glob, Grep, Skill]
---
I'm in the Ecotone framework monorepo (ecotone-dev), adding a DBAL store in packages/Dbal for a new feature. When the last expected reply for a request arrives, the store marks the request finished and then calls back into the application's handler with all replies. If the handler throws, the finish must not stick, so a retry can finish it again. Here is my draft:

```php
public function finish(string $requestId, callable $whenFinished): bool
{
    $connection = $this->connection();

    return $connection->transactional(function () use ($connection, $requestId, $whenFinished): bool {
        $row = $connection->fetchAssociative('SELECT * FROM ecotone_requests WHERE id = ?', [$requestId]);
        $updated = $connection->executeStatement("UPDATE ecotone_requests SET state = 'finished', version = version + 1 WHERE id = ? AND version = ?", [$requestId, $row['version']]);
        if ($updated === 0) {
            return false;
        }
        $whenFinished($this->repliesOf($requestId));

        return true;
    });
}
```

The store is also used by a console command `ecotone:requests:finish` that my module registers with `ConsoleCommandModule::prepareConsoleCommandForReference(...)`. Review the draft and give me the final version of `finish()` plus how the console command should be registered, so finishing and the handler stay atomic. Be concrete; don't ask questions.
