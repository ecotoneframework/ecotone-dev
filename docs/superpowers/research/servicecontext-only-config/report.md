# `servicecontext-only-config` — framework config files → `#[ServiceContext]` only (Release Group G)

Branch `dgafka/ecotone-2-0-work` @ `bcf4efc0`. Every claim below is cited `file:line` against that tree.
Public API now lives under `Ecotone\Api\*` (`packages/<Pkg>/Api/`); `ServiceConfiguration` is
`Ecotone\Api\ExtensionObject\ServiceConfiguration` at `packages/Ecotone/Api/ServiceConfiguration.php`.

---

## 0. Executive summary

Group G is **not** primarily a config-file trim. Two load-bearing findings change its shape:

1. **`ServiceConfiguration` values returned from `#[ServiceContext]` are silently ignored today, except two
   fields.** `ServiceConfiguration::mergeWith()` (`packages/Ecotone/Api/ServiceConfiguration.php:68-103`) merges
   *only* `defaultSerializationMediaType` and `defaultErrorChannel`. `serviceName`, `defaultMemoryLimit`,
   `connectionRetryTemplate`, `licenceKey`, `modulePackages`, `namespaces`, `failFast`, `environment`,
   `loadCatalog` and `extensionObjects` on a `#[ServiceContext]`-returned `ServiceConfiguration` are dropped on
   the floor. So "move `serviceName` from YAML to `#[ServiceContext]`" is not a move — the destination does not
   work yet. **Fixing `mergeWith()` is the whole of G's core work**; the config-file edits are the easy part.
2. **Two keys in the framework config are already dead.** `failFast` has had no reader anywhere in `src` since
   the container-compilation PoC (`git log -S "isFailingFast()"` → `24b8a32c`), and Symfony's
   `cacheConfiguration` node (`packages/Symfony/DependencyInjection/Configuration.php:27-29`) is never read by
   `EcotoneExtension` — Symfony hard-codes `shouldUseCache: true`
   (`packages/Symfony/DependencyInjection/EcotoneExtension.php:94,108`). Both should be deleted, not migrated.

Recommended split: **5 bootstrap keys stay** (`namespaces`, `loadSrcNamespaces`/`loadAppNamespaces`,
`modulePackages`, `cacheConfiguration`, `test`) **+ `licenceKey`**; **5 keys move** to `#[ServiceContext]`
(`serviceName`, `defaultSerializationMediaType`, `defaultErrorChannel`, `defaultMemoryLimit`,
`defaultConnectionExceptionRetry`); **1 key is deleted** (`failFast`).

---

## 1. Inventory

### 1.1 Symfony — `config/packages/ecotone.yaml`

Tree: `packages/Symfony/DependencyInjection/Configuration.php:20-86`. Consumption:
`packages/Symfony/DependencyInjection/EcotoneExtension.php:32-105`.

| YAML key | Declared | Read at | `ServiceConfiguration` method | Phase | Verdict |
|---|---|---|---|---|---|
| `serviceName` | `Configuration.php:23-25` | `EcotoneExtension.php:55-58` | `withServiceName()` (`ServiceConfiguration.php:117`) | **runtime** — consumed by `DistributedBusWithServiceMapModule.php:80,91,121…` and `DistributedHandlerModule.php:106`, both module-compile passes that run *after* the merge | **move** |
| `cacheConfiguration` | `Configuration.php:27-29` | **nowhere** — `grep cacheConfiguration packages/Symfony/DependencyInjection` returns only the tree node | — | — | **delete (dead)** |
| `failFast` | `Configuration.php:31-33` | `EcotoneExtension.php:42` | `withFailFast()` (`ServiceConfiguration.php:109`) | — the value is stored and never read: only `ServiceConfiguration.php:334,363` return it, no caller | **delete (dead)** |
| `test` | `Configuration.php:35-37` | `EcotoneExtension.php:104` | not a `ServiceConfiguration` field — passed as `enableTestPackage` | **bootstrap** — reaches `AnnotationFinderFactory::createForAttributes(..., $enableTestPackage)` (`MessagingSystemConfiguration.php:695`) and `addCorePackage()` (`:685`) | **stays** |
| `loadSrcNamespaces` | `Configuration.php:39-41` | `EcotoneExtension.php:43` | `withLoadCatalog('src'\|'')` (`:143`) | **bootstrap** — `MessagingSystemConfiguration.php:692` → `$catalogToLoad` in `FileSystemAnnotationFinder::init()` (`:138-140`) | **stays** |
| `defaultSerializationMediaType` | `Configuration.php:43-45` | `EcotoneExtension.php:60-63` | `withDefaultSerializationMediaType()` (`:194`) | **runtime** — already merged from `#[ServiceContext]` (`ServiceConfiguration.php:72-84`) | **move** |
| `defaultErrorChannel` | `Configuration.php:47-49` | `EcotoneExtension.php:79-82` | `withDefaultErrorChannel()` (`:202`) | **runtime** — merged (`:86-100`), consumed at `MessagingContainerBuilder.php:129-131` | **move** |
| `namespaces` | `Configuration.php:52-55` | `EcotoneExtension.php:44` | `withNamespaces()` (`:186`) | **bootstrap** — `MessagingSystemConfiguration.php:690`, `ContainerCacheLayout.php:46` | **stays** |
| `defaultMemoryLimit` | `Configuration.php:57-59` | `EcotoneExtension.php:64-67` | `withConsumerMemoryLimit()` (`:258`) | **runtime** — `MessagingContainerBuilder.php:133-136` | **move** |
| `defaultConnectionExceptionRetry` | `Configuration.php:61-75` | `EcotoneExtension.php:68-78` | `withConnectionRetryTemplate()` (`:210`) via `RetryTemplateBuilder::exponentialBackoffWithMaxDelay` | **runtime** — `MessagingContainerBuilder.php:137-140`, defaulted at `MessagingSystemConfiguration.php:207-219` | **move** |
| `licenceKey` | `Configuration.php:77-79` | `EcotoneExtension.php:51-53` | `withLicenceKey()` (`:217`) | **runtime-but-early** — validated at `MessagingSystemConfiguration.php:250-253`, i.e. *after* the merge at `:205`, but it gates module compilation (`ProjectingModule.php:69`, `AmqpModule.php:113`, `RabbitConsumerModule.php:50`, `AggregrateModule.php:344`, `OrchestratorModule.php:213`, `DataProtectionModule.php:193`) | **stays** (§2.5) |
| `modulePackages` | `Configuration.php:81-84` | `EcotoneExtension.php:38,47-49` | `withModulePackages()` (`:238`) | **bootstrap** — feeds `MessagingSystemConfiguration::getModuleClassesFor()` (`:277-285`) whose output is `$systemClassesToRegister` for the annotation finder (`:693`, `ContainerCacheLayout.php:49`) | **stays** |

Unknown keys are **already rejected** by Symfony: `ArrayNode::$ignoreExtraKeys = false`
(`vendor/symfony/config/Definition/ArrayNode.php:31`) and the throw at `:287-303`
(`Unrecognized option%s "%s" under "%s"`). `Configuration.php` never calls `ignoreExtraKeys()`. So deleting a
node *is* the rejection mechanism — no extra code needed on the Symfony side.

Environment placeholders are resolved at **compile time**: `EcotoneExtension.php:36`
(`$container->resolveEnvPlaceholders($config, true)`).

### 1.2 Laravel — `config/ecotone.php`

File: `packages/Laravel/config/ecotone.php`. Consumption: `packages/Laravel/src/EcotoneProvider.php:40-103`
(the `@TODO Ecotone 2.0 use ServiceContext to configure Laravel` marker is at `:56`).

| PHP key | Declared | Read at | `ServiceConfiguration` method | Phase | Verdict |
|---|---|---|---|---|---|
| `serviceName` | `config/ecotone.php:14` | `EcotoneProvider.php:77-81` | `withServiceName()` | runtime | **move** |
| `loadAppNamespaces` | `:24` | `EcotoneProvider.php:59` | `withLoadCatalog('app'\|'')` | **bootstrap** | **stays** |
| `namespaces` | `:35` | `EcotoneProvider.php:61` | `withNamespaces()` | **bootstrap** | **stays** |
| `cacheConfiguration` | `:54` | `EcotoneProvider.php:49` | — (drives `$useProductionCache`) | **bootstrap** — decides whether the annotation scan happens at all (`EcotoneProvider.php:204-210`) | **stays** |
| `defaultSerializationMediaType` | `:66` | `EcotoneProvider.php:72-76` | `withDefaultSerializationMediaType()` | runtime | **move** |
| `defaultErrorChannel` | `:77` | `EcotoneProvider.php:53,83-86` | `withDefaultErrorChannel()` | runtime | **move** |
| `defaultConnectionExceptionRetry` | `:92` | `EcotoneProvider.php:88-98` | `withConnectionRetryTemplate()` | runtime | **move** |
| `modulePackages` | `:105` (commented out) | `EcotoneProvider.php:55,64-66` | `withModulePackages()` | **bootstrap** | **stays** |
| `test` | `:115` | `EcotoneProvider.php:51,101,219,233` | — (`enableTestPackage`) | **bootstrap** | **stays** |
| `licenceKey` | `:125` | `EcotoneProvider.php:68-70` | `withLicenceKey()` | runtime-but-early | **stays** |

