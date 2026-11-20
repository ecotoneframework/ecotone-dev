# Last fix list: execution recording (re-reviewed at dc6b151f6)

Fresh Guidelines, Developer experience and Behaviour sessions re-reviewed: no blocking finding, every earlier G, X and V
item resolved or recorded. These remain; no redesign.

## Should fix

- F1. `ecotone.execution.label` and `ecotone.execution.application_parent` get `SpanAttribute::EXECUTION_LABEL` and
  `EXECUTION_APPLICATION_PARENT` under `Api/`, used everywhere (Dbal's `StoringSpanRecorder` no longer imports
  `RecordedExecution`) and named in the skill; `ecotone.execution.sent_from_another_application` gets a constant and a
  line in the skill, or stays off exported spans.
- F2. The `--build` refusal: short sentences, `#[Environment([...])]` naming the application's development and test
  environments (Symfony dev, test; Laravel local, testing), keep the level out of production.
- F3. The comment's opening names the base sha, says the pull request side is the merge into its base, says what was
  compared in the fallback, links the glossary at the head sha, gives the container form of the local command with its
  note, says to leave the baseline alone, and fixes "none of the the" and "the 1 catalogue scenarios".
- F4. The glossary adds the terms the comment uses and lacks, and the time the base-commit snapshot adds to the job.
- F5. Snapshot refusals name `snapshot --location=<that directory>`; `formatVersion` is checked on read and refused with
  what happened, and the comparison job posts that a comparison across a format change cannot be drawn.
- F6. `.gitignore` covers the default `var/build-flow-snapshot`.
- F7. `MultiTenantFrameworkTracingLevelTest` uses `bootstrapFlowTesting`, or names why it needs the real boot and keeps a
  flow-testing case beside it.
- F8. The skill and row 3h say a framework-level flow test always traces (`CurrentTrace::tracingId()` returns an id).

## Nits

- F9. Prose docblocks and comments in new code and tests removed.
- F10. Named arguments in `RecordedGatheredGroupStore::completePart(...)`; one generated-id regex in `RecordedExecution`;
  `MessageFlowChainReader::classDeclaring()` named for what it returns and reading the definition like the rest of the
  file; no redundant `assert()` in `RecordedMessagingEntrypointService`.
- F11. G7's other half: the order of `partsOf` pinned in the shared suite, or stated here as not observable from userland.
- F12. Skill wording: the `{generated}` sentence in its own bullet with "give the endpoint an `endpointId`", and plain
  words for "a failed step's ancestors".
