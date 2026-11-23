NOT PASSED: GL49

Guidelines review — branch dgafka/one-path-review-requirement, head d3ecbf4ca, base origin/main 5ec5f01aa
Reviewer: framework-review-guidelines session (claude-opus-5-5), read only.

## Inventory (G1)

Purpose: on PR #779 the pre-PR reviews passed a channel that ran its handler in place outside a coroutine, an
interceptor that let a dead-letter replay past its refusal on a header any sender can set, and a compiler pass
comparing a channel's class. No requirement spoke about it. The change adds framework-service-design rule 22
(one path per component, a differing flow routed where it starts) with its reasoning, review-list requirement
GL51 citing it, and two eval cases. No packages/ code.

Commits: 0bbcbde40 docs(skills): …, d3ecbf4ca test(skills): … (both read with their bodies).

Changed files, all read in full in the diff:
- .agents/skills/framework-service-design/SKILL.md — Applies-when bullet, rule 22, references line
- .agents/skills/framework-service-design/references/rules.md — section 22 (decision table, ownership test,
  three alternatives, #779 wrong/right pairs, measurement)
- .agents/skills/framework-pull-request/references/review-list.md — GL51
- .agents/skills/framework-review-guidelines/SKILL.md — G2, G3 Verified by name eval:review-scenario-branch
- .agents/evals/framework-service-design/route-replay-at-the-source/{prompt.md, graders/rule-22.md, graders/skill-loaded.md}
- .agents/evals/framework-review-guidelines/review-scenario-branch/{prompt.md, graders/rule-g2.md, graders/rule-g3.md, graders/skill-loaded.md}
Also read: var/one-path-report.txt (implementer evidence, eval scores, PR preview).

Skills whose requirements apply, and why (G2): framework-agent-tooling (A1, A2, A4, A5: the change adds a rule,
edits skills and adds eval cases); framework-orientation O2 (the rule text and eval prompts name framework API);
framework-pull-request P1 (always). framework-service-design 22 read in full to decide GL51 and to check the
review list states it faithfully. All other skills: the change adds no PHP, test, storage, configuration or header.

## Missing

GL49 — missing — .agents/evals/framework-service-design/route-replay-at-the-source/prompt.md:49 and
      graders/rule-22.md:4 — the prompt presents as current Ecotone that "the endpoint that consumes a channel is
      registered in the container under `EndpointRunnerReference` for the channel name and can run one message
      with `runOnce(Message $message)`", and the grader names `runOnce()` as the passing path. Neither holds in
      the tree: `EndpointRunnerReference` is keyed by endpoint id
      (packages/Ecotone/src/Messaging/Config/Container/EndpointRunnerReference.php:10), and `EndpointRunner`
      offers only `runEndpointWithExecutionPollingMetadata()` (packages/Ecotone/src/Messaging/Endpoint/EndpointRunner.php:12);
      no `runOnce` exists anywhere under packages/. The same shape is in
      .agents/evals/framework-review-guidelines/review-scenario-branch/prompt.md:24
      (`…get((string) new EndpointRunnerReference($this->name))->createConsumer()->run()`: the runner has no
      `createConsumer()`); there it is part of the diff under review, so a reviewer is asked to judge code calling
      a method that does not exist. — fix: either give the runner in the prompt as code the prompt declares (an
      interface with `runOnce(Message $message)` shown in the code block, like `InProcessMessageChannel` and
      `BackgroundConsumers` are), or describe the existing API truthfully (the runner under
      `EndpointRunnerReference(<endpointId>)`), and word the grader as "the endpoint that already exists" without
      naming a method the tree lacks; in review-scenario-branch call a method the prompt's own code defines or the
      real runner's method. — skill: framework-orientation O2 (also framework-agent-tooling A5 for keeping the
      prompt self-contained). Deterministic-check candidate (G5), partial: check:agent-skills already resolves
      class references of public skills; extending it to backticked `Class::method` / `->method(` references in
      eval prompts would catch the `createConsumer()` call, not the free-text `runOnce` claim.

## Covered

