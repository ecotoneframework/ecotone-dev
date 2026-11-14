# Pre-push review findings: explicit endpoint ids as flow node ids, commit d29104883

Four independent read-only reviews (guidelines, breaking changes, behaviour, developer experience), run as sessions
in the implementation worktree. Two BLOCKING findings (G1, V1). Fix every item test-first where it changes behaviour.

## Guidelines
- G1 (BLOCKING). `upgrade-2.0.md` gained a Before / Now / How to adapt block describing a change between two
  unreleased 2.0 states. The file covers 1.x to 2.0 only. Delete the block and fold the behaviour into the existing
  "Store the message flow as versions and compare them" entry: an explicit `endpointId` identifies the node
  (`handler:{endpointId}`, `scheduled:{endpointId}`), so a rename is one changed node; without one it is one removal
  and one addition; adding or changing an `endpointId` is itself one removal and one addition at the next compare.
  Drop the sentence about constructing Pulse links.
- G2 (should fix). Commits 114caef99 and 1f9035727 change ids while the existing assertions are updated only in
  16f86a7dc, so the branch is not bisectable. Each commit must leave the Flow suite green.
- G3 (should fix). All ten commits have empty bodies. Each body says what was wrong, why, and what changed.
- G4 (nit). `MessageFlowDistributedTest` pastes the same node lookup twelve times; one private helper.
- G5 (nit). `MessageFlowChannelTest.php:373` picks the expected id with a ternary inside the assertion.
- G6 (nit). `MessageFlowDiscovery.php:459` and `:479` duplicate the generated-or-explicit id expression; one
  named private method.
- G7 (nit). `apps/Pulse/README.md:207` runs past the wrap width.

## Breaking changes
- B1 (should fix). Same as G1.
- B2 (nit). A new Pulse reading a service on an earlier beta highlights nothing for the other endpoints of a
  shared method. No action.
- B3 (nit). Saved Pulse links with the old id stop resolving. No action.
- B4 (nit). Ship in the same beta as the base PR, so no released version stores the old ids. No action here.

## Behaviour
- V1 (BLOCKING). On a method with several handler attributes the trace records the wrong endpoint:
  `TracingModule.php:86` keeps one endpoint id per `class::method` (the last attribute wins) and
  `TracingInterceptor.php:244` prefers it over the attribute that ran. Sending the FIRST attribute's message makes
  Pulse highlight the second endpoint's node and conformance report no path. The two new tests send only the last
  attribute and pass by coincidence. The mixed case (an explicit CommandHandler plus a generated EventHandler on
  one method) lights the event-handler node for a command trace. Record the endpoint that actually ran; add
  scenarios sending the first attribute and the mixed case, in the conformance scenarios and `TraceOnFlowGraphTest`.
- V2 (should fix). An aggregate's static factory and its instance action may share a routing key and the same
  explicit `endpointId`; bootstrap accepts it, and the graph merges both methods into one node, losing the
  factory's inferred edges. Coordinator's decision: when one explicit endpoint id is declared on more than one
  method, those nodes keep their class-and-method ids. Add a test.
  Withdrawn: bootstrap rejects a shared explicit endpoint id, in BusRoutingMapBuilder for the same routing key and MessagingSystemConfiguration for different keys.
- V3 (should fix). "Unchanged arrows" is proved only on a bare handler. Add one rename fixture whose handler is
  asynchronous, intercepted, has an inferred emits edge, and is an aggregate or saga event handler, and assert no
  edge is added or removed.
- V4 (nit). An explicit endpoint id equal to another handler's `Class::method` merges with that node. No action.
- V5 (nit). `MessageFlowDistributedTest` lookups take `[0]` of the matches and never assert the id.
- V6 (nit). Same as G1.
- V7 (nit). The `returnsEvents` loop now marks every node of that class and method. No action.

## Developer experience
- D1 (should fix). Same as G1.
- D2 (nit). Same as G1 (the Pulse link sentence).
- D3 (nit). Say in the upgrade entry and the Pulse README that adding or changing an `endpointId` shows one
  removal and one addition at the first compare.
