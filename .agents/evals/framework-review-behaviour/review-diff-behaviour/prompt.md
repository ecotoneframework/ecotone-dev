---
description: "Behaviour review of a fix and its single test"
tags: [framework-review-behaviour]
max_turns: 20
allowed_tools: [Read, Glob, Grep, Skill]
---
You're the feature/behaviour reviewer for a change in the Ecotone framework monorepo (ecotone-dev). Problem reported: 'A command handled twice by two DBAL consumers at the same time sends the confirmation email twice.' The fix adds deduplication by command id. Diff summary: `packages/Dbal/src/Deduplication/DeduplicationInterceptor.php` now inserts the command id into `ecotone_deduplication` before calling the handler, and skips when the insert finds a duplicate. Test added:

```php
public function test_command_sent_twice_is_handled_once(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting([OrderService::class], [$service = new OrderService()]);
    $ecotone->sendCommand(new PlaceOrder('1'), metadata: ['id' => 'abc']);
    $ecotone->sendCommand(new PlaceOrder('1'), metadata: ['id' => 'abc']);
    $this->assertSame(1, $service->emailsSent);
}
```

Write your review report.