Laravel has **no** `defaultMemoryLimit` and **no** `failFast` key; `withFailFast(false)` is hard-coded at
`EcotoneProvider.php:60`.

Unknown keys are **silently accepted**: `mergeConfigFrom()` is a shallow `array_merge`
(`vendor/laravel/framework/src/Illuminate/Support/ServiceProvider.php:163-172`) — a stale published
`config/ecotone.php` keeps every key the user wrote, and `Config::get('ecotone.serviceName')` simply stops
being read. **Laravel needs an explicit unknown-key guard**; this is the one place where "the provider rejects
unknown keys" (upgrade-2.0.md:305) requires new code.

### 1.3 Tempest — `EcotoneConfig`

Class: `packages/Tempest/src/EcotoneConfig.php:12-31`. Consumption:
`packages/Tempest/src/MessagingSystemInitializer.php:57-152`.

| Constructor property | Declared | Read at | Phase | Verdict |
|---|---|---|---|---|
| `serviceName` | `EcotoneConfig.php:15` (+ `ECOTONE_SERVICE_NAME` env fallback at `:25-27`) | `MessagingSystemInitializer.php:133-135` | runtime | **move** |
| `namespaces` | `:16` | `:116-120` | **bootstrap** | **stays** |
| `loadAppNamespaces` | `:17` | `:118` — falls back to `deriveNamespacesFromComposer()` (`:94-108`) | **bootstrap** | **stays** |
| `cacheConfiguration` | `:18` (+ `ECOTONE_CACHE_CONFIGURATION` env fallback at `:28-30`) | `:63,164-173` | **bootstrap** | **stays** |
| `defaultSerializationMediaType` | `:19` | `:137-139` | runtime | **move** |
| `defaultErrorChannel` | `:20` | `:141-143` | runtime | **move** |
| `modulePackages` | `:21` | `:129-131` | **bootstrap** | **stays** |
| `test` | `:22` | `:70,151,179-181,195` | **bootstrap** | **stays** |
| `licenceKey` | `:23` | `:145-147` | runtime-but-early | **stays** |

Tempest already rejects unknown keys — `new EcotoneConfig(unknownKey: …)` is a PHP `Unknown named parameter`
error. Note Tempest hard-codes `withLoadCatalog('')` (`:124`) and `withFailFast(false)` (`:125`).

### 1.4 How the framework-provided `ServiceConfiguration` merges with `#[ServiceContext]` ones

Path, in order:

1. `MessagingSystemConfiguration::prepareWithModuleRetrievingService()`
   (`packages/Ecotone/src/Messaging/Config/MessagingSystemConfiguration.php:726-737`) calls
   `$moduleConfigurationRetrievingService->findAllExtensionObjects()` **before** constructing `self`.
2. `AnnotationModuleRetrievingService::findAllExtensionObjects()`
   (`packages/Ecotone/src/Messaging/Config/Annotation/AnnotationModuleRetrievingService.php:52-97`) finds every
   `#[ServiceContext]` method (`:54`), instantiates the class (`:66`), resolves `#[ConfigurationVariable]`
   parameters (`:68-79`), invokes it (`:80`) and flattens arrays (`:89-93`).
3. `MessagingSystemConfiguration::__construct()` (`:196-256`):
   - `:198` merges the **framework** `ServiceConfiguration`'s own `getExtensionObjects()` into the list.
     A `#[ServiceContext]`-returned `ServiceConfiguration`'s extension objects are **not** unwrapped.
   - `:199-204` collects every `ServiceConfiguration` instance out of the extension objects.
   - `:205` `$serviceConfiguration = $serviceConfiguration->mergeWith($extensionApplicationConfiguration);`
   - `:224-233` filters every `ServiceConfiguration` **out** of the extension-object list, then `:248` pushes
     the merged one back in. So modules only ever see the merged object.
   - `:250-253` validates `licenceKey` and sets `isRunningForEnterpriseLicence`.

`ServiceConfiguration::mergeWith()` (`packages/Ecotone/Api/ServiceConfiguration.php:68-103`) — the whole body:

```php
if (! $this->defaultSerializationMediaType) {            // :72  framework value wins if set
    foreach ($applicationConfigurations as $c) {
        if ($c->defaultSerializationMediaType) {
            if ($found && $c->defaultSerializationMediaType !== $found) {
                throw ConfigurationException::create("Ecotone can't resolve defaultSerializationMediaType…");   // :77
            }
            $found = $c->defaultSerializationMediaType;
        }
    }
    $self = $self->withDefaultSerializationMediaType($found ?? self::DEFAULT_SERIALIZATION_MEDIA_TYPE);   // :83
}
// identical block for defaultErrorChannel                 :86-100  (throw at :91)
```

**Conflict semantics, confirmed by tests** (`packages/Ecotone/tests/Messaging/Unit/Config/MessagingSystemConfigurationTest.php`):

- framework value set → it wins, `#[ServiceContext]` values ignored, no error (`:688-694`, `:711-717`)
- framework value unset, one `#[ServiceContext]` value → that value is used (`:673-679`, `:696-702`)
- framework value unset, **two different** `#[ServiceContext]` values → `ConfigurationException` (`:681-686`, `:704-709`)
- nothing set → `DEFAULT_SERIALIZATION_MEDIA_TYPE` = `application/x-php-serialized` (`ServiceConfiguration.php:22,83`); `defaultErrorChannel` stays null (`:97-99`)

**It is not "last wins". It is "framework wins, otherwise unanimity or error".**

Everything else on a `#[ServiceContext]`-returned `ServiceConfiguration` is discarded. Ecotone's own only
in-repo consumer of this mechanism is `packages/JmsConverter/src/Configuration/JMSDefaultSerialization.php:14-19`,
which sets exactly `defaultSerializationMediaType` — i.e. the mechanism is only exercised for a field that
happens to be one of the two supported ones.

### 1.5 Two structural defects that block the move

**(a) Four `with*()` methods mutate instead of cloning.** Clone: `withFailFast` (`:109`), `withServiceName`
(`:117`), `withEnvironment` (`:132`), `withLoadCatalog` (`:143`), `withExtensionObjects` (`:151`),
`addExtensionObject` (`:162`), `doNotLoadCatalog` (`:170`), `withNamespaces` (`:186`),
`withDefaultSerializationMediaType` (`:194`), `withDefaultErrorChannel` (`:202`), `withCacheDirectoryPath`
(`:344`). Mutate `$this` and return `$this`: `withConnectionRetryTemplate` (`:210-215`), `withLicenceKey`
(`:217-222`), `withModulePackages` (`:238-246`), `withConsumerMemoryLimit` (`:258-263`).

Two call sites **depend** on the mutation and discard the return value:
`MessagingSystemConfiguration.php:207-219` (`$serviceConfiguration->withConnectionRetryTemplate(...)` — return
value thrown away) and, transitively, `addCorePackage()` (`:287-300`) whose `withModulePackages()` mutates its
argument. Making `mergeWith()` handle these fields requires fixing this first, or the merge result and the
caller's object silently diverge.

**(b) `defaultMemoryLimitInMegabytes` has a non-null default.**
`ServiceConfiguration.php:40` initialises it to `PollingMetadata::DEFAULT_MEMORY_LIMIT_MEGABYTES` = `1024`
(`packages/Ecotone/Api/PollingMetadata.php:21`). The "framework value wins if set" test used for the other two
fields (`if (! $this->x)`) can never distinguish "unset" from "explicitly 1024", so it cannot be reused as-is.
It also means `MessagingContainerBuilder.php:133` (`if ($this->applicationConfiguration->getDefaultMemoryLimitInMegabytes() && …)`)
always fires, so the "default" is already applied unconditionally.

