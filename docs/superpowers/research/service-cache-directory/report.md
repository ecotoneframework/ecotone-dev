# `service-cache-directory` — replacing `ServiceConfiguration::withCacheDirectoryPath()`

> **Revision note (round 1 challenge response).** The maintainer's review found a factual error (§1/§2 below,
> C1), a genuine architectural flaw in the round-1 recommendation that the report itself had surfaced but not
> resolved (C2), a contradiction between round-1's "Option C" and a docblock the report had already quoted
> (C5), and a real gap around cache-clearing when the cache is corrupt, not just stale (C6). This revision
> **reverses the round-1 recommendation**: instead of renaming `ServiceCacheConfiguration` → `ServiceCacheDirectory`
> and hanging it off `ServiceConfiguration`, the cache directory is removed from `ServiceConfiguration`
> entirely. Every section below reflects that change; disagreements with round 1 are called out explicitly
> where it matters so the reasoning is auditable.

## 1. Problem

`packages/Ecotone/src/Messaging/Config/ServiceConfiguration.php:337-350`:

```php
/**
 * @deprecated use ServiceCacheDirectory
 */
public function getCacheDirectoryPath(): string
{
    return $this->cacheDirectoryPath;
}
public function withCacheDirectoryPath(string $cacheDirectoryPath): self
{
    $clone                     = clone $this;
    $clone->cacheDirectoryPath = rtrim($cacheDirectoryPath, '/');

    return $clone;
}
```

`ServiceCacheDirectory` **does not exist anywhere in the repo** (verified: `rg "ServiceCacheDirectory" packages/` returns zero hits outside this deprecation comment and the release-design spec that flags it).

A different, non-deprecated class already does almost the whole job: `Ecotone\Messaging\Config\ServiceCacheConfiguration` (`packages/Ecotone/src/Messaging/Config/ServiceCacheConfiguration.php`), a `DefinedObject` value object holding `(string $path, bool $shouldUseCache)`, registered in every container under `ServiceCacheConfiguration::REFERENCE_NAME`.

**Round 1 said `ServiceConfiguration::getCacheDirectoryPath()` has exactly one production reader. That was wrong — there are two, and they behave differently. Correcting this here because it changes the whole analysis (§2, §5).**

1. `EcotoneLite::prepareConfiguration()` (`packages/Ecotone/src/Lite/EcotoneLite.php:171`) — the path genuinely drives where the container gets cached.
2. `InMemoryReferenceSearchService::__construct()` (`packages/Ecotone/src/Messaging/Handler/InMemoryReferenceSearchService.php:43-48`):
   ```php
   if (! array_key_exists(ServiceCacheConfiguration::REFERENCE_NAME, $objectsToResolve) && ! self::hasInOriginalReferenceService(ServiceCacheConfiguration::REFERENCE_NAME, $referenceSearchService)) {
       $objectsToResolve[ServiceCacheConfiguration::REFERENCE_NAME] = new ServiceCacheConfiguration(
           $serviceConfiguration->getCacheDirectoryPath(),
           false
       );
   }
   ```
   I read this fully. It fires whenever an `InMemoryReferenceSearchService` is built (`createWith()`, `createEmpty()`, `createWithContainer()`) and nobody already registered a `ServiceCacheConfiguration` reference. **`$shouldUseCache` is hard-coded `false` on this line — always.** So the path value is inert: no file is ever written through this instance, regardless of what `getCacheDirectoryPath()` returns. I confirmed this by tracing every caller: `rg "InMemoryReferenceSearchService::create" packages --include='*.php'` finds exactly 6 files, and every one of them is a test (`packages/Ecotone/tests/Messaging/Unit/Handler/SymfonyExpressionEvaluationAdapterTest.php`, `.../Transformer/EnricherBuilderTest.php`, `.../Config/Annotation/ModuleConfiguration/RequiredConsumersModuleTest.php`, `.../Config/MessagingSystemConfigurationTest.php`, `.../Channel/DynamicChannel/DynamicMessageChannelBuilderTest.php`, `packages/PdoEventSourcing/tests/EventSourcingMessagingTestCase.php`). Zero non-test callers. So: **does my plan break it? No — it eliminates the reader.** Since the value is provably unused, this call site doesn't need `getCacheDirectoryPath()` (or any replacement of it) at all; it can be rewritten as `ServiceCacheConfiguration::noCache()`, which returns the exact same semantics (`sys_get_temp_dir()`, `false`) it produces today via the indirect route. See §9 task 2.

Separately — and round 1's inventory conflated this — **`Ecotone\Laravel\EcotoneProvider::getCacheDirectoryPath()` (`packages/Laravel/src/EcotoneProvider.php:192-195`) is a different method on a different class that happens to share a name:**

