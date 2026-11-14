---
type: llm
---
PASS if the lost version write throws Ecotone\Api\Messaging\ConcurrencyException with actionable remedies and the
existing default command-bus/asynchronous endpoint instant retries under a database transaction own retry, with
fresh transactions and normal configured error-channel/delayed-retry/dead-letter handling after exhaustion. The
answer must propose focused tests for default automatic retry and exhaustion reaching the dead letter, and may
name InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(true, n) and one consumer or a
dedicated channel for a hot key. FAIL if it adds a handwritten retry loop, re-sends/redelivers the consumed message
from the store or handler, introduces a new feature-owned retry/backoff configuration type or custom recovery mechanism, or silently swallows
the conflict. Application configuration through the existing InstantRetryConfiguration or error-channel APIs is
allowed and is not feature-specific retry machinery.
