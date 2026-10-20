# Review: implement-pulse-responder (590c80be1..3bf4a369f) — report-only, tests not executed (read-only review)

## Blocking
None found.

## Should-fix
1. Contribution seam gives action handlers no usable way to reply with a full snapshot (PulseSnapshot.php:41, PulseModule.php:88). Only public entry is PulseSnapshot::snapshotJson(?string $sourceService), a service that exists only when PulseConfiguration is present. Dbal registering replay/delete handlers unconditionally gets an unresolvable Reference(PulseSnapshot::class) in apps without Pulse; and each action must re-do the source check itself (no shared authorization for actions). Expose a snapshot-for-action entry that applies the same source check, and define how Dbal registers handlers only when Pulse is configured (e.g. a registration object carrying handlers, consumed by PulseModule).
2. Section names unguarded (PulseSnapshot.php:76-78). `$snapshot[$section->sectionName()] = ...` silently overwrites core keys (schemaVersion, overview, flowGraph, topology, serviceName, takenAt) and duplicate sections (rule 1d). Refuse at bootstrap (reserved/duplicate names) with a message test.
3. Asynchronous-receiver reply routing is not actually proved. Both tests run the same queue-channel path; the async test only asserts topology JSON. Add a case where the Pulse service's reply handler runs async/with asynchronousChannel set and assert the reply lands on ecotone.pulse.snapshot_received (rule 10a). Also not covered: the same routing key reached by local CommandBus::sendWithRouting (no source header -> must be refused), and a request with a refused source leaves the intruder channel/consumer failing.
4. Refused request throws inside the target's consumer (PulseSnapshot.php:99). On a real queue a hostile/misconfigured sender makes the service's own distributed channel fail/retry the message repeatedly (poison message). Decide explicitly (drop+log vs retry) and test; at least note in report to coordinator.
5. No upgrade-2.0.md / docs entry for new Api class Ecotone\Api\Pulse\PulseConfiguration; brief required it.
6. ConsoleInvocationResolver::resolveFramework (ConsoleInvocationResolver.php:31) — wrong home: class is about console prefixes; it returns package-name strings reused as the public JSON contract ("symfony"/"laravel"/"tempest"/"lite"), so renaming a package constant changes the schema. Only 'lite' is tested (enabledModules: []). Move to a small dedicated resolver with an explicit mapping and test the other three, or confirm isPackageActive (class_exists of module classes + enabled flag) cannot report 'symfony' for a Laravel app with symfony classes installed.

## Nit
- PulseModule.php:35 licence docblock sits between #[ModuleAnnotation] and the class; inline `/** @var X[] */` docblocks (PulseModule.php:48,76) are not shapes/@link (rule 6). Copy the placement used by existing Enterprise modules.
- topologyOf takes the *last* Asynchronous annotation silently (PulseModule.php:140-144); two withAsynchronousChannel calls is a rule 1d ambiguity.
- licence.enterprise hard-coded true / expiresAt always null — acceptable per brief; contract test pins it.
- ecotoneVersion via getPrettyVersion may be "dev-main"; fine, note for Pulse consumer.
- Tests carry the full multi-line Enterprise notice and @internal docblock (rule 6/6b: src keeps full notice; tests need none) — check what sibling Enterprise tests do.
- Source service identity is the sender's self-declared header (FYI): any service on the broker named as the Pulse service passes; inherent to the design.

## Conformance summary
- Rules 2/2a/3: PulseSnapshot takes required deps, no mutable state. OK.
- 1/1a: all four bootstrap refusals + runtime refusal have message tests naming the way out. OK. Ordering: licence check runs after duplicate check — fine.
- 13a: topology derived from DistributedServiceMap definitions, no fresh reflection. OK.
- 10/11: EcotoneLite, inline anonymous fixtures, snake_case, no assertion messages. OK; no internal classes named. Seam (sections/registration) untested in core — expected per coordinator decision, relies on Dbal unit.
- 17: final + strict_types on all new files. OK.
- v1 JSON fields all produced and asserted: schemaVersion, serviceName, takenAt (pinned), overview.{ecotoneVersion (loose assertion), phpVersion, framework, licence}, flowGraph (nodes/edges + a handler label), topology.serviceMaps incl. eventSubscriptions (exclude only; include not asserted) and asynchronousChannel.
- TDD evidence: cannot be seen from a single squashed commit; RED-first not verifiable from the diff.

Uncertainty: nothing was executed; HEAD was 3bf4a369f at review time.
