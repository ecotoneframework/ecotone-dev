## 20. Mechanics php-cs-fixer enforces

Run `vendor/bin/php-cs-fixer fix` **on the host, never inside the container** — `git` is not available there, so
the fixer loses its file filter and rewrites the whole repository. Config: `.php-cs-fixer.dist.php`.

| Rule | Shape |
|---|---|
| `php_unit_method_casing` | `public function test_it_does_the_thing(): void` |
| `not_operator_with_successor_space` | `! $var`, never `!$var` |
| `single_quote` | `'string'` unless interpolating |
| `trailing_comma_in_multiline` | last element of a multiline array or argument list |
| `global_namespace_import` | `use function sprintf;` at the top, then bare `sprintf(...)` — not `\sprintf(...)` |
| `ordered_imports`, `no_unused_imports` | alphabetical, none spare |
| `php_unit_internal_class` | `@internal` on test classes |
| `array_indentation`, `no_empty_phpdoc`, `no_useless_return` | |

It does not add licence headers; `bin/add-apache-licence.php` does.

---

## 21. Gates and landmines

**Gates.**

- `bin/check-licence.php` runs on every push and pull request (`.github/workflows/file-licence.yml`) over
  `packages/*/src`. `Api/` files all carry headers too, though the script does not yet check them.
- `phpstan` is **level 3, over a baseline**, in the root config and in every package's own. The root covers `src`,
  every package's `Api/`, and `Monorepo` — not `tests/`, and not the `src` of the `Tempest`, `Redis`, `Sqs` or
  `DataProtection` packages, which only their own configs analyse. Level 3 is the first level that checks return
  types: it reports a caller declaring a subclass that receives the parent from an inherited `: self` method, the
  defect level 1 let through, though only where such a caller exists — never at the `: self` declaration, so that
  defect still needs a caller-side test. Each config's `phpstan-baseline.neon` holds the errors that were already in
  the tree; it shrinks as they are fixed and never grows — fix a new error, do not regenerate the baseline over it.
  phpstan will not catch a wrong class in an attribute argument, so a green run does not prove a rename or a move is
  complete. [dev-workflow.md](workflow.md#static-analysis-licence-headers-code-style) has the exact path
  list.
- `composer tests:ci` **at the root** = phpstan, then `packages/DataProtection/tests/before-tests.sh` (it generates
  a 200 MB fixture), then phpunit, then the quickstart examples. Four packages have their own additions to it;
  [dev-workflow.md](workflow.md#what-composer-testsci-runs) lists them.

**Landmines.**

- Run tests **inside the container, one package at a time, never in parallel** — the packages share the compose
  services and a parallel run produces false failures.
- Do not `composer install` or run tests with `-u root`: it leaves `vendor/` root-owned on the host.
- The monorepo **root** `vendor/` is always loaded, even for a single package's run, because Ecotone's annotation
  finder requires the root `vendor/autoload.php`. Keep it installed and in sync.
- `vendor/bin/phpunit --no-coverage` for a single file; a plain run trips PHPUnit 12's coverage requirement.
- `Monorepo/*/Symfony/config/reference.php` is gitignored and untracked — it regenerates on every Symfony run.
- Conventional commit subjects: `feat(scope):`, `fix(scope):`, `refactor(scope):`, `test(scope):`, `docs(scope):`,
  `style(scope):`. One increment per commit.

---

