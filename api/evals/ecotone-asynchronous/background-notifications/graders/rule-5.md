---
type: llm
---
PASS if the test bootstraps with `EcotoneLite::bootstrapFlowTesting()`, registers the channel in-memory through `extensionObjects:` (e.g. `SimpleMessageChannelBuilder::createQueueChannel('<name>')`), asserts nothing was mailed right after publishing, then calls `$ecotone->run('<name>')` before asserting the confirmation was sent, and moves time with `advanceTimeBy()` (or `changeTimeTo()`) before the `run()` that proves the reminder. FAIL if the test asserts the email without calling `run()`, never checks that nothing was sent before `run()`, or does not move time to prove the 24-hour delay.