```php
public static function getCacheDirectoryPath(): string
{
    return App::storagePath() . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'data';
}
```

It computes Laravel's `storage_path()`-based convention directory and is called at lines 50, 192 (declaration) and 248, plus from 6 test files. **This method is not deprecated, is not part of `ServiceConfiguration`, and nothing in this report's plan touches it.** The rename/removal work below only ever targets `ServiceConfiguration::with/getCacheDirectoryPath()` and the `ServiceCacheConfiguration` class — never `EcotoneProvider::getCacheDirectoryPath()`. Stated explicitly in §9 so the implementer doesn't grep-and-replace across the wrong symbol.

## 2. The central design flaw (round 1 identified it, didn't resolve it — resolved here)

Round 1 correctly observed but left open: Symfony's `EcotoneExtension::load()` (`packages/Symfony/DependencyInjection/EcotoneExtension.php:87-91,104-105`) builds `ServiceCacheConfiguration` from `%kernel.build_dir%/ecotone` directly and **never reads `ServiceConfiguration`'s cache field at all** — it isn't even set. Laravel (`EcotoneProvider.php:63`) and Tempest (`MessagingSystemInitializer.php:128`) both call `->withCacheDirectoryPath($cacheDirectory)` on the `ServiceConfiguration`, but `prepareFromCache()`/`buildServiceConfiguration()` never read that value back — they use the separately-passed `$cacheDirectory` local variable. In all three frameworks, if a user wrote `ServiceConfiguration::createWithDefaults()->withCacheDirectory(...)` (round 1's proposed API), **the call would silently do nothing.** That is exactly the DX trap rule 2 (smart defaults with *working* explicit overrides) forbids.

The task gave three options. Evaluating each against what I now know from §1 and §5 (below):

- **(a) Make `ServiceConfiguration::getCacheDirectory()` authoritative; have each integration seed the default into it and read it back.** This requires the value to survive being cloned/merged through `ServiceConfiguration::mergeWith()` and to be excluded from the config-hash serialization (§5) — otherwise a Symfony deploy where `%kernel.build_dir%` legitimately differs between build and runtime machines invalidates the cache every time, defeating the whole point of a "runtime-resolved, not baked into the dump" cache path that `ServiceCacheConfiguration`'s own docblock says it exists to guarantee (quoted in full in §5). Doable via a `__sleep()`/dedicated hash-exclusion, but that's a special-cased carve-out bolted onto a general-purpose configuration object purely to undo a problem this option creates.
- **(b) Remove the cache directory from `ServiceConfiguration` altogether.** It becomes a bootstrap parameter of `EcotoneLite::bootstrap(...)`/`bootstrapFlowTesting(...)` for standalone use, and a framework-owned concern for Symfony/Laravel/Tempest (exactly what they already do today, unchanged). Delete `with/getCacheDirectoryPath()`; document "configure it via your framework, or via `EcotoneLite::bootstrap()`'s parameter" in the upgrade guide.
- **(c) Throw `ConfigurationException` when a framework integration detects a user override it's about to ignore.** Requires each integration to positively detect "was this non-default", which for a mutable-by-clone value object means comparing against `ServiceCacheConfiguration::defaultCachePath()` — a fragile equality check (a user whose real path happens to equal the default sentinel is silently fine; the moment the default changes, so does what triggers the guard). It's also pure friction: it turns a config field that never worked for these frameworks into one that actively errors, without giving the user anywhere correct to put the value instead.

**I'm picking (b), reversing round 1's "Option C."** It is the only option that doesn't require special-casing one field out of `ServiceConfiguration`'s otherwise-uniform hashing/cloning/merging behavior, and it matches what all three framework integrations already do in practice — nobody today relies on `ServiceConfiguration` actually carrying the production cache path, they only relied on it for `EcotoneLite`. Concretely:

```diff
--- a/packages/Ecotone/src/Messaging/Config/ServiceConfiguration.php
@@
     private string $serviceName = self::DEFAULT_SERVICE_NAME;
     private bool $failFast = self::DEFAULT_FAIL_FAST;
-    private string $cacheDirectoryPath;
     private string $environment = self::DEFAULT_ENVIRONMENT;
@@
     private function __construct()
     {
-        $this->cacheDirectoryPath = ServiceCacheConfiguration::defaultCachePath();
     }
@@
-    /**
-     * @deprecated use ServiceCacheDirectory
-     */
-    public function getCacheDirectoryPath(): string
-    {
-        return $this->cacheDirectoryPath;
-    }
-    public function withCacheDirectoryPath(string $cacheDirectoryPath): self
-    {
-        $clone                     = clone $this;
-        $clone->cacheDirectoryPath = rtrim($cacheDirectoryPath, '/');
-
-        return $clone;
-    }
```

