# The flow scenario catalogue

The catalogue is `Ecotone\Test\Flow\FlowScenarioCatalogue::all()`, the `all()` of every
`packages/Ecotone/src/Test/Flow/*FlowScenarios.php` class. Three things run every scenario:

- the tracing conformance tests (`TracingFlowConformanceTest`, `OpenTelemetryFlowConformanceTest`) assert the spans
  each stage expects;
- `php bin/build-flow.php snapshot` records each scenario into the build-flow baseline
  (`packages/Ecotone/tests/BuildFlow/baseline`), which main's CI regenerates after every merge;
- the pull request's flow comparison runs each scenario on the base commit and on the merge, and comments what
  changed.

A flow no scenario runs is invisible to all three: its change shows nowhere in the comment.

## Adding a scenario

1. Pick the class whose subject fits (`AsynchronousFlowScenarios`, `RoutingFlowScenarios`, `InterceptorFlowScenarios`,
   …), or add a `<Subject>FlowScenarios` class and put `...<Subject>FlowScenarios::all()` in `FlowScenarioCatalogue::all()`.
2. Add a `private static function <whatItShows>(): FlowScenario` that builds the application inline (anonymous
   handler classes, as in a test) and returns
   `new FlowScenario(name: '…', classesToResolve: […], services: […], extensionObjects: […], stages: […])`.
3. Each stage is `FlowStage::when(fn (FlowTestSupport $ecotone, array $metadata) => …)` sending the message (pass
   `$metadata` on), then `->expect(ExpectedSpan::named('…')->containing(…))` and `->withNoOtherSpans()`. Use
   `->failingWith('…')` for a stage that throws and `->leavingWorkPending()` when a queue is left for a later stage.
4. List the method in the class's `all()`. `php bin/check-flow-catalogue.php` refuses a method that is not listed, a
   class the catalogue does not include, and a name used twice (each name becomes its baseline's file name).
5. Run the conformance tests and `docker compose exec -T app php bin/build-flow.php compare --presentation=build`: the
   new scenario shows as `runs only now`.
6. Leave `packages/Ecotone/tests/BuildFlow/baseline` alone. main's CI commits the scenario's baseline after the merge.

## What cannot be a scenario

A scenario runs in the `app` container in one process, with in-memory or DBAL storage. A feature that needs real
coroutines (OpenSwoole, Swoole), an external broker, or a second process is tested where it can run, and the pull
request says why it has no scenario.
