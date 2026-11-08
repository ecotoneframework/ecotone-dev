---
type: llm
---
PASS if the tenant-enriching interceptor is guaranteed to run before the role check by being the same kind as the role check (e.g. both `#[Presend]`) with a lower `precedence` value than the role check. FAIL if the ordering requirement is ignored, if the tenant interceptor has the same or a higher `precedence` value than the role check while claiming to run first, or if the tenant header is added by a `#[Before]`/`#[After]` interceptor that would run only after a `#[Presend]` role check.