```diff
--- a/packages/Ecotone/src/Lite/EcotoneLite.php
@@ public static function bootstrap(
     public static function bootstrap(
         ContainerInterface|array $containerOrAvailableServices = [],
         ?ServiceConfiguration $serviceConfiguration = null,
+        string $cacheDirectoryPath = ServiceCacheConfiguration::DEFAULT_PATH_PLACEHOLDER, // resolved to sys_get_temp_dir() below; see note
         ...
     ) {
@@ private static function prepareConfiguration(...)
-            $serviceConfiguration->getCacheDirectoryPath(),
+            $cacheDirectoryPath,
```
(Exact signature/plumbing is an implementation detail for §9 — the point is `$cacheDirectoryPath` becomes a parameter threaded through `bootstrap()`/`bootstrapFlowTesting()` alongside `$pathToRootCatalog`, not a `ServiceConfiguration` field. `sys_get_temp_dir()` remains the default, same as today.)

Symfony/Laravel/Tempest need **no change at all** beyond deleting the now-dead `->withCacheDirectoryPath()` calls in Laravel (`EcotoneProvider.php:63`) and Tempest (`MessagingSystemInitializer.php:128`) — those calls write into a field that (per §1) was never read back on that path anyway:

```diff
--- a/packages/Laravel/src/EcotoneProvider.php
@@
         $applicationConfiguration = ServiceConfiguration::createWithDefaults()
             ->withEnvironment($environment)
             ->withLoadCatalog(Config::get('ecotone.loadAppNamespaces') ? 'app' : '')
             ->withFailFast(false)
             ->withNamespaces(Config::get('ecotone.namespaces') ?? [])
-            ->withModulePackages($modulePackages)
-            ->withCacheDirectoryPath($cacheDirectory);
+            ->withModulePackages($modulePackages);
```
```diff
--- a/packages/Tempest/src/MessagingSystemInitializer.php
@@
         $applicationConfiguration = ServiceConfiguration::createWithDefaults()
             ->withEnvironment($environment)
             ->withLoadCatalog('')
             ->withFailFast(false)
             ->withNamespaces($namespaces)
-            ->withModulePackages($config->modulePackages)
-            ->withCacheDirectoryPath($cacheDirectory);
+            ->withModulePackages($config->modulePackages);
```
`$cacheDirectory` keeps flowing exactly as it does today — as the explicit parameter into `ContainerCacheLayout::resolve()`/`prepareFromCache()` — it just stops being duplicated onto `ServiceConfiguration` where it was dead weight. Symfony needs no diff since it never touched the field in the first place. This is a **net deletion**, not a new mechanism: nothing that currently works stops working, and the silent-no-op trap can no longer occur because there is nothing on `ServiceConfiguration` left to silently ignore.

## 3. Naming and Group H placement (revised)

Round 1 recommended renaming `ServiceCacheConfiguration` → `ServiceCacheDirectory` across ~40 call sites. **I'm reversing that too.** The only reason to rename was to give `ServiceConfiguration::getCacheDirectory(): ServiceCacheDirectory` a class to point at — and §2 just deleted that method. With no method left whose deprecation notice needs satisfying, the rename is purely cosmetic, and the task's own framing is right that cosmetics don't earn a ~40-call-site rename. **Recommendation: don't rename `ServiceCacheConfiguration`; delete the two deprecated `ServiceConfiguration` methods (§2) and, separately, delete the dangling `@deprecated use ServiceCacheDirectory` line — there is no replacement class to point users at, because the concept no longer lives on `ServiceConfiguration` at all.** `ServiceCacheConfiguration` keeps its current name, its current two-argument constructor, `noCache()`, `defaultCachePath()`, `REFERENCE_NAME`, and `getDefinition()`, unchanged.

On the "path + enabled flag under one name" objection: I agree the name undersells the boolean half, but renaming to fix that is the same cosmetic argument the task warned against — and Doctrine's own equivalent (`Configuration::setProxyDir()` + a *separate* `setAutoGenerateProxyClasses()`, cited in §4) shows established prior art for keeping "where" and "whether/how" as two concerns without forcing one class name to describe both. Ecotone's version already bundles both into one small value object; the name `ServiceCacheConfiguration` ("configuration for how the cache behaves") is defensible as-is for that shape, and not worth a second churn on top of un-deprecating `ServiceConfiguration`.