**(c) `#[ServiceContext]` configuration variables have no optional/default path.**
`AnnotationModuleRetrievingService.php:78` calls `$this->variableConfigurationService->getByName($variableName)`
unconditionally. Compare the handler path, `ValueConverter::fromConfigurationVariableService()`
(`packages/Ecotone/src/Messaging/Handler/Processor/MethodInvoker/Converter/ValueConverter.php:32-42`), which
checks `hasName()` and falls back to the PHP default or throws a named error. On Symfony,
`SymfonyConfigurationVariableService::getByName()` (`packages/Symfony/DependencyInjection/Compiler/SymfonyConfigurationVariableService.php:20`)
delegates to `Container::getParameter()`, which throws `ParameterNotFoundException` for a missing parameter.
So today a `#[ServiceContext]` method with an optional `#[ConfigurationVariable]` parameter **hard-fails on
Symfony** — which is precisely the pattern G asks users to adopt for `serviceName`, `defaultErrorChannel` etc.
This must be fixed inside G.

---

## 2. Chicken-and-egg analysis — what genuinely cannot move

The dividing line is exactly one call: **`AnnotationFinderFactory::createForAttributes()`**
(`packages/Ecotone/src/AnnotationFinder/AnnotationFinderFactory.php:14-27`). It is what discovers
`#[ServiceContext]` methods. Anything that is an *argument* to it cannot itself come from a `#[ServiceContext]`.
Two call sites:

```php
// MessagingSystemConfiguration::prepare()  :688-696
AnnotationFinderFactory::createForAttributes(
    realpath($rootPathToSearchConfigurationFor),
    $serviceConfiguration->getNamespaces(),        // :690
    $serviceConfiguration->getEnvironment(),       // :691
    $serviceConfiguration->getLoadedCatalog() ?? '',   // :692
    self::getModuleClassesFor($serviceConfiguration),  // :693
    $userLandClassesToRegister,
    $enableTestPackage                             // :695
);
// ContainerCacheLayout::resolve()  :44-52  — same five arguments
```

### 2.1 `namespaces` — cannot move
`ServiceConfiguration::getNamespaces()` is argument 2. `FileSystemAnnotationFinder::init()`
(`packages/Ecotone/src/AnnotationFinder/FileSystem/FileSystemAnnotationFinder.php:138-140`) uses it to decide
which PSR-4 roots to walk. With no namespaces you cannot find the class that would declare them. **Stays.**

### 2.2 `loadSrcNamespaces` / `loadAppNamespaces` (`loadCatalog`) — cannot move
Argument 4 (`$catalogToLoad`), same `init()`. Identical reasoning. **Stays.**

### 2.3 `modulePackages` — cannot move
Argument 5 is `getModuleClassesFor($serviceConfiguration)`
(`MessagingSystemConfiguration.php:277-285`), which expands `ModulePackageList::allPackages()` minus
`getSkippedModulesPackages()` into concrete module class names, and those become
`$systemClassesToRegister` — the set of classes the finder registers *in addition to* the user's namespaces.
Deciding which module classes to scan by scanning is circular. Also note `withModulePackages()`
(`ServiceConfiguration.php:238-246`) is subtractive (`array_diff` against `allPackages()`), so a partial
`#[ServiceContext]` value could not be composed meaningfully anyway. **Stays.**

### 2.4 `cacheConfiguration` — cannot move
It decides whether the annotation scan is skipped entirely. `EcotoneProvider::prepareFromCache()`
(`packages/Laravel/src/EcotoneProvider.php:204-210`) returns a fully dumped container **before**
`ContainerCacheLayout::resolve()` is ever called; `MessagingSystemInitializer::prepareFromCache()`
(`packages/Tempest/src/MessagingSystemInitializer.php:164-173`) does the same. Reading the flag from a
`#[ServiceContext]` would require the scan the flag exists to avoid. This is the same argument the
`service-cache-directory` report makes for `ServiceCacheConfiguration` (its §4 row A). **Stays.**

### 2.5 `licenceKey` — *could* move; **decision: stays in framework config**
Mechanically it could move. It is read at `MessagingSystemConfiguration.php:250-253`, which runs after the
merge at `:205`, and it is not an argument to `createForAttributes()`. Three reasons to keep it in framework
config anyway:

1. **Cache-invalidation integrity.** `isRunningForEnterpriseLicence` changes which container gets compiled —
   `ProjectingModule.php:69` and `AmqpModule.php:113` *throw* without it; `AggregrateModule.php:344`,
   `OrchestratorModule.php:213`, `DataProtectionModule.php:193` register different definitions. So the compiled
   container is licence-dependent. In framework config the value is part of the framework's own invalidation
   input: Laravel hashes `Config::all()` (`EcotoneProvider.php:218` → `FileSystemAnnotationFinder.php:577`) and
   Symfony rebuilds the container when a `%env()%`-resolved value changes (`EcotoneExtension.php:36`). Read from
   a raw `getenv()` inside a `#[ServiceContext]`, it is invisible to the hash (§8.1) — you could deploy a
   licence and keep serving an open-core container.
2. **Secret shape.** Every existing example uses the framework's env indirection —
   `'licenceKey' => '%env(SYMFONY_LICENCE_KEY)%'` (`packages/Symfony/tests/phpunit/Licence/config/services.php:9`,
   `EnvPlaceholderKafka/config/services.php:13`, `EnvPlaceholderEndpoint/config/services.php:9`),
   `env('LARAVEL_LICENCE_KEY')` (`packages/Laravel/tests/Licence/config/ecotone.php:7`,
   `tests/MultiTenant/config/ecotone.php:8`). That is the idiomatic place for a secret in both frameworks.
3. **Symmetry with `EcotoneLite`.** The licence is already a *bootstrap parameter* there —
   `EcotoneLite::bootstrap(licenceKey: …)` (`packages/Ecotone/src/Lite/EcotoneLite.php:49,162`), not a
   `ServiceConfiguration` field users are expected to set.

It should still be **merged** from `#[ServiceContext]` under the uniform rule (§4), so `EcotoneLite` and
`LicenceTesting::VALID_LICENCE` keep working and so a user *can* set it from code — framework config just wins.

