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