**Group H placement:** release-design Group H moves every extension object a user returns from `#[ServiceContext]` into `<Package>\Api\ExtensionObject\*`. §4 (unchanged from round 1) confirms `ServiceCacheConfiguration` cannot be a `#[ServiceContext]` extension object — deciding whether to skip annotation scanning requires the cache directory/flag to be known *before* the annotation finder (which discovers `#[ServiceContext]` methods) has run. It therefore does not move to an `Api\ExtensionObject` namespace. **It stays at `Ecotone\Messaging\Config\ServiceCacheConfiguration`, and should be marked `@internal`.** Justification: after §2's change, no ordinary end user ever constructs it directly — Symfony/Laravel/Tempest build it internally from framework convention, and `EcotoneLite` users configure a plain `string $cacheDirectoryPath` parameter, never this class. The only remaining consumers who touch it directly are people writing a *new* framework integration (the same tier as `ContainerCacheLayout` and `EcotoneSymfonyContainerFactory`, both already un-namespaced into `Api\*` and both already effectively integrator-only) — consistent, not a special case.

## 4. Alternatives compared (unchanged from round 1 — still holds)

| # | Approach | DX | Perf / correctness | Migration cost | Fits Ecotone's design rules | Verdict |
|---|---|---|---|---|---|---|
| A | New extension object `ServiceCacheDirectory`/`ServiceCacheConfiguration`, discovered via `#[ServiceContext]` | Familiar pattern | **Broken by construction**: `#[ServiceContext]` methods are found by the same annotation scan the cache exists to skip. You cannot ask "should I skip scanning?" by scanning | New concept, conflicts with the chicken-and-egg constraint everywhere it'd be used in production | Violates rule 2's own precondition | **Reject** |
| B | Framework-provided, convention-based dir per integration; `EcotoneLite` defaults to `sys_get_temp_dir()` | Zero-config, matches host framework idioms | Correct — matches what's already implemented | None for frameworks (already true) | Matches "smart, reliable-by-default" | Right default, needs an escape hatch |
| C (round 1) | Rename `ServiceCacheConfiguration`→`ServiceCacheDirectory`, put it on `ServiceConfiguration` | Same object end-to-end | **Silent no-op for Symfony/Laravel/Tempest (§2); contradicts the class's own docblock (§5)** | ~40-file cosmetic rename | Violates rule 3 (DX) via the silent no-op | **Reject — this round's finding** |
| D (this round) | B, plus an explicit `string $cacheDirectoryPath` **bootstrap parameter** on `EcotoneLite::bootstrap()`/`bootstrapFlowTesting()` (not a `ServiceConfiguration` field); `ServiceCacheConfiguration` kept as-is, `@internal`, integrator-facing only | Framework users: zero-config, unchanged. Lite/standalone users: one explicit, always-effective parameter, no silent no-op possible because there's no object field to ignore | No behavior change for any of the three frameworks; `EcotoneLite` gains a parameter that is read exactly once, in exactly the place it's used | Delete 2 methods on `ServiceConfiguration`, thread one new parameter through `EcotoneLite`, drop 2 dead `->withCacheDirectoryPath()` calls | Satisfies rule 2 without the annotation-scan-ordering conflict (A) or the silent-no-op/hash-pollution conflict (C) | **Recommended** |

## 5. Proposed public API

No new public class. `ServiceCacheConfiguration` is untouched (kept `@internal`, per §3):

```php
/**
 * ServiceConfiguration can be dumped for given environment and then code can be moved/symlinked.
 * So cache directory have to be split from dumped configuration.
 * This class can be resolved from DI and promotes design to resolve the cache path during execution phase.
 *
 * @internal integrators building a custom framework binding may construct this directly;
 *           application code configures the cache directory via the framework's own convention,
 *           or via EcotoneLite::bootstrap()'s $cacheDirectoryPath parameter.
 */
final class ServiceCacheConfiguration implements DefinedObject
{
    public const REFERENCE_NAME = self::class;

    public function __construct(private string $path, private bool $shouldUseCache)
    {
    }

    public static function noCache(): self
    {
        return new self(sys_get_temp_dir(), false);
    }

    public static function defaultCachePath(): string
    {
        return sys_get_temp_dir();
    }

    public function getPath(): string { return $this->path; }
    public function shouldUseCache(): bool { return $this->shouldUseCache; }
    public function getDefinition(): Definition { return new Definition(self::class, [$this->path, $this->shouldUseCache]); }
}
```

`ServiceConfiguration` loses `with/getCacheDirectoryPath()` (§2 diff) and gains nothing in its place — the concept moves out, it doesn't get renamed in place.

`EcotoneLite` usage after the change:

```php
$messagingSystem = EcotoneLite::bootstrap(
    cacheDirectoryPath: __DIR__ . '/var/cache/ecotone',
);
```