### 2.6 `failFast` — **delete**
`git log --oneline -S "isFailingFast()" -- packages/` → `24b8a32c` ("PoC: compile messaging system into a
container (#223)") and `b63cb19b`. Nothing in `packages/*/src` reads `failFast()` (`:334`) or `isFailingFast()`
(`:363`) today; the only writers are `EcotoneExtension.php:42`, `EcotoneProvider.php:60`,
`MessagingSystemInitializer.php:125` and test fixtures. It is a no-op flag with a plausible-sounding name — the
worst kind. Delete the YAML node, the `withFailFast()`/`failFast()`/`isFailingFast()` methods and the field.
(Section 5 keeps `withFailFast()` out of the migration table for the same reason.)

### 2.7 `test` — cannot move
Argument 7 (`$isRunningForTesting`, `AnnotationFinderFactory.php:14,25`), which controls
`FileSystemAnnotationFinder::init()`'s "empty configuration is legal" branch (`:140`), *and* it selects the
`TEST_PACKAGE` in `addCorePackage()` (`MessagingSystemConfiguration.php:285-300`), which feeds §2.3. **Stays.**

### 2.8 `serviceName` — **moves**
Consumed only in module-compile passes that run well after the merge:
`DistributedBusWithServiceMapModule.php:80` (asserts it is not
`ServiceConfiguration::DEFAULT_SERVICE_NAME`), `:91,121,136,151,165,181`, and
`DistributedHandlerModule.php:106`. Nothing before the annotation scan reads it. **Moves.**

### 2.9 `defaultMemoryLimit` — **moves**
Only reader: `MessagingContainerBuilder.php:133-136`, a compile-time pass over polling endpoints. **Moves**,
conditional on fixing the non-null default (§1.5b).

### 2.10 `defaultConnectionExceptionRetry` — **moves**
Only readers: `MessagingContainerBuilder.php:137-140` and the production/dev default at
`MessagingSystemConfiguration.php:207-219`. **Moves**, conditional on fixing the mutation (§1.5a) — the
default-filling block at `:207-219` relies on `withConnectionRetryTemplate()` mutating in place.

### 2.11 Summary

| Key | Verdict | Proof |
|---|---|---|
| `namespaces` | stays (bootstrap) | arg 2 of `createForAttributes` — `MessagingSystemConfiguration.php:690` |
| `loadSrcNamespaces` / `loadAppNamespaces` | stays (bootstrap) | arg 4 — `:692`, `FileSystemAnnotationFinder.php:138-140` |
| `modulePackages` | stays (bootstrap) | arg 5 via `getModuleClassesFor()` — `:693,277-285` |
| `cacheConfiguration` | stays (bootstrap) | pre-scan short-circuit — `EcotoneProvider.php:204-210` |
| `test` | stays (bootstrap) | arg 7 + `addCorePackage` — `:695,685` |
| `licenceKey` | stays (policy, §2.5) | `:250-253`; gates `ProjectingModule.php:69` |
| `serviceName` | **moves** | `DistributedBusWithServiceMapModule.php:80` |
| `defaultSerializationMediaType` | **moves** | already merged — `ServiceConfiguration.php:72-84` |
| `defaultErrorChannel` | **moves** | already merged — `:86-100`; `MessagingContainerBuilder.php:129` |
| `defaultMemoryLimit` | **moves** | `MessagingContainerBuilder.php:133` |
| `defaultConnectionExceptionRetry` | **moves** | `MessagingContainerBuilder.php:137` |
| `failFast` | **deleted** | no reader since `24b8a32c` |
| Symfony `cacheConfiguration` node | **deleted** | not read by `EcotoneExtension` |

---

## 3. Environment-specific configuration

### 3.1 `#[Environment]` filtering does work for `#[ServiceContext]` — confirmed

`Ecotone\Api\Attribute\Environment` (`packages/Ecotone/Api/Environment.php:7`) targets classes **and** methods.
`FileSystemAnnotationFinder::__construct()` builds the ban list at `:79-130`: class-level `#[Environment]`
removes the class from `registeredClasses` (`:84-90`); method-level wins over class-level (`:120-128`) and
populates `$bannedEnvironmentClassMethods`. `findAnnotatedMethods()` — the method
`AnnotationModuleRetrievingService::findAllExtensionObjects()` uses (`:54`) — skips banned methods at
`:343-345`. So both of these work:

```php
#[ServiceContext]
#[Environment(['prod', 'production'])]
public function productionConfig(): ServiceConfiguration { … }
```

The environment name compared against is `$serviceConfiguration->getEnvironment()`
(`MessagingSystemConfiguration.php:691`, `ContainerCacheLayout.php:47`), which each integration derives from
the framework, never from a config key: Symfony `kernel.environment` (`EcotoneExtension.php:41`), Laravel
`App::environment()` (`EcotoneProvider.php:47,58`), Tempest `APP_ENV`/`ENVIRONMENT` defaulting to `production`
(`MessagingSystemInitializer.php:62,123`). Nothing to migrate here.

### 3.2 Reading environment values inside `#[ServiceContext]`

`#[ConfigurationVariable]` (`packages/Ecotone/Api/ConfigurationVariable.php:11-24`) on a parameter; the value
comes from the per-framework `ConfigurationVariableService`:

| Framework | Implementation | What the name resolves to |
|---|---|---|
| Symfony | `SymfonyConfigurationVariableService.php:18-30` | a **container parameter**; a `%…%` value is resolved through `resolveEnvPlaceholders()` at `:26` |
| Laravel | `LaravelConfigurationVariableService.php:13-21` | `Config::get($name)` — i.e. `config('services.billing.name')` |
| Tempest | `TempestConfigurationVariableService.php:21-30` | `env($name)`, or `SomeConfig::class . '::property'` via `:23-27` |

**Caveat (§1.5c): the optional path is missing.** `AnnotationModuleRetrievingService.php:78` calls `getByName()`
without a `hasName()` check, so on Symfony a missing parameter throws `ParameterNotFoundException`, and on
Laravel/Tempest you silently get `null` regardless of the PHP default you wrote. Fix this in G by mirroring
`ValueConverter::fromConfigurationVariableService()` (`ValueConverter.php:32-42`).

### 3.3 Idiomatic 2.0 pattern, per framework

**Symfony** — env goes into a parameter, the ServiceContext reads the parameter:

```yaml
# config/services.yaml
parameters:
    app.ecotone.service_name: '%env(ECOTONE_SERVICE_NAME)%'
    app.ecotone.error_channel: '%env(default::ECOTONE_DEFAULT_ERROR_CHANNEL)%'
```
```php
namespace App\Infrastructure;

use Ecotone\Api\{ConfigurationVariable, Environment, ServiceConfiguration, ServiceContext};
use Ecotone\Messaging\Conversion\MediaType;

final class EcotoneConfiguration
{
    #[ServiceContext]
    public function service(
        #[ConfigurationVariable('app.ecotone.service_name')] string $serviceName,
        #[ConfigurationVariable('app.ecotone.error_channel')] ?string $errorChannel = null,
    ): ServiceConfiguration {
        $configuration = ServiceConfiguration::createWithDefaults()
            ->withServiceName($serviceName)
            ->withDefaultSerializationMediaType(MediaType::APPLICATION_JSON);

        return $errorChannel ? $configuration->withDefaultErrorChannel($errorChannel) : $configuration;
    }

    #[ServiceContext]
    #[Environment(['prod', 'production'])]
    public function productionTuning(): ServiceConfiguration
    {
        return ServiceConfiguration::createWithDefaults()->withConsumerMemoryLimit(512);
    }
}
```

**Laravel** — the name is a `config()` path, so keep app-owned values in your own config file:

```php
// config/messaging.php
return ['serviceName' => env('ECOTONE_SERVICE_NAME', 'billing')];
```
```php
#[ServiceContext]
public function service(
    #[ConfigurationVariable('messaging.serviceName')] string $serviceName,
): ServiceConfiguration {
    return ServiceConfiguration::createWithDefaults()->withServiceName($serviceName);
}
```
Prefer this over calling `env()` directly inside the method: `Config::all()` is hashed into the cache key
(`EcotoneProvider.php:218`), a raw `env()` read is not (§8.1).

**Tempest** — the name is an env var, or `Config::class . '::property'`:

```php
#[ServiceContext]
public function service(
    #[ConfigurationVariable('ECOTONE_SERVICE_NAME')] string $serviceName,
): ServiceConfiguration {
    return ServiceConfiguration::createWithDefaults()->withServiceName($serviceName);
}
```
Tempest currently passes **no** `configurationVariables` into `ContainerCacheLayout::resolve()`
(`MessagingSystemInitializer.php:175-182` omits the argument, default `[]` at `ContainerCacheLayout.php:39`),
so even `#[ConfigurationVariable]` values are outside the hash there. G should close that (§10, PR6).

---

## 4. Multiple `#[ServiceContext]` methods returning `ServiceConfiguration`

**Current behaviour** (`ServiceConfiguration.php:68-103`, tests at
`MessagingSystemConfigurationTest.php:673-717`): *not* last-wins. Per field:

1. If the framework-provided (bootstrap) `ServiceConfiguration` has a value → that wins; `#[ServiceContext]`
   values are ignored **without a warning** (`:72`, `:86`).
2. Otherwise, collect every non-null value from all `#[ServiceContext]` configurations. Two **different**
   values → `ConfigurationException` (`:77`, `:91`). Identical values → fine.
3. Nothing set → field default (`application/x-php-serialized` at `:83`; `null` error channel at `:97-99`).

**Recommendation for 2.0: keep exactly this rule and generalise it to every mergeable field.** It is
order-independent (annotation scan order is filesystem-dependent and must never be semantic), it is already
what users of the two supported fields experience, and it is already tested. "Last wins" would make behaviour
depend on directory-listing order — reject it.

The one thing to change: once a field is removed from framework config, rule 1 stops firing for it, so a
previously-silent override becomes a hard `ConfigurationException`. That is a real, intentional behaviour
change and belongs in the upgrade notes (§8.6).

Error messages should name the offenders. Today the message is
`"Ecotone can't resolve defaultErrorChannel. In order to continue you need to set it up."` — it does not say
*which* classes disagree, even though `AnnotationModuleRetrievingService` has the
`{$annotationRegistration->getClassName()}:{$methodName}` string available (`:83,90`). Improving this is cheap
and belongs in G.

Fields that must **not** be mergeable (they are bootstrap-only, §2): `namespaces`, `loadCatalog`,
`modulePackages`, `environment`. Setting them from a `#[ServiceContext]` today is a silent no-op. G should make
it a `ConfigurationException` naming the class and pointing at the framework config file — a silent no-op on a
namespace list is the single most confusing failure mode this whole area has.

Extension objects: `MessagingSystemConfiguration.php:198` only unwraps the framework configuration's
`getExtensionObjects()`. A `#[ServiceContext]` returning
`ServiceConfiguration::createWithDefaults()->addExtensionObject(new Foo())` loses `Foo`. Fix in the same PR as
`mergeWith()`.

---

## 5. Framework specifics

### 5.1 Symfony

**`Configuration.php` tree after the change** — exactly five nodes:

```php
$treeBuilder->getRootNode()
    ->children()
        ->arrayNode('namespaces')->scalarPrototype()->end()->end()
        ->booleanNode('loadSrcNamespaces')->defaultTrue()->end()
        ->arrayNode('modulePackages')->defaultNull()->scalarPrototype()->end()->end()
        ->booleanNode('test')->defaultFalse()->end()
        ->scalarNode('licenceKey')->defaultNull()->end()
    ->end();
```
Removed: `serviceName`, `cacheConfiguration` (dead), `failFast` (dead), `defaultSerializationMediaType`,
`defaultErrorChannel`, `defaultMemoryLimit`, `defaultConnectionExceptionRetry`. Unknown keys already throw
(`vendor/symfony/config/Definition/ArrayNode.php:287-303`), so old YAML fails loudly with
`Unrecognized option "serviceName" under "ecotone"`.

**`EcotoneExtension::load()` changes** — `:38-82` collapses to:

```php
$serviceConfiguration = ServiceConfiguration::createWithDefaults()
    ->withEnvironment($container->getParameter('kernel.environment'))
    ->withLoadCatalog($config['loadSrcNamespaces'] ? 'src' : '')
    ->withNamespaces($config['namespaces']);

if ($config['modulePackages'] !== null) {
    $serviceConfiguration = $serviceConfiguration->withModulePackages($config['modulePackages']);
}
if ($config['licenceKey'] !== null) {
    $serviceConfiguration = $serviceConfiguration->withLicenceKey($config['licenceKey']);
}
```
`RetryTemplateBuilder` (`:13`) and the `withFailFast` call (`:42`) drop out. Everything from `:84` onward —
`SymfonyConfigurationVariableService`, `ServiceCacheConfiguration`, `CacheWarmer`/`CacheClearer`,
`MessagingSystemConfiguration::prepare()` (`:100-105`), the `ecotone.container` registration (`:117-120`),
bridge registration (`:122-127`), console commands (`:152-163`) — is untouched.

**Container parameters other bundles read** — both are computed from the *compiled Ecotone container*, not from
any removed key, so G does not affect them:
- `ecotone.external_references` (`EcotoneExtension.php:150`), read by
  `Compiler/AliasExternalReferenceForTesting.php:16-20`
- `ecotone.messaging_system_configuration.required_references` (`:185`), read by
  `Compiler/RequiredReferencesCompilerPass.php:16`
- the `ecotone.container` service (`:117`) and the `ecotone.testing.*` aliases
  (`ExternalReferenceResolver::TESTING_ALIAS_PREFIX`, `:144`)

Both compiler passes are registered in `packages/Symfony/SymfonyBundle/EcotoneSymfonyBundle.php:22-27` —
unchanged.

### 5.2 Laravel

**`config/ecotone.php` final shape** (comments abbreviated):

```php
return [
    'loadAppNamespaces'  => true,
    'namespaces'         => [],
    // 'modulePackages'  => [],     // absent = load every installed package
    'cacheConfiguration' => env('ECOTONE_CACHE', false),
    'test'               => false,
    'licenceKey'         => null,
];
```
`serviceName` (`:14`), `defaultSerializationMediaType` (`:66`), `defaultErrorChannel` (`:77`),
`defaultConnectionExceptionRetry` (`:92`) are removed together with their comment blocks.

**`EcotoneProvider` changes** — `:47-98` collapses to roughly:

```php
$applicationConfiguration = ServiceConfiguration::createWithDefaults()
    ->withEnvironment($environment)
    ->withLoadCatalog(Config::get('ecotone.loadAppNamespaces') ? 'app' : '')
    ->withNamespaces(Config::get('ecotone.namespaces') ?? []);
```
plus the existing `modulePackages` (`:64-66`) and `licenceKey` (`:68-70`) blocks. `RetryTemplateBuilder`
(`:17`), `withFailFast(false)` (`:60`) and `$errorChannel` (`:53`) drop out. The `@TODO Ecotone 2.0 use
ServiceContext to configure Laravel` marker at `:56` is deleted. `withCacheDirectoryPath($cacheDirectory)`
(`:62`) is the `service-cache-directory` report's concern, not G's (§6).

**New: unknown-key rejection.** Because `mergeConfigFrom()` is a shallow `array_merge`
(`vendor/laravel/framework/src/Illuminate/Support/ServiceProvider.php:163-172`), a stale published config keeps
its removed keys and they become silent no-ops. Add, right after the `mergeConfigFrom()` at
`EcotoneProvider.php:42-45`:

```php
private const ALLOWED_KEYS = ['loadAppNamespaces', 'namespaces', 'modulePackages', 'cacheConfiguration', 'test', 'licenceKey'];
private const MOVED_KEYS = [
    'serviceName'                     => 'ServiceConfiguration::withServiceName()',
    'defaultSerializationMediaType'   => 'ServiceConfiguration::withDefaultSerializationMediaType()',
    'defaultErrorChannel'             => 'ServiceConfiguration::withDefaultErrorChannel()',
    'defaultConnectionExceptionRetry' => 'ServiceConfiguration::withConnectionRetryTemplate()',
    'failFast'                        => null,   // removed in 2.0
];
```
and throw `ConfigurationException` naming the key and its replacement. Message shape:
`config/ecotone.php key "serviceName" was removed in Ecotone 2.0. Set it from a #[ServiceContext] method returning ServiceConfiguration::withServiceName(). See https://docs.ecotone.tech/…`

**`php artisan vendor:publish` implications.** `EcotoneProvider::boot()` publishes with tag `ecotone-config`
(`:180-185`). Existing installs have their own copy in `config/ecotone.php`, so shrinking the package file does
**not** shrink theirs — the guard above is what turns that into a good error. Upgrade instruction:
`php artisan vendor:publish --tag=ecotone-config --force`, then move the removed keys into a `#[ServiceContext]`.
Note also `Application::configurationIsCached()` short-circuits `mergeConfigFrom()` entirely
(`ServiceProvider.php:165`), so a user with `config:cache` from 1.x must run `php artisan config:clear` first;
`EcotoneProvider` already hooks `optimize`/`optimize:clear`/`cache:clear` to drop the Ecotone cache
(`:244-254`).

### 5.3 Tempest

**`EcotoneConfig` final constructor:**

```php
final class EcotoneConfig
{
    public function __construct(
        public array $namespaces = [],
        public bool $loadAppNamespaces = true,
        public ?array $modulePackages = null,
        public bool $cacheConfiguration = false,
        public bool $test = false,
        public string $licenceKey = '',
    ) {
        if (! $this->cacheConfiguration) {
            $this->cacheConfiguration = (bool) env('ECOTONE_CACHE_CONFIGURATION', false);
        }
    }
}
```
Removed: `serviceName` (`:15`) together with its `ECOTONE_SERVICE_NAME` env fallback (`:25-27`),
`defaultSerializationMediaType` (`:19`), `defaultErrorChannel` (`:20`). The `ECOTONE_CACHE_CONFIGURATION`
fallback (`:28-30`) stays — `cacheConfiguration` remains a bootstrap key.
`MessagingSystemInitializer::buildServiceConfiguration()` (`:110-152`) loses `:133-143` and the now-dead
`withFailFast(false)` at `:125`. Unknown keys already error at the PHP level (named-argument constructor).

Tempest reads namespaces from Composer when none are given
(`deriveNamespacesFromComposer()`, `:94-108`) — unaffected.

### 5.4 `EcotoneLite::bootstrap*` — untouched

`bootstrap()` (`packages/Ecotone/src/Lite/EcotoneLite.php:41-62`), `bootstrapFlowTesting()` (`:73-93`) and
`bootstrapFlowTestingWithEventStore()` (`:104-134`) take a `?ServiceConfiguration $configuration` directly,
which `prepareConfiguration()` (`:142-166`) treats exactly like a framework-provided one — including the
`licenceKey` parameter at `:49,162`. G changes no `EcotoneLite` signature. Lite users benefit from the fixed
`mergeWith()` for free: a `#[ServiceContext]` in a test fixture that sets `serviceName` starts working.

---

## 6. Interaction with the two related items

### 6.1 `ServiceConfiguration` cache-directory API (`docs/superpowers/research/service-cache-directory/report.md`)

That report recommends **removing** `with/getCacheDirectoryPath()` from `ServiceConfiguration` and making the
path a parameter of `EcotoneLite::bootstrap()`, deleting the two dead `->withCacheDirectoryPath()` calls in
Laravel and Tempest. Against the current tree:

- The methods are still there: `packages/Ecotone/Api/ServiceConfiguration.php:339-350`, field at `:26`,
  initialised at `:54`. **The `@deprecated use ServiceCacheDirectory` docblock the report quotes no longer
  exists**, and the file has moved from `packages/Ecotone/src/Messaging/Config/` to `packages/Ecotone/Api/`
  under the Group H namespace flattening. The report's line numbers and paths need refreshing before it is
  implemented.
- The dead calls are still there: `packages/Laravel/src/EcotoneProvider.php:62`,
  `packages/Tempest/src/MessagingSystemInitializer.php:127`. Symfony still never touches the field
  (`EcotoneExtension.php:91-95,107-108` builds `ServiceCacheConfiguration` from `%kernel.build_dir%` directly).
- `EcotoneLite::prepareConfiguration()` still reads it at `packages/Ecotone/src/Lite/EcotoneLite.php:171`.

**Should G absorb it? No — but G should be sequenced after it.** They are independent in *scope* (cache-dir is
an `EcotoneLite`/`ServiceConfiguration` API question; G is a framework-config question) but they collide in
*one file*: both rewrite `packages/Ecotone/Api/ServiceConfiguration.php`. G's PR1 makes every `with*()` clone
consistently (§1.5a); the cache-dir report deletes two of those methods. Doing the deletion first means G's
immutability sweep has two fewer methods to touch and no rebase conflict. Recommended order: cache-dir
removal → G PR1 → rest of G. G's design doc should state this as a soft prerequisite, not fold the work in.

One genuine coupling to note in both docs: `ContainerCacheLayout::resolve()` hashes
`sha1(serialize($serviceConfiguration))` (`ContainerCacheLayout.php:53-58` →
`FileSystemAnnotationFinder.php:576`). Removing the cache path from `ServiceConfiguration` removes a value from
that hash; G removes five more. Neither is a problem, because the moved values now live in class files whose
`sha1_file` is already hashed (`FileSystemAnnotationFinder.php:566-569`) — but the two changes must not be
reviewed as if the hash input were fixed.

### 6.2 `EcotoneTestSupportModule.php:145` — "reconsider missing-handler interceptor"

```php
$messagingConfiguration->registerServiceDefinition(AllowMissingDestination::class);       // :143
$allowMissingDestinationInterfaceToCall = $interfaceToCallRegistry->getFor(AllowMissingDestination::class, 'invoke');
/** @TODO Ecotone 2.0, reconsider if needed */                                            // :145
if (! $testConfiguration->isFailingOnCommandHandlerNotFound()) { … }                      // :146-154
if (! $testConfiguration->isFailingOnQueryHandlerNotFound()) { … }                        // :155-163
```

**G should not absorb this.** It is driven by `TestConfiguration`, not `ServiceConfiguration`, and
`TestConfiguration` is resolved from the extension-object list via
`ExtensionObjectResolver::resolveUnique()` (`EcotoneTestSupportModule.php:110`) — a completely different path
from `ServiceConfiguration::mergeWith()`. It was never a framework config key on any of the three
integrations. It belongs with the async-test-semantics group in the release design
(`docs/superpowers/specs/2026-08-22-ecotone-2-0-release-design.md:170-175`), which is where "should a missing
handler be a hard failure in tests?" is actually being decided.

The only interaction worth writing down: G changes how `ServiceConfiguration` extension objects are unwrapped
(`MessagingSystemConfiguration.php:198`, §4). `TestConfiguration` returned directly from a `#[ServiceContext]`
is unaffected; a `TestConfiguration` nested inside a `#[ServiceContext]`-returned
`ServiceConfiguration::addExtensionObject()` is *currently dropped* and starts working after G. Regression-test
that at `EcotoneTestSupportModule.php:110`.

---

## 7. Prior art

| Source | What it says | Bearing on G |
|---|---|---|
| **Symfony FrameworkBundle** — `vendor/symfony/framework-bundle/DependencyInjection/Configuration.php:1682-1880` (`messenger`) | Everything that shapes the compiled container — transports, routing, buses, middleware — is in the config tree; nothing is discovered from user attributes at compile time | Symfony's own line is "compile-time inputs go in the tree". Ecotone's line is different only because it *does* scan attributes at compile time — which is exactly why the split has to be drawn at `createForAttributes()`, not by analogy |
| **Symfony `ArrayNode`** — `vendor/symfony/config/Definition/ArrayNode.php:31,287-303` | `$ignoreExtraKeys = false` by default; unknown keys throw `Unrecognized option "x" under "y"` | Confirms the Symfony half of "the bundle rejects unknown keys" needs **no new code** — deleting the node is sufficient |
| **DoctrineBundle** — `vendor/doctrine/doctrine-bundle/src/DependencyInjection/Configuration.php:59-86` | Keeps connection/DBAL wiring in YAML but pushes per-entity behaviour to attributes on user classes | The same two-tier split G proposes: infrastructure the container needs first in config, domain-shaped behaviour in attributes |
| **Doctrine ORM `Configuration`** | `setProxyDir()` and `setAutoGenerateProxyClasses()` are separate concerns, both set programmatically at bootstrap, never from a scanned attribute | Supports keeping `cacheConfiguration` (and, per §6.1, the cache directory) out of the attribute layer |
| **Laravel `ServiceProvider`** — `vendor/laravel/framework/src/Illuminate/Support/ServiceProvider.php:163-172` (`mergeConfigFrom`) and `publishes()` | Shallow `array_merge` of package defaults under the user's published copy; no validation of the user's keys anywhere in the framework | Laravel package convention gives us **no** unknown-key rejection; G must add it explicitly (§5.2). Also explains why the published copy does not shrink when the package file does |
| **Tempest config objects** — e.g. `vendor/tempest/framework/packages/database/src/Config/DatabaseConfig.php:11-40`, discovered from `*.config.php` files, cached via `vendor/tempest/framework/packages/core/src/ConfigCache.php` | Typed constructor-promoted objects, not arrays; unknown keys are a PHP error | Tempest's shape already matches the target: `EcotoneConfig` just needs fewer constructor parameters |

---

## 8. Edge cases

### 8.1 Config caching vs `#[ServiceContext]` values that change

The cache key is built in `FileSystemAnnotationFinder::getCacheMessagingFileNameBasedOnConfig()` (`:556-581`):

```php
foreach ($this->registeredClasses() as $class) { $fileSha .= $class . sha1_file($filePath); }   // :566-569
$fileSha .= sha1_file($rootCatalog . '/composer.lock');                                          // :571-574
$fileSha .= sha1(serialize($serviceConfiguration));                                              // :576
$fileSha .= sha1(serialize($this->skipClosures($configurationVariables)));                       // :577
$fileSha .= $enableTesting ? 'true' : 'false';                                                   // :578
```

- **Editing the `#[ServiceContext]` class is covered** by `:566-569`. Moving values out of framework config and
  into a class *improves* invalidation relative to Symfony YAML, which participates only via Symfony's own
  container staleness checks.
- **A raw `getenv()`/`env()` read inside a `#[ServiceContext]` method is not covered.** The class file is
  unchanged, so the hash is unchanged, so a stale container is served. Mitigation is documentation +
  `#[ConfigurationVariable]`: Laravel hashes `Config::all()` (`EcotoneProvider.php:218`); Symfony's compiled
  container is rebuilt when `resolveEnvPlaceholders()` inputs change (`EcotoneExtension.php:36`,
  `SymfonyConfigurationVariableService.php:26`).
- **Tempest is the gap**: `MessagingSystemInitializer.php:175-182` calls `ContainerCacheLayout::resolve()`
  without `configurationVariables`, so it defaults to `[]` (`ContainerCacheLayout.php:39`) and *no* env value —
  `#[ConfigurationVariable]` or not — participates in the hash. Fix in G (§10 PR6).
- `ContainerCacheLayout::containsAnonymousClass()` (`:77-86`) already disables caching when any registered class
  is anonymous — unrelated but worth knowing when testing this area.

### 8.2 Tests bootstrapping with `test: true`

`test` stays a bootstrap key, so nothing changes for
`packages/Laravel/tests/Application/config/ecotone.php:13`,
`Monorepo/ExampleApp/Symfony/config/services_test_es.php:16`, or Tempest's
`EcotoneIntegrationTestCase::ecotoneConfig()` (`packages/Tempest/tests/EcotoneIntegrationTestCase.php:27-32`).
`EcotoneLite::bootstrapFlowTesting()` passes `enableTesting: true` directly (`EcotoneLite.php:91`).

### 8.3 Multi-app / monorepo

`Monorepo/ExampleApp` and `Monorepo/ExampleAppEventSourcing` each ship **four** bindings (Symfony, Laravel,
Lite, plus test variants) that today repeat the same values in framework-specific syntax:

- `Monorepo/ExampleApp/Symfony/config/services.php:15-21` — `loadSrcNamespaces`, `namespaces`,
  `defaultErrorChannel`, `failFast`, `modulePackages`
- `Monorepo/ExampleApp/Laravel/config/ecotone.php:6-9` — `namespaces`, `modulePackages`, `cacheConfiguration`,
  `defaultErrorChannel`
- `Monorepo/ExampleAppEventSourcing/Symfony/config/services.php:13-20` — plus
  `defaultSerializationMediaType: 'application/json'`
- `Monorepo/ExampleAppEventSourcing/Laravel/config/ecotone.php:3-8`

After G, `defaultErrorChannel` and `defaultSerializationMediaType` move into a single `#[ServiceContext]` in
the shared `Monorepo/ExampleApp*/Common/` namespace (already scanned by every binding), removing the
duplication. `failFast: false` (`services.php:19`) is simply deleted. This is the strongest in-repo argument
*for* G: four copies collapse to one.

Risk: a `#[ServiceContext]` in a shared namespace applies to **every** app scanning it. Where two apps in a
monorepo genuinely need different values, use `#[Environment]` (§3.1) or keep the classes in
per-app namespaces.

### 8.4 Quickstart examples that use framework config keys today

Full inventory (`find . -name ecotone.php -o -name ecotone.yaml`, `grep -rn "extension('ecotone'"`), excluding
`packages/*/config`:

**Must migrate (2 files, `defaultErrorChannel` / `defaultSerializationMediaType` / `failFast`):**
- `Monorepo/ExampleApp/Symfony/config/services.php:18,19`
- `Monorepo/ExampleAppEventSourcing/Symfony/config/services.php:14,17,18`

**Must migrate (2 files, `defaultErrorChannel`):**
- `Monorepo/ExampleApp/Laravel/config/ecotone.php:9`
- `Monorepo/ExampleAppEventSourcing/Laravel/config/ecotone.php:7`

**No change needed — bootstrap keys only:**
- `packages/Symfony/tests/phpunit/{Licence,EnvPlaceholderEndpoint,EnvPlaceholderKafka,SingleTenant,MultiTenant,DbalConnectionRequirement,DbalConnectionRequirementWithConnection}/config/services.php` — all use only `modulePackages` and/or `licenceKey`
- `packages/Symfony/tests/phpunit/SingleTenant/config/packages/ecotone.yaml:1-3` — `ecotone:` with everything commented out
- `packages/Laravel/tests/{Application,MissingReference,Licence,MultiTenant,DbalConnectionRequirement,DbalConnectionRequirementWithConnection}/config/ecotone.php` — `namespaces`, `modulePackages`, `test`, `loadAppNamespaces`, `licenceKey` only
- `quickstart-examples/ConsoleCommand/Laravel/config/ecotone.php`,
  `quickstart-examples/MultiTenant/Laravel/{Aggregate,AsynchronousEvents,Events,EventSourcing,MessageBus}/config/ecotone.php`,
  `quickstart-examples/Laravel/Projection/{DatabaseReadModel,EloquentReadModel}/config/ecotone.php` — all `return [];`
- every `quickstart-examples/*/Symfony/config/services.php` — none calls `extension('ecotone', …)`;
  e.g. `quickstart-examples/ConsoleCommand/Symfony/config/services.php` only loads services
- `quickstart-examples/Tempest/**/app/*.config.php` — Tempest `DatabaseConfig`, not `EcotoneConfig`

So the quickstart surface is essentially clean; the work is concentrated in `Monorepo/ExampleApp*`.

### 8.5 Laravel `config:cache`

`mergeConfigFrom()` is skipped entirely when the app config is cached
(`ServiceProvider.php:165`), so a user upgrading with a 1.x `bootstrap/cache/config.php` in place never sees the
new package defaults *or* the unknown-key guard until they run `php artisan config:clear`. Put that in the
upgrade notes as the first step of the Laravel section.

### 8.6 Silent override becomes a hard error

Today `Monorepo/ExampleApp/Symfony/config/services.php:18` sets `defaultErrorChannel` in framework config; if a
`#[ServiceContext]` in `Monorepo\ExampleApp\Common\` also set one, the framework value would win silently
(`ServiceConfiguration.php:86`). After G that key is gone from framework config, so two disagreeing
`#[ServiceContext]` methods now throw (`:91`). Users with a "framework config is the override" mental model
will hit this. It is the correct behaviour and it is loud — document it.

### 8.7 Symfony compile-time env resolution

`EcotoneExtension.php:36` and `SymfonyConfigurationVariableService.php:21-27` both resolve `%env(...)%` at
container-build time. A `%env()%`-backed `licenceKey` or `#[ConfigurationVariable]` value is therefore baked
into the dumped container; changing the env var without rebuilding the container has no effect. This is true
before and after G — no regression, but §3.3's Symfony example must not imply runtime env reads.

---

## 9. Final text for `upgrade-2.0.md` §12

Replacing `upgrade-2.0.md:278-305` (drop the "Planned — not implemented yet" banner once landed):

> ## 12. Framework configuration: `ServiceContext` only
>
> **Before:** Symfony `config/packages/ecotone.yaml`, Laravel `config/ecotone.php` and Tempest `EcotoneConfig`
> each exposed most `ServiceConfiguration` options.
>
> **Now:** framework config files keep only the keys Ecotone must know *before* it scans your code — the
> namespaces to scan, whether to scan `src/`/`app/`, which module packages to load, whether to use the
> configuration cache, whether the test package is enabled, and the Enterprise licence key. Everything else is
> set from a `#[ServiceContext]` method returning `ServiceConfiguration`. Unknown keys are rejected.
>
> **Remaining keys**
>
> | Framework | Keys still accepted |
> |---|---|
> | Symfony (`ecotone.yaml`) | `namespaces`, `loadSrcNamespaces`, `modulePackages`, `test`, `licenceKey` |
> | Laravel (`config/ecotone.php`) | `namespaces`, `loadAppNamespaces`, `modulePackages`, `cacheConfiguration`, `test`, `licenceKey` |
> | Tempest (`EcotoneConfig`) | `namespaces`, `loadAppNamespaces`, `modulePackages`, `cacheConfiguration`, `test`, `licenceKey` |
>
> Symfony has no `cacheConfiguration` key — the bundle follows `kernel.build_dir` and Symfony's own container
> caching.
>
> **Migration table**
>
> | Symfony YAML key | Laravel key | Tempest property | Replace with |
> |---|---|---|---|
> | `serviceName` | `serviceName` | `serviceName` | `ServiceConfiguration::withServiceName(string)` |
> | `defaultSerializationMediaType` | `defaultSerializationMediaType` | `defaultSerializationMediaType` | `ServiceConfiguration::withDefaultSerializationMediaType(string\|MediaType)` |
> | `defaultErrorChannel` | `defaultErrorChannel` | `defaultErrorChannel` | `ServiceConfiguration::withDefaultErrorChannel(string)` |
> | `defaultMemoryLimit` | — | — | `ServiceConfiguration::withConsumerMemoryLimit(int)` |
> | `defaultConnectionExceptionRetry: {initialDelay, maxAttempts, multiplier}` | `defaultConnectionExceptionRetry` | — | `ServiceConfiguration::withConnectionRetryTemplate(RetryTemplateBuilder::exponentialBackoffWithMaxDelay($initialDelay, $maxAttempts, $multiplier))` |
> | `failFast` | — | — | **removed** — it had no effect since 1.x; delete the key |
> | `cacheConfiguration` (Symfony only) | *(kept in Laravel/Tempest)* | *(kept)* | **removed from Symfony** — it was never read; Symfony uses `kernel.build_dir` |
>
> **How to adapt**
>
> ```php
> namespace App\Infrastructure;
>
> use Ecotone\Api\{ConfigurationVariable, Environment, ServiceConfiguration, ServiceContext};
> use Ecotone\Messaging\Conversion\MediaType;
> use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
>
> final class EcotoneConfiguration
> {
>     #[ServiceContext]
>     public function service(
>         #[ConfigurationVariable('app.ecotone.service_name')] string $serviceName,
>     ): ServiceConfiguration {
>         return ServiceConfiguration::createWithDefaults()
>             ->withServiceName($serviceName)
>             ->withDefaultSerializationMediaType(MediaType::APPLICATION_JSON)
>             ->withDefaultErrorChannel('errorChannel')
>             ->withConnectionRetryTemplate(RetryTemplateBuilder::exponentialBackoffWithMaxDelay(100, 3, 3));
>     }
>
>     #[ServiceContext]
>     #[Environment(['prod', 'production'])]
>     public function productionTuning(): ServiceConfiguration
>     {
>         return ServiceConfiguration::createWithDefaults()->withConsumerMemoryLimit(512);
>     }
> }
> ```
>
> `#[ConfigurationVariable]` resolves against a Symfony container parameter, a Laravel `config()` path, or a
> Tempest env var / `SomeConfig::class . '::property'`. `#[Environment]` filters `#[ServiceContext]` methods per
> environment, exactly as it does handlers.
>
> **Precedence.** Two `#[ServiceContext]` methods setting the *same* option to *different* values is a
> `ConfigurationException` — it is not "last wins". Keys that previously lived in framework config used to win
> silently over a `#[ServiceContext]`; now that they are gone, a conflict between two `#[ServiceContext]`
> methods is reported instead of hidden.
>
> **Setting a bootstrap key from `#[ServiceContext]` is an error.** `withNamespaces()`, `withLoadCatalog()`,
> `withModulePackages()` and `withEnvironment()` cannot be honoured from a `#[ServiceContext]` — they decide
> which classes get scanned in the first place. Ecotone now throws instead of ignoring them.
>
> **Laravel:** run `php artisan config:clear`, then `php artisan vendor:publish --tag=ecotone-config --force`,
> then move the removed keys into a `#[ServiceContext]`. A leftover removed key in `config/ecotone.php` throws
> at boot with the replacement method named.
>
> **Symfony:** a leftover key fails container compilation with
> `Unrecognized option "serviceName" under "ecotone"`.
>
> **Tempest:** a leftover constructor argument is a PHP `Unknown named parameter` error.

*(One typo to fix when transcribing: "they decide" → "they decide".)*

---

## 10. Implementation plan

Sequential, docker only (`compose` services are shared; never run two package suites in parallel).
Prerequisite, tracked separately: the `service-cache-directory` removal (§6.1) lands first so PR1 does not
conflict with it.

| PR | Scope | Files | Tests |
|---|---|---|---|
| **1** | Make `ServiceConfiguration` uniformly immutable | `packages/Ecotone/Api/ServiceConfiguration.php:210,217,238,258` (clone instead of mutate); fix the two callers that relied on mutation: `MessagingSystemConfiguration.php:207-219` (assign the return value) and `addCorePackage()` `:287-300` | `packages/Ecotone` — `MessagingSystemConfigurationTest`, plus a new "with\*() does not mutate the receiver" unit test |
| **2** | Generalise `mergeWith()` | `ServiceConfiguration.php:68-103` — add `serviceName`, `defaultMemoryLimit`, `connectionRetryTemplate`, `licenceKey` under the existing "framework wins / unanimity or throw" rule; make `defaultMemoryLimitInMegabytes` nullable (`:40`) and move the `1024` default to `MessagingContainerBuilder.php:133`; unwrap `#[ServiceContext]` `ServiceConfiguration::getExtensionObjects()` at `MessagingSystemConfiguration.php:198`; name the offending classes in the two conflict messages (`:77,91`) | `packages/Ecotone` — extend `MessagingSystemConfigurationTest.php:673-717` with one merge/conflict pair per newly-merged field |
| **3** | Bootstrap-only keys throw from `#[ServiceContext]` | `ServiceConfiguration` gains a "was set" marker for `namespaces`/`loadCatalog`/`modulePackages`/`environment`; `MessagingSystemConfiguration.php:199-205` throws `ConfigurationException` naming the class and the framework config file | `packages/Ecotone` |
| **4** | Optional `#[ConfigurationVariable]` in `#[ServiceContext]` | `AnnotationModuleRetrievingService.php:68-79` — route through the same `hasName()`/default/throw logic as `ValueConverter::fromConfigurationVariableService()` (`ValueConverter.php:32-42`) | `packages/Ecotone`, then `packages/Symfony` (the `ParameterNotFoundException` case) |
| **5** | Symfony | `packages/Symfony/DependencyInjection/Configuration.php` → 5 nodes; `EcotoneExtension.php:38-82` trimmed, `RetryTemplateBuilder` import dropped | `packages/Symfony` (docker; includes the `Licence`, `EnvPlaceholder*`, `SingleTenant`, `MultiTenant`, `DbalConnectionRequirement*` apps) |
| **6** | Laravel | `packages/Laravel/config/ecotone.php` trimmed; `EcotoneProvider.php:47-98` trimmed, `@TODO` at `:56` removed; unknown-key + moved-key guard added after `:45` | `packages/Laravel` (docker) |
| **7** | Tempest | `EcotoneConfig.php` → 6 properties; `MessagingSystemInitializer.php:110-152` trimmed; pass `configurationVariables` into `ContainerCacheLayout::resolve()` at `:175-182` (§8.1) | `packages/Tempest` (docker; `ProdCacheHashTest`, `ProductionCacheInvalidationTest` are the ones to watch) |
| **8** | Migrate examples | `Monorepo/ExampleApp/Symfony/config/services.php:15-21`; `Monorepo/ExampleApp/Laravel/config/ecotone.php:9`; `Monorepo/ExampleAppEventSourcing/Symfony/config/services.php:13-20`; `Monorepo/ExampleAppEventSourcing/Laravel/config/ecotone.php:7`; new `#[ServiceContext]` in `Monorepo/ExampleApp*/Common/` | Monorepo suites + `packages/OpenTelemetry` (it boots `Monorepo/ExampleApp` — `Monorepo/ExampleApp/Symfony/config/services.php:36-40`) |
| **9** | Docs & skills | `upgrade-2.0.md:278-305` (§9 text); `.claude/skills/ecotone-symfony-setup/SKILL.md:52-60,147`; `.claude/skills/ecotone-symfony-setup/references/configuration-reference.md:3-55`; `.claude/skills/ecotone-laravel-setup/references/configuration-reference.md:8-52`; `.claude/skills/ecotone-laravel-setup/SKILL.md`; `.claude/skills/ecotone-enterprise/references/configuration-guide.md:9-56` | — |

Files to migrate, complete list: the 4 `Monorepo/ExampleApp*` config files (§8.4) and the 5 skill-doc files
(PR9). No quickstart example needs a change.

---

## 11. Open questions for the maintainer

1. **`licenceKey` placement.** §2.5 recommends keeping it in framework config (cache-invalidation integrity,
   secret shape, symmetry with `EcotoneLite::bootstrap(licenceKey:)`), while still merging it from
   `#[ServiceContext]`. Confirm — the alternative (ServiceContext-only) is defensible but changes how a licence
   participates in the container hash.
2. **Symfony `cacheConfiguration`.** The node exists (`Configuration.php:27-29`) and is never read;
   `EcotoneExtension.php:94,108` hard-codes `shouldUseCache: true`. Delete the node (my recommendation, since
   Symfony's own `kernel.build_dir`/debug machinery already governs this), or wire it up and change Symfony's
   caching behaviour? These are different products.
3. **`ServiceConfiguration` immutability.** PR1 turns four mutating `with*()` methods into cloning ones
   (`:210,217,238,258`). Any code outside this repo relying on `$config->withLicenceKey($k)` mutating in place
   breaks silently. Acceptable as a 2.0 break, or does it need a deprecation shim?
4. **`failFast`.** Delete outright (§2.6), or is there an intended meaning to restore before 2.0 ships?
5. **Bootstrap keys set from `#[ServiceContext]`** (PR3). Throw, as recommended? Today `withNamespaces()` from a
   `#[ServiceContext]` is a silent no-op, which is the worst option — but a throw could break someone who is
   currently doing it harmlessly.
6. **Deprecation window.** 2.0 is a major, so the plan above removes keys outright. Should Symfony/Laravel
   instead accept the removed keys for one minor with a `trigger_deprecation()`, given Symfony's rejection is a
   hard container-compile failure?
