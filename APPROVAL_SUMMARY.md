# Refactoring Proposal - Ready for Approval

## Executive Summary

Replace the `int $timeoutInMilliseconds` parameter with `PollingMetadata $pollingMetadata` in the `MessagePoller.receiveWithTimeout()` method. This enables adapters to dynamically adjust behavior based on polling constraints, preventing message reprocessing when consumers stop before committing batches.

## Problem

**Current Issue:** AmqpStreamInboundChannelAdapter has fixed `commitInterval` set at construction. When consumer runs with execution constraints (e.g., `handledMessageLimit=50`), it may stop before committing the batch, causing reprocessing on next run.

**Root Cause:** `receiveWithTimeout()` only receives timeout value, not full polling context.

## Solution

Pass complete `PollingMetadata` to `receiveWithTimeout()` instead of just timeout. This provides adapters with:
- `handledMessageLimit` - stop after N messages
- `executionTimeLimitInMilliseconds` - stop after N milliseconds
- `fixedRateInMilliseconds` - timeout for receive
- All other polling constraints

**Key Logic:** When limits are set, override `commitInterval=1` to ensure all messages are committed before consumer stops.

## Changes Required

### Interface Change (1 file)
- `MessagePoller.php` - Update signature

### Implementation Changes (5 files)
- `PollableChannelPollerAdapter.php` - Extract timeout from metadata
- `InvocationPollerAdapter.php` - Accept metadata parameter
- `EnqueueInboundChannelAdapter.php` - Extract timeout from metadata
- `AmqpStreamInboundChannelAdapter.php` - **Add commit interval override logic**
- `KafkaInboundChannelAdapter.php` - Extract timeout from metadata

### Call Site Changes (1 file)
- `PollToGatewayTaskExecutor.php` - Pass metadata instead of timeout

### Tests (1 file)
- `AmqpStreamChannelTest.php` - Add 2 new test cases

## Impact Analysis

| Aspect | Impact | Notes |
|--------|--------|-------|
| Public API | None | MessagePoller is internal interface |
| External Code | None | No external dependencies |
| Existing Tests | Requires Updates | All call sites must be updated |
| Performance | Neutral | No performance impact |
| Backward Compat | Breaking | Internal interface only |

## Implementation Phases

1. **Phase 1 (Ecotone)** - 3 files, 1 interface
2. **Phase 2 (Enqueue)** - 1 file
3. **Phase 3 (AMQP)** - 1 file + logic
4. **Phase 4 (Kafka)** - 1 file
5. **Phase 5 (Testing)** - Add tests + verify all pass

## Key Benefits

✅ **Prevents Message Reprocessing** - Commits happen before consumer stops
✅ **Cleaner API** - Single parameter with full context
✅ **Eliminates Redundancy** - Timeout already in PollingMetadata
✅ **Enables Smart Behavior** - Adapters can make intelligent decisions
✅ **Localized Change** - Framework internals only

## Test Strategy

1. Run existing tests in `packages/Ecotone/tests` after Phase 1
2. Run existing tests in `packages/Enqueue/tests` after Phase 2
3. Add new tests in `packages/Amqp/tests/Integration/AmqpStreamChannelTest.php`
4. Run full test suite for each package
5. Run Docker container tests as per project conventions

## Files Summary

| Package | Files | Type |
|---------|-------|------|
| Ecotone | 4 | Interface + implementations + call site |
| Enqueue | 1 | Implementation |
| Amqp | 2 | Implementation + tests |
| Kafka | 1 | Implementation |
| **Total** | **8** | **1 interface, 5 implementations, 1 call site, 1 test file** |

## Approval Checklist

- [ ] Problem statement understood
- [ ] Solution approach approved
- [ ] Implementation plan acceptable
- [ ] Test strategy sufficient
- [ ] Ready to proceed with Phase 1

## Next Steps (Upon Approval)

1. Create task list for 5 phases
2. Start Phase 1: Update Ecotone package
3. Run tests after each phase
4. Document any issues found
5. Proceed to next phase only after tests pass

---

**Detailed documentation available in:**
- `REFACTORING_PROPOSAL.md` - Full technical proposal
- `IMPLEMENTATION_DETAILS.md` - Before/after code for each file
- `REFACTORING_SUMMARY.md` - Quick reference guide