`ecotone:cache:clear` CLI — one shared deletion routine instead of three copy-pasted recursive-delete functions (Symfony `CacheClearer::deleteDirectory`, Laravel `EcotoneCacheClear::clearDirectory`, Tempest `EcotoneCacheClearCommand::removeCacheDirectory`):

```php
/** @internal */
final class ServiceCacheConfigurationClearer
{
    public static function clear(ServiceCacheConfiguration $serviceCacheConfiguration): void
    {
        self::deleteDirectory($serviceCacheConfiguration->getPath());
    }

    private static function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        foreach (array_diff(scandir($directory), ['.', '..']) as $item) {
            $path = $directory . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? self::deleteDirectory($path) : @unlink($path);
        }
        @rmdir($directory);
    }
}
```

Per-framework registration is revised from round 1 specifically because of §6's chicken-and-egg finding — **every `ecotone:cache:clear` command must depend only on `ServiceCacheConfiguration`, never on `ConfiguredMessagingSystem` or any bridged gateway, and must be discovered through the host framework's own native command mechanism, not Ecotone's dynamic `getRegisteredConsoleCommands()` list** (which itself requires a successfully-booted Ecotone container to enumerate):

- **Symfony**: a plain `Command` class depending only on `ServiceCacheConfiguration` (constructor-injected, same as `CacheClearer` already is), tagged `console.command` directly in `EcotoneExtension::load()` — sits alongside, not instead of, the existing `kernel.cache_clearer` tag on `CacheClearer`.
- **Laravel**: a plain `Illuminate\Console\Command` registered via `$this->commands([...])` in `EcotoneProvider::boot()`, constructed with only the `ServiceCacheConfiguration::REFERENCE_NAME` singleton (already bound eagerly at `EcotoneProvider.php:118-121` regardless of cache health) — **not** routed through the `getRegisteredConsoleCommands()`/`Artisan::command()` loop at lines 123-167, which needs `$container` (the possibly-broken Ecotone container) to already exist.
- **Tempest**: keep `EcotoneCacheClearCommand` exactly as it is — it already does this correctly (see §6).
- **EcotoneLite**: no CLI; document `ServiceCacheConfigurationClearer::clear(new ServiceCacheConfiguration($cacheDirectoryPath, true))` or a plain `rm -rf`.

## 6. Internals, including the chicken-and-egg gap (C6 — "most important gap")

Sequence for a cached boot is unchanged from round 1's description (framework resolves path → `ContainerCacheLayout::resolve()` computes config hash + annotation finder → `EcotoneSymfonyContainerFactory::bootstrap()` loads-or-builds → `ProxyFactory` writes gateway proxies). What round 1 did not examine is **what happens when the *stale-cache recovery path itself* is what's broken**, which the task correctly flagged as the most important gap. I traced this per framework:

**Symfony — already safe, verified, no change needed.** `EcotoneExtension::load()` runs during Symfony's own container compilation and calls `EcotoneSymfonyContainerFactory::build()` (not `bootstrap()`/`loadCached()`) for its primary container — `build()` always fully recompiles from a fresh annotation scan (`packages/Ecotone/src/SymfonyContainer/EcotoneSymfonyContainerFactory.php:63-90`); it never `require`s the previously-dumped `ecotone_container.php`. The *only* place that file is read is the lazy `'ecotone.container'` service factory (`EcotoneContainerLoader::load`, registered at `EcotoneExtension.php:108-110`), which Symfony resolves on demand, not during compilation. `CacheClearer` (`Symfony/DependencyInjection/Compiler/CacheClearer.php`) depends only on `ServiceCacheConfiguration` — never on that lazy service — so `bin/console cache:clear` already works even with a fully corrupt dump. A new `ecotone:cache:clear` command built the same way (§5) inherits this safety for free.

**Tempest — already safe, by a different but equally correct mechanism.** `EcotoneCacheClearCommand` (`packages/Tempest/src/EcotoneCacheClearCommand.php:9,21`) is discovered via `Tempest\Console\ConsoleCommand` — **Tempest's own native attribute**, not anything Ecotone's messaging system produces — and its constructor depends only on `Tempest\Console\Console`. Tempest's container resolves `MessagingSystemInitializer` (which builds the Ecotone container) lazily, only when something asks for `ConfiguredMessagingSystem`; running an unrelated command never triggers it. This is the pattern to copy for Symfony/Laravel, not a gap to fix.

