NOT PASSED: GL49

Guidelines review, re-check — branch dgafka/one-path-review-requirement, head ca1acce60, base origin/main 5ec5f01aa
Reviewer: framework-review-guidelines session (claude-opus-5-5), read only.
Round 1 (d3ecbf4ca) is recorded in docs/reviews/2026-11-22-one-path-review-list-1-guidelines.md.

## Inventory of the fix range d3ecbf4ca..ca1acce60 (G1)

- d9f6506f5 docs(reviews): the four decided lists of round 1 under docs/reviews/.
- 41414d694 fix(skills) (GL49): route-replay-at-the-source/prompt.md now declares `InProcessEndpoint::handle()` of
  the feature under change, and graders/rule-22.md names it; review-scenario-branch/prompt.md calls
  `$this->consumers->runNow()` and describes `BackgroundConsumers` / `EventLoop`; its grader names `runNow()`.
- 9d29ac08c docs(skills): rule 22 (SKILL.md and references/rules.md) splits "configuration known at boot" into a
  boot-known *mode* (resolved at container build) and the component's *own setting* (may be branched on), adds
  the pool-wait example, says what "registered for that flow only" and "chosen at container build" mean, drops
  the wrong count; skill description names the rule; GL51 carries the new wording.
- ca1acce60 test(skills) (BH2): new case framework-review-guidelines/review-wiring-names-a-feature (prompt,
  rule-g3, skill-loaded); rule G3 names it.
All changed files read in full; var/one-path-report.txt ROUND 2 and var/eval-head-2.log read.

## Round 1 findings

- GL49 (runOnce / createConsumer on the runner under EndpointRunnerReference): resolved. No prompt or grader names
  `runOnce`, `createConsumer` or `EndpointRunnerReference` any more; the endpoint is declared in the prompt's code.
- Not on the list, "eight more places": resolved, the count is dropped (rules.md:528).
- Not on the list, description not changed: resolved, SKILL.md frontmatter now names the rule.

## Missing

GL49 — missing — .agents/skills/framework-service-design/references/rules.md:417-424 — the new covered example
      shows `ConnectionPool` branching on `$this->connectionPoolWaitSeconds` (documented "0 fails at once",
      `pop($this->connectionPoolWaitSeconds)`) and says "this branch of `ConnectionPool`" as if it were the tree's.
      On main, packages/Dbal/src/Connection/ConnectionPool.php has no such property or setting: it waits a constant
      `self::SECONDS_WAITING_FOR_RETURNED_CONNECTION` (:150, :156) and has no `<= 0.0` guard; the configurable wait
      exists only on PR #779's branch. A skill reference is what agents load, so an agent is taught a
      `ConnectionPool` member and setting the tree lacks — the same shape as round 1's finding. The rest of the
      #779 evidence in this section is attributed ("pull request 779 at `ffee52a2e`", `08d8e57bf`, `6348dc35e`);
      this example is not. — fix: attribute it like the others ("`ConnectionPool` on the coroutine channel branch,
      pull request 779 at `<commit>`"), or move it into the "Evidence: the coroutine message channel" subsection.
      Same kind, smaller, in .agents/evals/framework-review-guidelines/review-wiring-names-a-feature/prompt.md:57
      with :19-:32: the prompt says "Every class in the diff exists on main", and two of them are real classes
      shown with a "before" the tree does not have — `RecordExecutionCompilerPass::recordingOf(...)` does not exist
      (the tree builds the `ChannelSendRecording` Definition inline, RecordExecutionCompilerPass.php:165-171), and
      `ChannelSendRecording::__construct(string $channelName, Tracer $tracer)` is in the tree
      `(string $channelName, array $interceptors, array $declaredPhases, bool $onlyFlowTestingIntercepts,
      bool $queued, ExecutionRecordingTracer $tracer)` (ChannelSendRecording.php:44-51). Fix: say the excerpt is
      shortened (as route-replay-at-the-source does for `DeadLetterHandler`), or give the recording classes
      feature-local names as the `InProcess*` ones are. — skill: framework-orientation O2. Not a deterministic-check
      candidate (G5): the names exist; only their members are invented, in prose and in diff context.

## Covered

GL44 — covered — rules.md "Why no program decides this" unchanged in substance; the mode/setting split is a
      judgement (what the arms of a branch select), stated as such in rules.md:406-414.
GL45 — covered — the added text sits in framework-service-design rule 22 and its reference; GL51 updated in the
      same change (A2).
GL46 — covered — rule 22 changed: route-replay-at-the-source graded 1.00 over 3 runs on the branch's skills
      (var/eval-head-2.log), 0.50 without; G3 changed: review-scenario-branch 1.00 and new
      review-wiring-names-a-feature 1.00 (3 runs each), the latter 0.50 without; grader files exist
      (review-wiring-names-a-feature/graders/rule-g3.md). The full bin/test-agent-skills run left add-dependency,
      keep-ambient-state and route-replay-at-the-source ungraded on a usage limit (report ROUND 2); those other
      cases of the changed skill are the behaviour session's BH13.
GL47 — covered — review-wiring-names-a-feature/prompt.md is a reviewer task carrying its diff, names no rule; the
      two edited prompts stay developer tasks and now declare the code they rely on.
GL50 — covered — `docs(reviews): …`, `fix(skills): …`, `docs(skills): …`, `test(skills): …` match `type(scope): `;
      each body says what was wrong, why, and what changed.
GL51 — not-apply — the range adds no branch to code a message passes through (no packages/ change). Read against
      rule 22: GL51's new wording ("a mode known at boot", "a setting it was built with that every message meets
      the same way") matches SKILL.md:112-118 and rules.md:396-397.

## Not apply

GL1 to GL6 — not-apply — no PHP test added or changed.
GL7 to GL15 — not-apply — no PHP class, constructor, property, method, interface, configuration or call added.
GL16 to GL22 — not-apply — no PHP code, Api/ class, public signature, console option or header added.
GL23 to GL27 — not-apply — no exception, guard or configurable usage added.
GL28 to GL36 — not-apply — no storage, connection or transaction touched.
GL37 — not-apply — no Enterprise feature and no file under src or Api/.
GL38 to GL40 — not-apply — no module or package.
GL41, GL42 — not-apply — no design or spec; docs/reviews files are decided review lists (D4), not designs.
GL43 — not-apply — nothing a 1.x application observes changes.
GL48 — not-apply — no PHP code, no baseline change.

## Not on the list

- framework-service-design/SKILL.md:126: the reflowed paragraph leaves one line far past the file's wrap width ("asks a contract it
  defines instead (an interface, an attribute), which the other side declares. In all three the other path already
  exists:"). Cosmetic.
- review-scenario-branch/prompt.md:46 and review-wiring-names-a-feature/prompt.md:57 say the fictional
  `InProcess*`, `BackgroundConsumers` and `EventLoop` classes "exist on main". As round 1, read as the scenario's
  main inside a self-contained eval (A5), not as a GL49 claim about the tree.