- D4 (nit). `api/skills/ecotone-handler/SKILL.md` "EndpointId Rules" does not name the stable identity in the flow
  graph and version comparison as a reason to declare one. Add one bullet.

## Second round, on the fixes (head a862a0862), from the resumed sessions
G1-G7, B1, V1, V3, V5 and D1-D4 are confirmed resolved.

- V8 (BLOCKING, reasoned from code, not run). Aggregates and sagas that pair a factory and an action on one
  routing key probably fail once tracing is on. `AggregateModule::registerRoutedFactoryHandler`
  (`AggregateModule.php:640-660`) builds the factory and action processors as plain
  `ChainedMessageProcessorBuilder`s with no endpoint annotations, yet they are intercepted. The tracing pointcut
  still matches (the handler attribute is on the method), the new `EndpointId $endpoint` parameter of
  `TracingInterceptor` is found in neither endpoint nor method annotations, and `AroundInterceptorBuilder.php:181`
  falls back to the payload. Even without a crash the action would record the factory's endpoint id. Reproduce
  first, with tracing on: (a) `#[CommandHandler('order.place')]` on a static factory and on an instance action,
  with generated endpoint ids; (b) the same pair with two different explicit endpoint ids, if bootstrap accepts
  it; send the command twice. Expected: no failure, and the two spans carry the factory's and then the action's
  endpoint id. If the test is green as written, report that and V8 is withdrawn; if red, fix and keep the test.
  Add the scenario to the modelling flow scenarios so tracing, OpenTelemetry and both DBAL flow tracking stores
  run it.
- V9 (should fix). A method carrying both a command and an event handler attribute produced two process spans per
  call and now produces one. If a 1.x application with tracing would have seen two spans, say so in the tracing
  part of `upgrade-2.0.md` (Before / Now / How to adapt); if the double span only ever existed in 2.0 betas, add
  nothing and say so in the report.
- G8 (should fix). `api/skills/ecotone-handler/SKILL.md` gained a rule line but no grader. Add a grader for it
  under `api/evals/ecotone-handler/` in the form the other public evals use; the coordinator runs the eval.
- G9 (nit). `MessageFlowDistributedTest.php:331`: hoist the inline method-to-endpoint array into a named local.
- G10 (nit). `MessageFlowDistributedTest.php:853`: blank line before the closing brace; run php-cs-fixer.
- V10 (nit). Copies of a builder's annotations can keep an outdated `EndpointId` entry. No action.
- B5, B6 (nit). Old stored traces differ from new ones for multi-attribute methods. No action.

## Third round, on the second fixes (head 26895a43c), from the resumed sessions
V8, V9, G8, G9 and G10 are confirmed resolved; no session reports a BLOCKING or SHOULD-FIX finding in the change.

- B7 (nit, fix). `upgrade-2.0.md:3555` names only a command plus an event handler attribute; in 1.x any two
  different handler kinds on one method (command, query, event, internal) produced nested spans. Say "handler
  attributes of more than one kind".
- B8 (nit, fix). The same note uses the 2.0 span name `process <Class::method>` before the section that
  introduces it ("Tracing is part of Core"). Move the note after that section.
- G13 (nit, fix; raised by the guidelines session for the DX one). Rule 6 of `api/skills/ecotone-handler/SKILL.md`
  points to stored flow version comparison, an Enterprise feature, without the skill's Enterprise notice. Say it is
  Enterprise in the form the skill already uses.
- V11 (should fix, not in this change). `bin/test-agent-skills` cannot evaluate a public skill with the current
  `claude` CLI (it symlinks `api/evals` into the temporary plugin, which the CLI refuses) and still exits 0. The
  behaviour session evaluated a copied plugin instead: order-handlers 1.00, stable-flow-identity 1.00 (rule-6 on a
  2 to 1 vote). Left for a separate change to the script.
- G11, G12, V10, V12 (nit). No action.