GL44 — covered — references/rules.md "Why no program decides this" (section 22) and var/one-path-report.txt
      "DETERMINISTIC CHECK": measured on 5ec5f01aa (139 cross-module header reads, nearly all contracts; two of
      the three #779 places read no header), so the rule needs judgement; the narrower getCid-only-in-ExecutionScope
      check was measured, stated stricter than the rule, and declined by the coordinator.
GL45 — covered — the rule sits in .agents/skills/framework-service-design/SKILL.md rule 22, whose new Applies-when
      bullet ("Making one flow behave differently … adding an `if`, `match` or `?:` there") covers it; nothing
      added to AGENTS.md or docs/; the requirement GL51 is added in the same change (A2).
GL46 — covered — graders exist: route-replay-at-the-source/graders/rule-22.md (rule 22),
      review-scenario-branch/graders/rule-g2.md and rule-g3.md (G2, G3 whose Verified by changed); scores reported
      in var/one-path-report.txt: bin/test-agent-skills over framework-pull-request, framework-review-guidelines,
      framework-service-design all ≥ 0.91, new cases 1.00, and 3-run base/head comparison 0.50→1.00 and 0.33→1.00.
      (Running them again is the behaviour session's BH13.)
GL47 — covered — both prompts are developer tasks ("Make the change …", "Write your review report") carrying the
      code in the prompt; neither quotes rule 22, GL51 or "one path". (The API claim inside the prompt is GL49.)
GL49 — the remaining names of the change resolve: ErrorContext::DLQ_MESSAGE_REPLIED / `ecotone.dlq.message_replied`
      and WHOLE_ERROR_CONTEXT (packages/Ecotone/Api/Messaging/ErrorContext.php), MessageHeaders::DELIVERY_DELAY,
      PRIORITY, TIME_TO_LIVE, REPLY_CHANNEL, POLLED_CHANNEL_NAME (Api/Messaging/MessageHeaders.php),
      AggregateMessage::AGGREGATE_ID, `ecotone_retry_number` (DelayedRetryErrorHandler.php:23),
      DeadLetterHandler::replayMessage() with its targetChannel / polled channel / routing-slip choice
      (packages/Dbal/src/Recoverability/DeadLetterHandler.php), MessagingEntrypointService::ENTRYPOINT,
      LicenceDecider::prepareDefinition(), RecordExecutionCompilerPass, `ecotone:deadletter:replay`.
      `CoroutineMessageChannel` and `ConsumedInTheSendingProcess` are not in the tree, but rules.md presents them
      as evidence of PR #779 at named commits (ffee52a2e, 08d8e57bf, 6348dc35e, all present in the object store),
      not as current API. The requirement as a whole is decided missing above.
GL50 — covered — `docs(skills): a component has one path, …` and `test(skills): the guidelines review is graded …`
      match `type(scope): `; both bodies state what #779 let through, why no review caught it, and what changed.
GL51 — not-apply — the change adds no branch to, and no second way of executing in, code a message passes
      through or code that wires it (no packages/ change). Read against rule 22 to check the requirement: GL51
      states the rule's three readings, the part-by-part refusal exemption and the three alternatives faithfully.

## Not apply

GL1 to GL6 — not-apply — the change adds or changes no PHP test.
GL7 to GL15 — not-apply — no PHP class, constructor, property, method, interface, configuration, metadata read,
      orchestrating method or call is added.
GL16 to GL22 — not-apply — no PHP code, Api/ class, public signature, console option or message header is added.
GL23 to GL27 — not-apply — no exception, guard, check or configurable usage is added.
GL28 to GL36 — not-apply — the change reads and writes no storage and touches no connection or transaction.
GL37 — not-apply — no Enterprise feature and no file under src or Api/.
GL38 to GL40 — not-apply — no module or package.
GL41, GL42 — not-apply — no design or spec is added or changed (skills and eval cases only).
GL43 — not-apply — nothing a 1.x application observes changes, and nothing a spec names is renamed.
GL48 — not-apply — no PHP code and no phpstan baseline change.

## Not on the list

- references/rules.md section 22, last paragraph: "`RecordExecutionCompilerPass` compares definition classes in
  eight more places". The file on main has about fifteen such comparisons (`=== X::class`, `in_array(getClassName(), …)`,
  `match (…->getClassName())`; lines 162, 170, 178, 184, 236, 253, 254, 260, 284, 296, 317, 334, 407, 451, 504).
  If "eight" counts a narrower kind (a feature's class rather than the framework's own adapters), say which; else
  drop the number.
- var/one-path-report.txt says the skill's description was changed so it is found for "make one flow behave
  differently"; the diff changes only the Applies-when bullet, not the frontmatter description. The skill-loaded
  grader passes 3 of 3, so discovery works; the report statement (and the PR preview if it repeats it) is inaccurate.
