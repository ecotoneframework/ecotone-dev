---
type: llm
---
PASS if the answer says the channel does get a scenario in the flow scenario catalogue despite needing real
coroutines, and all of the following hold:

1. It adds a scenario method to a `*FlowScenarios` class in packages/Ecotone/src/Test/Flow that registers the real
   `SameWorkerChannelBuilder` channel (not a queue channel standing in for it), and lists the method in that class's
   `all()` (and the class in `FlowScenarioCatalogue::all()` when the class is new).
2. The scenario is ended with `->needingACoroutineRuntime()`.
3. It says the scenario is left out where neither `swoole` nor `openswoole` is loaded and runs where one is.
4. It leaves packages/Ecotone/tests/BuildFlow/baseline untouched.

FAIL if it says a feature needing real coroutines cannot be a scenario, replaces the channel with
`SimpleMessageChannelBuilder` or another stand-in in the scenario, omits `needingACoroutineRuntime()`, or edits or adds
files under the baseline.
