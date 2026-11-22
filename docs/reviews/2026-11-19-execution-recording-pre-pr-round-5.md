# Fifth pre-PR round: execution recording, maintainer decisions on U4 and U5 (head 963ec0464)

The maintainer decided the two open items of the fourth round.

- W1 (U4, option a). A message that arrives from another application is still a process of its own in the receiving
  application's recording and `sentBy` stays null, but the process carries a readable `sent by` hint naming the other
  application and what it sent (for example "sent by: another application, the command ticket.create"; the sending
  service's name when the message carries it). No new public signature, no merging of recorders. Test through
  userland: two flow-testing applications, a distributed command and a distributed event; the receiving side's
  `lines` show the hint, `sentBy` is null; a message sent inside one application is unchanged. Update the public
  skill and `workflow.md` where they state the limit: the link across applications is this hint, not a `sentBy`.
- W2 (U5, option b). The framework level does not record when only a tracer is configured.
  `FrameworkTracing::recordsExecutionIn()` goes back to requiring a span recorder; with OpenTelemetry alone the level
  gives the build view and nothing at runtime: no recording cost, exported spans identical to the application level,
  no `ecotone.execution.*` attributes. Rework the T1 tests through `EcotoneLite::bootstrap` to prove exactly that
  (names, kinds, statuses, parents, attributes equal; memory flat). Then make every text say it: the `--build`
  refusal, the `ecotone:build-flow:record` refusal if it mentions it, `workflow.md`, row 3h, the public skill's
  OpenTelemetry bullet, `api-reference.md`.
- W3 (report only, do not change). With a span recorder and an OpenTelemetry tracer (flow testing with OpenTelemetry,
  or an application with its own recorder): exported spans still gain `ecotone.execution.order` and
  `process`/`parent`, and `parent` can name a step that is never exported. State what it would take to keep those
  attributes off exported spans while the recorder still gets them, and whether any test or the baseline recorder
  depends on them.
