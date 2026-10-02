# Rule 11 — named fixtures, triaged class by class

The evidence behind the rule 11 section of `docs/conventions-outstanding.md`. Measured on base `a57882174`.

| File | What it is |
|---|---|
| `classify.php` | Tokenizes each test file and lists every top-level named `class`/`interface`/`enum`/`trait` that does not extend a `TestCase`, with every attribute on the class and on its members. Attributes are collected by token, not by indentation, so a handler whose attributes sit on its methods is seen |
| `triage.php` | For each named class carrying an attribute other than a message-type one (`EventTag`, `NamedEvent`, `TargetIdentifier`, `Revision`, `TargetVersion`), classifies every use of its name in the file: `type` (parameter, property, return, nullable or union type), `attr-arg` (inside `#[...]`), `const-expr` (in a `const`), `extends`/`implements`, or runtime (`new`, `::class`, `::CONST`). **FORCED** when a type, attribute-argument, constant-expression or parent use exists, or when it is not a class; **FREE** otherwise |
| `xref.php` | For each named class, every other PHP file under `packages/` that references it **by fully-qualified name** — a `use` import or the FQCN string — or by short name from the **same namespace**. A short-name match from another namespace is a different class and is not counted |
| `triage-a57882174.txt` | `triage.php`'s output on the base: `*` marks a file with at least one FREE class |
| `nul-byte-experiment.php.txt` | The throwaway test behind the proposed third exception. Copy it to `packages/PdoEventSourcing/tests/Integration/NulByteExperimentTest.php` to rerun; it was never committed as a test |

Reproduce, from the repository root (`git` runs on the host, PHP in the container):

```bash
git diff --diff-filter=A --name-only c440b6d4c...HEAD -- 'packages/*/tests/*Test.php' > added.txt
docker compose exec -T app php docs/superpowers/research/rule-11-named-fixtures/classify.php added.txt > named.json
docker compose exec -T app php docs/superpowers/research/rule-11-named-fixtures/triage.php named.json > triage.txt
awk '/packages/{f=$NF} /^  FREE/{print f, $2}' triage.txt > free.txt
docker compose exec -T app php -d memory_limit=2G docs/superpowers/research/rule-11-named-fixtures/xref.php named.json free.txt
```

Both checks err towards the cautious answer. `triage.php` calls a class FORCED on any typed use, even in a method no
test calls, so FREE is a candidate, not a proof — runtime limits were checked only for the classes actually
converted. `xref.php` counts any same-namespace word match, so it over-reports: its three hits on the base were all
false — the string `'Wallet'` in `#[AggregateType('Wallet')]` (`AggregateBoundaryConfigurationTest`), and
`AddItemToBasket`/`ItemWasAddedToBasket` in `StatefulEventSourcedWorkflowWithMultipleAggregatesTest`, which imports
same-named classes from `Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\Common`.
