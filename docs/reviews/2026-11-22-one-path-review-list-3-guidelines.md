NOT PASSED: GL49

Guidelines review, re-check 2 — branch dgafka/one-path-review-requirement, head 47455d84c, base origin/main 5ec5f01aa
Reviewer: framework-review-guidelines session (claude-opus-5-5), read only.
Earlier rounds: docs/reviews/2026-11-22-one-path-review-list-1-guidelines.md (d3ecbf4ca),
docs/reviews/2026-11-22-one-path-review-list-2-guidelines.md (ca1acce60).

## Inventory of the fix range ca1acce60..47455d84c (G1)

- d714d5efd docs(reviews): the four decided lists of round 2.
- ef55ecc9a fix(skills) (GL49): rules.md attributes the mode examples, the pool guard and the two per-message
  interceptors to pull request 779 at `ffee52a2e`, says the evidence's "wrong" snippets are that branch's code and
  only the "right" one with a commit was written; drops the unlocatable bool-ternary example; reflows SKILL.md:126.
- 47455d84c test(skills) (GL49): the three prompts give their feature classes as the scenario's own; the wiring
  case renames its recording classes to `HandOver*` and adds an `extension_loaded('ev')` ternary per `take()`
  that its grader (rule-g3, point 5) requires decided missing; the author prompt's `send()` becomes `sendMessage()`.
All changed files read in full; commit bodies read.

## Round 2 findings

- GL49, rules.md ConnectionPool guard presented as the tree's: resolved. rules.md:407 and :416-418 attribute it to
  pull request 779; verified at `ffee52a2e`: ConnectionPool.php:147 `waitForReturnedConnection()`, :153 the
  `connectionPoolWaitSeconds <= 0.0` guard, :160 the `pop(...)`.
- GL49, wiring prompt claiming its classes exist on main with members the tree lacks: resolved for the recording
  classes (now `HandOverRecordingCompilerPass`, `HandOverRecording`, `HandOverRecorder`, declared as the
  scenario's). One import line of the same prompt is not, see Missing.

## Whole-branch sweep of code claims (GL49), origin/main...47455d84c

Against main: ErrorContext::DLQ_MESSAGE_REPLIED, WHOLE_ERROR_CONTEXT, `ecotone.dlq.message_replied`;
MessageHeaders::DELIVERY_DELAY, PRIORITY, TIME_TO_LIVE, REPLY_CHANNEL, POLLED_CHANNEL_NAME;
AggregateMessage::AGGREGATE_ID; `ecotone_retry_number`; DeadLetterHandler::replayMessage() and its entrypoint
choice; MessagingEntrypointService::ENTRYPOINT and `sendMessage(Message)` (:117); MessageBuilder `fromMessage`,
`removeHeaders`, `setHeader`; QueueChannel (constructor takes the name); AbstractChannelInterceptor::preSend(Message,
MessageChannel): ?Message; ConfigurationException::create (via MessagingException::create); DirectChannel,
Definition, Reference; LicenceDecider::prepareDefinition(); RecordExecutionCompilerPass; EcotoneLite::bootstrapFlowTesting();
`ecotone:deadletter:replay` — all resolve.
Against pull request 779, as attributed: `ffee52a2e` CoroutineMessageChannelSleep.php:37 the openswoole ternary;
CoroutineMessageChannel.php:71-76 `enqueue()` with `getCid() < 1` and `consumer()->createConsumer()->run()`;
CoroutineMessageChannelHoldingInterceptor.php:40-41 the condition and message; CoroutineMessageChannelConsumerStopInterceptor.php:40
(drops by payload) and CoroutineMessageChannelFailureLogger.php:56-57 (acts only for its own channel names);
`08d8e57bf` RecordExecutionCompilerPass.php:172 the class comparison; `6348dc35e` :172 `is_a(..., ConsumedInTheSendingProcess::class, true)`
— all match.
Scenario-declared (stated as not in the tree): InProcessMessageChannel, InProcessSendGuardInterceptor,
BackgroundConsumers, EventLoop, InProcessEndpoint, InProcessConsumerPool(+Exhausted), InProcessChannelBuilder,
StreamSelectWait, EvWait, HandOver* — not GL49 claims.

## Missing

GL49 — missing — .agents/evals/framework-review-guidelines/review-wiring-names-a-feature/prompt.md:15 — the diff's
      unchanged context line `use Ecotone\Messaging\Config\Container\CompilerPass;` names the framework's real
      compiler-pass interface at a namespace that does not exist; in the tree it is
      `Ecotone\Messaging\Config\Container\Compiler\CompilerPass` (as RecordExecutionCompilerPass.php:15 imports it).
      The prompt now declares only its `HandOver*` and `InProcess*` classes as the scenario's, so this import is
      presented as the tree's. Present since ca1acce60; I missed it in round 2. — fix: change the line to
      `use Ecotone\Messaging\Config\Container\Compiler\CompilerPass;` — skill: framework-orientation O2.
      Deterministic-check candidate (G5): a `use Ecotone\...` line in an eval prompt or a skill's code block whose
      class is neither in packages/ nor declared in the same block could be resolved mechanically, as
      check:agent-skills already does for public skill class references.

## Covered

GL44 — covered — the added ternary case is graded by an eval; no mechanical rule is added.
GL45 — covered — the changed text stays in framework-service-design rule 22 and its reference.
GL46 — covered — rule 22 (reference) and G3's case changed; commit 47455d84c reports 3 runs each after the change,
      origin/main skills 0.50 / 0.33 / 0.50, branch skills 1.00 each, and rule-g3 of review-wiring-names-a-feature
      grades the new point 5. (The full bin/test-agent-skills rerun of framework-service-design's other cases is
      the behaviour session's BH13.)
GL47 — covered — the prompts stay developer tasks that carry their code; the new sentence "take them as given"
      tells the agent the code is declared, it does not quote rule 22 or GL51; the added ternary is not named as
      a mode in the prompt.
GL50 — covered — `docs(reviews): …`, `fix(skills): …`, `test(skills): …` are conventional and scoped; each body
      says what was wrong and what changed.
GL51 — not-apply — the range adds no branch to code a message passes through (no packages/ change). Read against
      rule 22: the new grader point 5 (boot-known extension chosen at container build) matches SKILL.md:112-114
      and rules.md:410-413.

## Not apply

GL1 to GL6 — not-apply — no PHP test added or changed.
GL7 to GL15 — not-apply — no PHP class, constructor, property, method, interface, configuration or call added.
GL16 to GL22 — not-apply — no PHP code, Api/ class, public signature, console option or header added.
GL23 to GL27 — not-apply — no exception, guard or configurable usage added.
GL28 to GL36 — not-apply — no storage, connection or transaction touched.
GL37 — not-apply — no Enterprise feature and no file under src or Api/.
GL38 to GL40 — not-apply — no module or package.
GL41, GL42 — not-apply — no design or spec; docs/reviews files are decided review lists (D4).
GL43 — not-apply — nothing a 1.x application observes changes.
GL48 — not-apply — no PHP code, no baseline change.

## Not on the list

- rules.md:446-448 describes the 779 failure logger as an interceptor "that returns early unless the message's
  channel name is one of its own"; at `ffee52a2e` it proceeds for every message and only logs for its own channel
  names (CoroutineMessageChannelFailureLogger.php:54-58). The per-message ownership check is real, "returns early"
  is loose. Optional wording.
