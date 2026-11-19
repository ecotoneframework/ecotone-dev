# Fourth pre-PR review round: execution recording (Behaviour at 97d64f5b0)

A fresh Behaviour session ran T1 to T12: parity of outcome and exported spans at both levels on 12 running
application flows with OpenTelemetry and on 94/94 catalogue scenarios, flat memory over 2,000 to 3,000 messages in
every mode, T2 held. Five items remain.

- U1 (blocking until explained). `ecotone-testing/ticket-lifecycle` scored 0.67, then 0.67 / 0.67 / 0.83 with
  `RUNS=3`, while the answers read as passing. Run the same case with `RUNS=3` against `main` and report both scores
  with the failing graders. If main scores the same, record it as not this branch's; if main passes, find what in the
  added section lowers it and fix that. A grader is edited only with exactly why its criteria were ambiguous.
- U2. `RecordedAcknowledgeConfirmationInterceptor`: on a manually acknowledged channel whose handler throws, two
  acknowledge steps are recorded. Record "left to the handler" only when nothing acknowledged during the interceptor.
- U3. `FrameworkTracingLevelTest` memory test measures queue backlog and passes without the fix. Drain fully and
  assert each level's own growth stays near zero.
- U4. Since T2 a distributed hop between two flow-testing applications is no longer a sub-flow. Report the options
  with their cost, what the #771 and catalogue baselines do for distributed scenarios, and say the limit plainly in
  the public skill and `workflow.md` until decided.
- U5. The `--build` refusal says the steps arrive as `ecotone.execution.*` attributes on exported spans; they do not.
  Say what does happen. Report for the maintainer: `ecotone.execution.parent` with OpenTelemetry only, what exporting
  the steps as child spans would take, and what not recording with only a tracer would take. Build neither.