**Laravel — the one real gap, and it's not the one round 1's error message assumed.** `EcotoneProvider::register()` calls `prepareFromCache()` **eagerly, synchronously, on every request/every artisan invocation** (`EcotoneProvider.php:100`). Two distinct failure modes exist and they behave very differently:
- *Stale-but-loadable* (a user's own class was renamed/removed since the cache was built): `loadCachedWithDefaults()` (`EcotoneProvider.php:203`) succeeds at the `require` step because the dumped container class itself is intact; the missing-class error only fires later, lazily, when something actually resolves the *specific* broken service. Laravel's `singleton(..., fn () => $factory())` bindings (`EcotoneProvider.php:103`) are lazy, and the dynamic-command loop (`EcotoneProvider.php:124`) only reads `getRegisteredConsoleCommands()`, which returns a serialized metadata array baked in at build time (`EcotoneSymfonyContainerFactory::build()` sets `CONSOLE_COMMANDS_PARAMETER = serialize($definitionsHolder->getRegisteredCommands())`), not live instances. So `register()` itself completes, artisan boots fine, and **any plain command — including a native `ecotone:cache:clear` — can already run.** This is the scenario `EcotoneContainer::attributeStaleCacheFailure()` (`EcotoneContainer.php:40-61`) is written for, and the command it recommends (`ecotone:cache:clear`) works for it once §5's command exists.
- *Cache file itself corrupt* (a truncated/partial write — e.g. the concurrent-warm-up race flagged in round 1's edge-case table, or external interference): `require $containerFile` throws a PHP parse/fatal error **immediately**, inside `register()`, before Laravel finishes booting any service provider. This aborts the entire artisan process — **no command at all can run, including a hypothetical `ecotone:cache:clear`,** because Laravel never gets far enough to register it. This is the genuine chicken-and-egg case, and round 1's error message ("clear the Ecotone cache (ecotone:cache:clear...)") would be actively unreachable advice in exactly this situation.

  Two complementary fixes, both worth doing (§9):
  1. **Prevent it at the source**: make `EcotoneSymfonyContainerFactory::dumpToCache()` write via `tempnam()` + `rename()` (atomic on POSIX) instead of `file_put_contents()` directly (round 1's edge-case finding, still valid and now more clearly motivated — it removes the torn-write failure mode entirely, since a killed/racing writer never gets to `rename()` a half-written file into the live path).
  2. **Defense in depth**: wrap the `loadCachedWithDefaults()` call in `prepareFromCache()` in a `try { ... } catch (\Throwable) { /* fall through to full rebuild */ }`, treating "corrupt cache" the same as "no cache file" rather than letting it propagate. This makes Laravel *self-healing* on the next request without needing any command at all, and it's strictly better than relying on `ecotone:cache:clear` for this specific failure mode, since — as just shown — that command may not be reachable when this failure mode occurs. `ecotone:cache:clear` remains the right tool for the *other*, non-crashing "stale but loadable" case, where self-healing can't apply (the container loads fine; it's just semantically wrong).

**Bottom line for the maintainer:** ship the atomic-write fix and the Laravel `try/catch` self-heal regardless of what else from this report is prioritized — they close the actual chicken-and-egg hole. The explicit `ecotone:cache:clear` commands (§5) are still worth adding for discoverability and for the stale-but-loadable case, but they are not sufficient on their own for total cache corruption in Laravel, and were never at risk in Symfony/Tempest to begin with.

**Anonymous classes and `bootstrapFlowTesting()` (task's other C6 bullet).** `ContainerCacheLayout::resolve()` force-disables caching when any registered class is anonymous (`ContainerCacheLayout.php:64`, `containsAnonymousClass()`) — sound, since an anonymous class's runtime name changes between processes and a cached container referencing one could never be reloaded. I initially assumed this was moot for `bootstrapFlowTesting()` because a sibling test helper, `ComponentTestBuilder`, hard-codes `ServiceCacheConfiguration::noCache()` (`Ecotone/src/Test/ComponentTestBuilder.php:72`) — but `bootstrapFlowTesting()` is a **different, separate code path**, and I traced it fully: it calls `prepareConfiguration(..., useCachedVersion: false)` (`EcotoneLite.php:91`), which calls `shouldUseAutomaticCache(false, $pathToRootCatalog)` (`EcotoneLite.php:270-283`). That method reads the caller's own `composer.json` and — for **any project that isn't Ecotone's own monorepo** — flips `$useCachedVersion` to `true` regardless of the `false` passed in. So a downstream user's `bootstrapFlowTesting()` calls **are cached by default**, into `sys_get_temp_dir()/ecotone/<configHash>/...`, unless the test registers an anonymous class, in which case the safety check in `ContainerCacheLayout::resolve()` disables it automatically for that one bootstrap. No user action is needed either way — but it means `ecotone:cache:clear` is irrelevant to anonymous-class-based tests (nothing gets written) while it **is** relevant to ordinary named-fixture test suites, which do accumulate real cache directories in `/tmp` across CI runs. Since each config change produces a *new* hash subdirectory rather than overwriting the old one, and nothing prunes stale ones, this is a genuine additional edge case beyond what round 1 listed: **`ecotone:cache:clear` for Lite/test usage should target the whole `sys_get_temp_dir()/ecotone/` parent, not just the currently-computed hash subdirectory, or stale hash directories accumulate indefinitely on shared CI runners.**

**The wiki's "cache removal" CLI wish and the `FileSystemAnnotationFinder.php:562` "temporary" comment**: still out of scope for the reasons given in round 1 (that comment is about the annotation-finder's fingerprinting strategy, not the directory-path deprecation this topic covers). One sentence for the maintainer, as requested: the "correct", non-temporary invalidation key would fingerprint each scanned namespace by directory `mtime`/file size rather than `sha1_file()`-ing the full contents of every already-discovered class on every request (`FileSystemAnnotationFinder.php:566-569`) — but doing so doesn't remove the deeper cost, which is that computing *any* key at all still requires the annotation finder to enumerate files first, so real relief would need the finder's discovery step redesigned, not just its hashing step; that's a separate, larger piece of work than this topic.

## 7. Edge cases

| Case | Behaviour |
|---|---|
| Read-only filesystem / immutable container image | `MessagingSystemConfiguration::prepareCacheDirectory()` throws a clear `ConfigurationException` on `mkdir` failure — keep as-is; document bind-mounting `%kernel.build_dir%` read-write at build time, read-only at runtime. |
| Concurrent cache warm-up (two workers race to build the same bucket) | Confirmed real: `dumpToCache()` uses plain `file_put_contents()` for both the class file and the loader stub, no atomicity. Fix: `tempnam()` + `rename()` for both writes (§6, §9). |
| **Total cache corruption blocking the cache-clear command itself** | See §6 — the actual gap the task asked about. Symfony/Tempest already safe by construction; Laravel needs the atomic-write fix plus a `try/catch` self-heal in `prepareFromCache()`. |
| Multi-tenant (per-tenant config, shared codebase) | Exercised by `packages/Laravel/tests/MultiTenant/MultiTenantTest.php`; tenant separation depends on tenant identity feeding `$configurationVariables` so the hash differs per tenant — document this as a requirement, not an automatic guarantee. |
| Cache dir shared between CLI and web workers, different OS users | `mkdir(..., 0775, true)` — group-writable, not world-writable; CLI and web must share a group (same guidance Laravel/Symfony already give for `storage`/`bootstrap/cache`). |
| Symfony cache-warmer contract | `CacheWarmer::isOptional() = true` is correct; it resolves `ConfiguredMessagingSystem` from the container being warmed, matching the ordering risk documented in Symfony's own Doctrine-proxy-warmer issues (§4 sources) — document that Ecotone's warmer must run after the container compiles. |
| Laravel `config:cache` interplay | Independent caches; the existing `optimize`/`optimize:clear` hook already clears both — no change. |
| Tests: parallel PHPUnit processes sharing a directory | Existing tests isolate via `sys_get_temp_dir() . '/ecotone-test-' . uniqid()`; `ComponentTestBuilder`'s default (`ServiceCacheConfiguration::noCache()`) never writes at all. Unaffected by §2's change since none of these call `ServiceConfiguration::withCacheDirectoryPath()` in a way that survives past this diff — they construct `ServiceCacheConfiguration` directly or via the new `EcotoneLite` parameter. |
| `bootstrapFlowTesting()` + anonymous classes vs. named fixtures | See §6 — caching is on by default for named-fixture test suites in downstream projects, auto-disabled for anonymous-class tests; unbounded `/tmp` growth across CI runs is a real, previously-unlisted edge case. |
| Multiple DB connections / Postgres vs MySQL vs SQLite | Not applicable — filesystem-only cache, no DB interaction. |
| Licence gating | Not an Enterprise feature; no `LicenceDecider` involvement. |

## 8. Migration impact — draft for `upgrade-2.0.md`

```diff
- $serviceConfiguration = ServiceConfiguration::createWithDefaults()
-     ->withCacheDirectoryPath(__DIR__ . '/var/cache/ecotone');
- $messagingSystem = EcotoneLite::bootstrap(serviceConfiguration: $serviceConfiguration);
+ $messagingSystem = EcotoneLite::bootstrap(
+     cacheDirectoryPath: __DIR__ . '/var/cache/ecotone',
+ );
```

```diff
- $cacheDirectoryPath = $serviceConfiguration->getCacheDirectoryPath();
+ // No longer configured on ServiceConfiguration. Symfony/Laravel/Tempest always resolve their own
+ // convention-based directory; for EcotoneLite/standalone use, pass cacheDirectoryPath to bootstrap().
```

No `ServiceCacheConfiguration` rename in this revision — nothing to `sed` there. Symfony/Laravel/Tempest users see **no change** (the field they were never able to use is simply gone); the only affected users are direct `EcotoneLite`/library callers of `withCacheDirectoryPath()`, of which `rg` finds 18 files, all inside this monorepo's own test suites (`Ecotone`, `Laravel`, `Dbal` packages) — no known third-party consumer impact beyond "move the string from a `ServiceConfiguration` method call to a `bootstrap()` parameter."

New for Symfony/Laravel users: `bin/console ecotone:cache:clear` / `php artisan ecotone:cache:clear` now exist explicitly, matching the wording `EcotoneContainer::attributeStaleCacheFailure()` already uses.

## 9. Implementation plan

1. Delete `ServiceConfiguration::$cacheDirectoryPath`, `with/getCacheDirectoryPath()`, and the constructor's default assignment (§2 diff). No rename of `ServiceCacheConfiguration`.
2. Rewrite `InMemoryReferenceSearchService.php:43-48` to `ServiceCacheConfiguration::noCache()`, dropping the `$serviceConfiguration->getCacheDirectoryPath()` call entirely (§1 — safe, provably dead value, test-only call sites).
3. Thread a `string $cacheDirectoryPath = ...` parameter through `EcotoneLite::bootstrap()`/`bootstrapFlowTesting()*`/`prepareConfiguration()`, defaulting to `ServiceCacheConfiguration::defaultCachePath()`; do **not** touch `Ecotone\Laravel\EcotoneProvider::getCacheDirectoryPath()` — different method, different class (§1).
4. Drop the dead `->withCacheDirectoryPath($cacheDirectory)` calls in `Laravel/EcotoneProvider.php:63` and `Tempest/MessagingSystemInitializer.php:128`; no change needed in Symfony's `EcotoneExtension.php` (never called it).
5. Extract `ServiceCacheConfigurationClearer::clear()` (§5) and point `Symfony/CacheClearer`, `Laravel/EcotoneCacheClear`, `Tempest/EcotoneCacheClearCommand` at it.
6. Add a native Symfony `Command` and a native Laravel `Illuminate\Console\Command`, both named `ecotone:cache:clear`, both depending only on `ServiceCacheConfiguration` — registered directly (Symfony: `console.command` tag in `EcotoneExtension::load()`; Laravel: `$this->commands([...])` in `boot()`), **not** through `getRegisteredConsoleCommands()` (§5, §6).
7. Harden `EcotoneSymfonyContainerFactory::dumpToCache()` to write via `tempnam()` + `rename()` for both the class file and the loader stub (§6).
8. Wrap the `loadCachedWithDefaults()` branch of `Laravel/EcotoneProvider::prepareFromCache()` in `try/catch (\Throwable)`, falling through to the full-rebuild path on any failure (§6) — Laravel-specific; Symfony/Tempest don't need this (§6).
9. For the Lite/test cache-accumulation edge case (§6), make the Lite-facing clear helper target the `sys_get_temp_dir()/ecotone/` parent directory, not just one hash subdirectory.
10. Migrate the 18 in-repo call sites using `with/getCacheDirectoryPath()` and update `upgrade-2.0.md` with the §8 snippets.
11. `rg -l "CacheDirectory" .claude/skills` and update any skill references that assumed the old method names.

## 10. Open questions for the maintainer

1. **Is deleting `ServiceConfiguration::with/getCacheDirectoryPath()` outright (Option D/b) acceptable, versus keeping a deprecated-but-functional shim for one release?** *Recommendation:* delete outright — 2.0 is already a breaking release, all 18 call sites are in-repo, and a shim that's a documented no-op for 3 of 4 target frameworks would be worse than no method at all.
2. **Should the Laravel `try/catch` self-heal (§6, §9 task 8) log a warning when it triggers, so silent-but-frequent cache corruption doesn't go unnoticed in production?** *Recommendation:* yes, via the existing `LoggerInterface`/`LaravelLogger` binding — a `warning`-level log on fallback, not an `error`, since the system is recovering, not failing.
3. **Should `ecotone:cache:clear` for Symfony also be wired into `kernel.cache_clearer` directly (so `cache:clear` alone is sufficient), or kept purely as a separate named command?** Round 1's Symfony `CacheClearer` already implements `CacheClearerInterface`/`kernel.cache_clearer`, independent of the new named command. *Recommendation:* keep both — `cache:clear` already works (§6) and shouldn't be changed; the named command exists purely for discoverability and to make the stale-cache error message's advice literally executable.
