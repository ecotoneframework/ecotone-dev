# ServiceContext-only Configuration — 2.0 Design (Group G)

Status: draft — awaiting maintainer approval
Date: 2026-08-28
Release group: G (framework config files keep bootstrap keys only; everything else via `#[ServiceContext]`)
Soft prerequisite: `docs/superpowers/research/service-cache-directory/report.md` (both rewrite `ServiceConfiguration`)
Research: `docs/superpowers/research/servicecontext-only-config/report.md`
Tree: `dgafka/ecotone-2-0-work` @ `bcf4efc0`

## Problem

Symfony `config/packages/ecotone.yaml`, Laravel `config/ecotone.php` and Tempest `EcotoneConfig` each expose
most of `ServiceConfiguration`'s surface. `upgrade-2.0.md:278-305` states the target — framework files keep
only bootstrap keys, everything else moves to a `#[ServiceContext]` method returning `ServiceConfiguration` —
and marks it "Planned — not implemented yet".

Investigating it turned up two facts that change what Group G actually is.

| # | Finding | Evidence |
|---|---|---|
| 1 | **The destination does not work.** `ServiceConfiguration::mergeWith()` merges exactly two fields — `defaultSerializationMediaType` and `defaultErrorChannel`. Everything else on a `#[ServiceContext]`-returned `ServiceConfiguration` is discarded | `packages/Ecotone/Api/ServiceConfiguration.php:68-103`; the discard happens at `MessagingSystemConfiguration.php:224-233,248` |
| 2 | `failFast` is dead — written by all three integrations, read by nobody | no reader in `packages/*/src`; `git log -S "isFailingFast()"` → `24b8a32c` (container-compilation PoC) |
| 3 | Symfony's `cacheConfiguration` node is dead — declared, never read; `shouldUseCache` is hard-coded `true` | `packages/Symfony/DependencyInjection/Configuration.php:27-29` vs `EcotoneExtension.php:91-95,107-108` |
| 4 | Four `with*()` methods **mutate** `$this` instead of cloning, and two call sites depend on that | mutating: `ServiceConfiguration.php:210,217,238,258`. Dependent: `MessagingSystemConfiguration.php:207-219` (discards the return value), `addCorePackage()` `:287-300` |
| 5 | `defaultMemoryLimitInMegabytes` defaults to `1024`, not `null`, so the existing "framework value wins if set" merge test cannot be reused for it | `ServiceConfiguration.php:40`, `PollingMetadata.php:21`, consumed unconditionally at `MessagingContainerBuilder.php:133` |
| 6 | `#[ServiceContext]` `#[ConfigurationVariable]` parameters have **no optional/default path** — on Symfony a missing container parameter throws `ParameterNotFoundException` | `AnnotationModuleRetrievingService.php:78` calls `getByName()` unguarded; contrast the handler path at `ValueConverter.php:32-42` |
| 7 | A `#[ServiceContext]`-returned `ServiceConfiguration`'s own `getExtensionObjects()` are dropped | `MessagingSystemConfiguration.php:198` unwraps only the framework-provided configuration |
| 8 | Symfony already rejects unknown keys; Laravel silently accepts them | `vendor/symfony/config/Definition/ArrayNode.php:31,287-303` vs `vendor/laravel/framework/src/Illuminate/Support/ServiceProvider.php:163-172` |

So Group G is: **fix the `#[ServiceContext]` destination, then delete the framework-config source.** The
config-file edits are the last third of the work, not the first.

## Goals / Non-goals

**Goals**
1. Every `ServiceConfiguration` option that is not needed before the annotation scan is settable — and actually
   honoured — from a `#[ServiceContext]` method.
2. Framework config files keep only what must be known before the container is built, and reject everything else
   with a message that names the replacement.
3. One precedence rule, applied uniformly, that does not depend on filesystem scan order.
4. No silent no-ops anywhere in this area — every ignored value becomes either honoured or an exception.
5. Environment-specific configuration keeps working, via `#[Environment]` + `#[ConfigurationVariable]`.

**Non-goals**
- Changing `EcotoneLite::bootstrap*` signatures (§Public API).
- The cache-directory API (`service-cache-directory` report — sequenced before, not folded in).
- `EcotoneTestSupportModule.php:145`'s missing-handler interceptor (`TestConfiguration`-scoped, belongs to the
  async-test-semantics group; see §Interactions).
- A deprecation window on the removed keys (see Open Question 6).

## Prior art

| Source | What it gives us |
|---|---|
| Symfony FrameworkBundle `messenger` tree (`vendor/symfony/framework-bundle/DependencyInjection/Configuration.php:1682-1880`) | Symfony's own line is "everything shaping the compiled container lives in the tree". Ecotone's line differs only because Ecotone *scans attributes* at compile time — which is why our split must be drawn at `AnnotationFinderFactory::createForAttributes()`, not by analogy |
| Symfony `ArrayNode` (`vendor/symfony/config/Definition/ArrayNode.php:31,287-303`) | `$ignoreExtraKeys = false` by default → `Unrecognized option "x" under "y"`. Deleting a node **is** the rejection; no new Symfony code needed |
| DoctrineBundle (`vendor/doctrine/doctrine-bundle/src/DependencyInjection/Configuration.php:59-86`) | The same two-tier split: connection/DBAL wiring in YAML, per-entity behaviour on user classes |
| Doctrine ORM `Configuration::setProxyDir()` + `setAutoGenerateProxyClasses()` | "Where to cache" and "whether to cache" are bootstrap concerns set programmatically, never discovered from a scanned attribute — supports keeping `cacheConfiguration` out of `#[ServiceContext]` |
| Laravel `ServiceProvider::mergeConfigFrom()` / `publishes()` (`ServiceProvider.php:163-172,180+`) | Shallow `array_merge`, no validation anywhere in the framework. Laravel package convention gives us nothing here — G must add the guard |
| Tempest config objects (`vendor/tempest/framework/packages/database/src/Config/DatabaseConfig.php:11-40`, cached via `packages/core/src/ConfigCache.php`) | Typed constructor-promoted objects; unknown keys are a PHP error. `EcotoneConfig` already has the target shape and just needs fewer parameters |

## Decision

**Draw the line at `AnnotationFinderFactory::createForAttributes()`.** Anything that is an argument to it
cannot come from a `#[ServiceContext]`, because that call is what *finds* `#[ServiceContext]` methods. Two call
sites, identical arguments:

```php
// MessagingSystemConfiguration::prepare()  :688-696
AnnotationFinderFactory::createForAttributes(
    realpath($rootPathToSearchConfigurationFor),
    $serviceConfiguration->getNamespaces(),             // :690  → namespaces
    $serviceConfiguration->getEnvironment(),            // :691  → environment
    $serviceConfiguration->getLoadedCatalog() ?? '',    // :692  → loadSrcNamespaces / loadAppNamespaces
    self::getModuleClassesFor($serviceConfiguration),   // :693  → modulePackages
    $userLandClassesToRegister,
    $enableTestPackage,                                 // :695  → test
);
// ContainerCacheLayout::resolve()  :44-52  — same five
```

Plus one that sits *earlier* still: `cacheConfiguration` decides whether the scan happens at all
(`EcotoneProvider.php:204-210`, `MessagingSystemInitializer.php:164-173` both return a dumped container before
`ContainerCacheLayout::resolve()` is reached).

| Key | Verdict | Why |
|---|---|---|
| `namespaces` | **stays** | arg 2; `FileSystemAnnotationFinder::init()` `:138-140` decides which PSR-4 roots to walk |
| `loadSrcNamespaces` / `loadAppNamespaces` | **stays** | arg 4, same `init()` |
| `modulePackages` | **stays** | arg 5 via `getModuleClassesFor()` (`:277-285`) — deciding which module classes to scan by scanning is circular; `withModulePackages()` (`:238-246`) is subtractive against `allPackages()`, so partial values could not compose anyway |
| `cacheConfiguration` | **stays** (Laravel/Tempest) | pre-scan short-circuit. **Deleted on Symfony** — the node is never read |
| `test` | **stays** | arg 7 (`$isRunningForTesting`, gating `init()` `:140`) **and** `addCorePackage()` `:285-300` |
| `licenceKey` | **stays** | see below |
| `serviceName` | **moves** | only read in module-compile passes: `DistributedBusWithServiceMapModule.php:80,91,121,136,151,165,181`, `DistributedHandlerModule.php:106` |
| `defaultSerializationMediaType` | **moves** | already merged (`ServiceConfiguration.php:72-84`) |
| `defaultErrorChannel` | **moves** | already merged (`:86-100`); consumed at `MessagingContainerBuilder.php:129-131` |
| `defaultMemoryLimit` | **moves** | only `MessagingContainerBuilder.php:133-136` |
| `defaultConnectionExceptionRetry` | **moves** | only `MessagingContainerBuilder.php:137-140` + the prod/dev default at `MessagingSystemConfiguration.php:207-219` |
| `failFast` | **deleted** | no reader since `24b8a32c` |

### `licenceKey` stays in framework config

Mechanically it could move: it is read at `MessagingSystemConfiguration.php:250-253`, *after* the merge at
`:205`, and it is not an argument to `createForAttributes()`. It stays anyway, for three reasons:

1. **Cache-invalidation integrity.** `isRunningForEnterpriseLicence` changes which container is compiled —
   `ProjectingModule.php:69` and `AmqpModule.php:113` throw without it; `AggregrateModule.php:344`,
   `OrchestratorModule.php:213`, `DataProtectionModule.php:193` register different definitions. In framework
   config the value is inside the framework's own invalidation input (Laravel hashes `Config::all()` —
   `EcotoneProvider.php:218` → `FileSystemAnnotationFinder.php:577`; Symfony rebuilds when a `%env()%`-resolved
   value changes — `EcotoneExtension.php:36`). Read from a raw `getenv()` inside a `#[ServiceContext]`, it is
   invisible to the hash — you could deploy a licence and keep serving an open-core container.
2. **Secret shape.** Every existing example already routes it through the framework's env indirection:
   `'%env(SYMFONY_LICENCE_KEY)%'` (`packages/Symfony/tests/phpunit/Licence/config/services.php:9`,
   `EnvPlaceholderKafka/config/services.php:13`, `EnvPlaceholderEndpoint/config/services.php:9`),
   `env('LARAVEL_LICENCE_KEY')` (`packages/Laravel/tests/Licence/config/ecotone.php:7`).
3. **Symmetry with `EcotoneLite`**, where the licence is already a bootstrap parameter, not a
   `ServiceConfiguration` field users set (`EcotoneLite.php:49,162`).

It remains **mergeable** from `#[ServiceContext]` under the uniform rule below, so `EcotoneLite` and
`LicenceTesting::VALID_LICENCE` keep working — framework config simply wins.

### Precedence: keep today's rule, generalise it

`ServiceConfiguration::mergeWith()` (`:68-103`) already implements, for its two fields:

1. framework/bootstrap value set → it wins; `#[ServiceContext]` values ignored (`:72`, `:86`)
2. otherwise, unanimity among `#[ServiceContext]` values; two different non-null values → `ConfigurationException`
   (`:77`, `:91`)
3. nothing set → field default (`application/x-php-serialized` at `:83`; `null` error channel at `:97-99`)

Tested at `packages/Ecotone/tests/Messaging/Unit/Config/MessagingSystemConfigurationTest.php:673-717`.

**It is not last-wins, and it must not become last-wins** — annotation scan order is filesystem-dependent and
must never be semantic. Generalise this exact rule to `serviceName`, `defaultMemoryLimit`,
`connectionRetryTemplate` and `licenceKey`.

| Option | Verdict |
|---|---|
| **A — generalise the existing "framework wins / unanimity or throw" rule** | **Chosen.** Order-independent, already what users of the two supported fields experience, already tested |
| B — last `#[ServiceContext]` wins | Rejected — makes behaviour depend on directory-listing order |
| C — first wins | Rejected — same defect, plus it hides later configuration entirely |
| D — always throw on more than one `ServiceConfiguration` | Rejected — `JMSDefaultSerialization` (`packages/JmsConverter/src/Configuration/JMSDefaultSerialization.php:14-19`) is a framework-shipped `#[ServiceContext]` returning one; users would be unable to add their own |

### Silent no-ops become exceptions

Setting `withNamespaces()`, `withLoadCatalog()`, `withModulePackages()` or `withEnvironment()` from a
`#[ServiceContext]` is a silent no-op today — the worst failure mode in this area, because the user sees a
namespace list they wrote being ignored with no signal. G makes it a `ConfigurationException` naming the class,
the method and the framework config file to use instead.

## Public API

### `ServiceConfiguration` (`packages/Ecotone/Api/ServiceConfiguration.php`)

Uniformly immutable. Four methods change from mutate-and-return-`$this` to clone-and-return:

```php
public function withConnectionRetryTemplate(RetryTemplateBuilder $t): self   // :210  was mutating
public function withLicenceKey(string $licenceKey): self                     // :217  was mutating
public function withModulePackages(array $packagesToLoad): self              // :238  was mutating
public function withConsumerMemoryLimit(int $megabytes): self                // :258  was mutating
```

`defaultMemoryLimitInMegabytes` becomes genuinely nullable:

```php
- private ?int $defaultMemoryLimitInMegabytes = PollingMetadata::DEFAULT_MEMORY_LIMIT_MEGABYTES;   // :40
+ private ?int $defaultMemoryLimitInMegabytes = null;
```
with the `1024` default applied at the point of use (`MessagingContainerBuilder.php:133-136`), so "unset" is
distinguishable from "explicitly 1024" and the merge rule can be applied.

`withFailFast()` / `failFast()` / `isFailingFast()` and the `$failFast` field are **removed**
(`:25,107-115,334-337,363-366`); `DEFAULT_FAIL_FAST` (`:21`) goes with them.

`mergeWith()` (`:68-103`) is rewritten as a per-field loop over a fixed field list, keeping the three-rule
semantics and adding the offending class names to the two exception messages (`:77`, `:91`) — today they say
only *what* conflicted, never *where*, even though `AnnotationModuleRetrievingService` has
`{$className}:{$methodName}` in hand (`:83,90`).

### `MessagingSystemConfiguration` (`packages/Ecotone/src/Messaging/Config/MessagingSystemConfiguration.php`)

- `:198` also unwraps `getExtensionObjects()` from `#[ServiceContext]`-returned `ServiceConfiguration`s
  (finding 7).
- `:199-205` throws `ConfigurationException` when a `#[ServiceContext]` `ServiceConfiguration` carries a
  bootstrap-only field.
- `:207-219` assigns the return value of `withConnectionRetryTemplate()` instead of relying on mutation.
- `addCorePackage()` (`:287-300`) likewise stops relying on `withModulePackages()` mutating its argument.

### `AnnotationModuleRetrievingService` (`.../Config/Annotation/AnnotationModuleRetrievingService.php:68-79`)

`#[ConfigurationVariable]` parameters on `#[ServiceContext]` methods gain the optional/default path already
present for handler parameters (`ValueConverter::fromConfigurationVariableService()`, `ValueConverter.php:32-42`):
check `hasName()`, fall back to the PHP default, otherwise throw a named error. Without this, the migration
Group G asks users to perform hard-fails on Symfony with `ParameterNotFoundException`.

### `EcotoneLite::bootstrap*` — unchanged

`bootstrap()` (`packages/Ecotone/src/Lite/EcotoneLite.php:41-62`), `bootstrapFlowTesting()` (`:73-93`) and
`bootstrapFlowTestingWithEventStore()` (`:104-134`) take `?ServiceConfiguration $configuration`, which
`prepareConfiguration()` (`:142-166`) treats exactly like a framework-provided one — including `licenceKey`
(`:49,162`). No signature changes. Lite users get the fixed `mergeWith()` for free: a `#[ServiceContext]` in a
test fixture that sets `serviceName` starts working.

### Symfony — `Configuration.php` final tree

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
`defaultErrorChannel`, `defaultMemoryLimit`, `defaultConnectionExceptionRetry`. The `@TODO Ecotone 2.0` at
`Configuration.php:9` goes with them.

`EcotoneExtension::load()` `:38-82` collapses to:

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
The `RetryTemplateBuilder` import (`:13`) drops out. Everything from `:84` onward is untouched.

**Container parameters other bundles read are unaffected** — all are derived from the compiled Ecotone
container, not from any removed key:
`ecotone.external_references` (`:150`, read by `Compiler/AliasExternalReferenceForTesting.php:16-20`),
`ecotone.messaging_system_configuration.required_references` (`:185`, read by
`Compiler/RequiredReferencesCompilerPass.php:16`), the `ecotone.container` service (`:117`) and the
`ecotone.testing.*` aliases (`:144`). Both compiler passes stay registered as-is
(`packages/Symfony/SymfonyBundle/EcotoneSymfonyBundle.php:22-27`).

### Laravel — `config/ecotone.php` final shape

```php
return [
    'loadAppNamespaces'  => true,
    'namespaces'         => [],
    // 'modulePackages'  => [],   // absent = load every installed package
    'cacheConfiguration' => env('ECOTONE_CACHE', false),
    'test'               => false,
    'licenceKey'         => null,
];
```
Removed with their comment blocks: `serviceName` (`:14`), `defaultSerializationMediaType` (`:66`),
`defaultErrorChannel` (`:77`), `defaultConnectionExceptionRetry` (`:92`).

`EcotoneProvider::register()` `:47-98` collapses to the environment/catalog/namespaces triple plus the existing
`modulePackages` (`:64-66`) and `licenceKey` (`:68-70`) blocks; `RetryTemplateBuilder` (`:17`),
`withFailFast(false)` (`:60`), `$errorChannel` (`:53`) and the `@TODO` at `:56` are deleted.
`withCacheDirectoryPath($cacheDirectory)` (`:62`) belongs to the `service-cache-directory` report, not G.

**New — unknown-key rejection.** Because `mergeConfigFrom()` is a shallow `array_merge`
(`ServiceProvider.php:163-172`), a stale published config keeps its removed keys and they become silent
no-ops. Immediately after `EcotoneProvider.php:42-45`:

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
throwing `ConfigurationException` naming the key and its replacement.

**`vendor:publish`.** `boot()` publishes under tag `ecotone-config` (`:180-185`); existing installs keep their
own copy, so shrinking the package file does not shrink theirs — the guard above is what turns that into a good
error. Upgrade steps: `php artisan config:clear` (a 1.x `bootstrap/cache/config.php` short-circuits
`mergeConfigFrom()` entirely — `ServiceProvider.php:165`), then
`php artisan vendor:publish --tag=ecotone-config --force`, then move the keys.

### Tempest — `EcotoneConfig` final constructor

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
Removed: `serviceName` (`:15`) and its `ECOTONE_SERVICE_NAME` fallback (`:25-27`),
`defaultSerializationMediaType` (`:19`), `defaultErrorChannel` (`:20`).
`MessagingSystemInitializer::buildServiceConfiguration()` (`:110-152`) loses `:133-143` and the dead
`withFailFast(false)` (`:125`). Unknown keys already error at the PHP level (named-argument constructor).
`deriveNamespacesFromComposer()` (`:94-108`) is unaffected.

### The user-facing pattern

```php
namespace App\Infrastructure;

use Ecotone\Api\{ConfigurationVariable, Environment, ServiceConfiguration, ServiceContext};
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;

final class EcotoneConfiguration
{
    #[ServiceContext]
    public function service(
        #[ConfigurationVariable('app.ecotone.service_name')] string $serviceName,
        #[ConfigurationVariable('app.ecotone.error_channel')] ?string $errorChannel = null,
    ): ServiceConfiguration {
        $configuration = ServiceConfiguration::createWithDefaults()
            ->withServiceName($serviceName)
            ->withDefaultSerializationMediaType(MediaType::APPLICATION_JSON)
            ->withConnectionRetryTemplate(RetryTemplateBuilder::exponentialBackoffWithMaxDelay(100, 3, 3));

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

`#[ConfigurationVariable]` resolves per framework: Symfony container parameter, with `%…%` resolved through
`resolveEnvPlaceholders()` (`SymfonyConfigurationVariableService.php:18-30`); Laravel `Config::get()`
(`LaravelConfigurationVariableService.php:13-21`); Tempest `env()` or `SomeConfig::class . '::property'`
(`TempestConfigurationVariableService.php:21-30`).

**`#[Environment]` filtering on `#[ServiceContext]` methods works today** — confirmed, not assumed.
`FileSystemAnnotationFinder::__construct()` builds the ban list at `:79-130` (class-level removes the class at
`:84-90`; method-level wins over class-level at `:120-128`), and `findAnnotatedMethods()` — the exact method
`AnnotationModuleRetrievingService::findAllExtensionObjects()` uses (`:54`) — skips banned methods at
`:343-345`. The environment name compared against comes from the framework, never from a config key:
`kernel.environment` (`EcotoneExtension.php:41`), `App::environment()` (`EcotoneProvider.php:47`),
`APP_ENV`/`ENVIRONMENT` (`MessagingSystemInitializer.php:62`).

## Internals

### The merge path, in order

1. `prepareWithModuleRetrievingService()` (`MessagingSystemConfiguration.php:726-737`) calls
   `findAllExtensionObjects()` **before** `new self(...)`.
2. `AnnotationModuleRetrievingService::findAllExtensionObjects()` (`:52-97`) finds `#[ServiceContext]` methods
   (`:54`), rejects constructor parameters (`:63-65`), instantiates (`:66`), resolves
   `#[ConfigurationVariable]` parameters (`:68-79`), invokes (`:80`), flattens arrays (`:89-93`).
3. `MessagingSystemConfiguration::__construct()` (`:196-256`): unwrap framework extension objects (`:198`) →
   collect `ServiceConfiguration` instances (`:199-204`) → **merge (`:205`)** → default the retry template
   (`:207-219`) → filter every `ServiceConfiguration` out (`:224-233`) → push the merged one back (`:248`) →
   validate the licence (`:250-253`) → `initialize()` (`:255`).

Because step 2 runs before step 3, everything merged at `:205` is available to every module compile pass. That
is the whole reason `serviceName`, `defaultMemoryLimit`, `connectionRetryTemplate` and `licenceKey` are movable.

### Cache-key participation

`FileSystemAnnotationFinder::getCacheMessagingFileNameBasedOnConfig()` (`:556-581`) hashes: every registered
class file's `sha1_file` (`:566-569`), `composer.lock` (`:571-574`), `sha1(serialize($serviceConfiguration))`
(`:576`), the passed `configurationVariables` (`:577`), and `$enableTesting` (`:578`).

Moving values from framework config into a `#[ServiceContext]` class **improves** invalidation, because `:566-569`
already hashes that class's contents — better than Symfony YAML, which participates only via Symfony's own
container staleness checks. The gap is a raw `getenv()`/`env()` read *inside* a `#[ServiceContext]` method: the
class file is unchanged, so the hash is unchanged, so a stale container is served. Mitigation is
`#[ConfigurationVariable]` plus documentation — with one integration fix: **Tempest passes no
`configurationVariables` at all** (`MessagingSystemInitializer.php:175-182` omits the argument, defaulting to
`[]` at `ContainerCacheLayout.php:39`), so even `#[ConfigurationVariable]` values are outside its hash. G closes
that.

## Interactions

### `service-cache-directory` report

That report removes `with/getCacheDirectoryPath()` from `ServiceConfiguration` and makes the path an
`EcotoneLite::bootstrap()` parameter, deleting two dead calls (`EcotoneProvider.php:62`,
`MessagingSystemInitializer.php:127`). Against the current tree its citations need refreshing: the file moved to
`packages/Ecotone/Api/ServiceConfiguration.php` (methods now at `:339-350`, field `:26`, init `:54`) and **the
`@deprecated use ServiceCacheDirectory` docblock it quotes no longer exists**.

**G does not absorb it, but is sequenced after it.** Independent in scope, colliding in one file: both rewrite
`ServiceConfiguration`. Landing the cache-dir deletion first leaves G's immutability sweep two fewer methods to
touch and avoids a rebase conflict. Note for both reviews: the cache-dir change removes one value from the
`sha1(serialize($serviceConfiguration))` hash input and G removes five more — neither is a problem (the moved
values now live in class files already hashed at `:566-569`), but the hash input is not fixed across the two.

### `EcotoneTestSupportModule.php:145`

```php
/** @TODO Ecotone 2.0, reconsider if needed */                       // :145
if (! $testConfiguration->isFailingOnCommandHandlerNotFound()) { … } // :146-154
if (! $testConfiguration->isFailingOnQueryHandlerNotFound())   { … } // :155-163
```

**Not part of G.** It is driven by `TestConfiguration`, resolved via `ExtensionObjectResolver::resolveUnique()`
(`EcotoneTestSupportModule.php:110`) — a different path from `ServiceConfiguration::mergeWith()` — and it was
never a framework config key on any integration. "Should a missing handler be a hard failure in tests?" belongs
with the async-test-semantics decisions at
`docs/superpowers/specs/2026-08-22-ecotone-2-0-release-design.md:170-175`.

One interaction to regression-test: G's `:198` change means a `TestConfiguration` nested inside a
`#[ServiceContext]`-returned `ServiceConfiguration::addExtensionObject()` — currently dropped — starts being
honoured at `EcotoneTestSupportModule.php:110`.

## Edge cases

1. **Silent override becomes a hard error.** `Monorepo/ExampleApp/Symfony/config/services.php:18` sets
   `defaultErrorChannel` in framework config today; a `#[ServiceContext]` also setting one loses silently
   (`ServiceConfiguration.php:86`). After G the framework key is gone, so two disagreeing `#[ServiceContext]`
   methods throw (`:91`). Intentional and loud — must be in the upgrade notes.
2. **Symfony compile-time env resolution.** `EcotoneExtension.php:36` and
   `SymfonyConfigurationVariableService.php:21-27` both resolve `%env(...)%` at build time, so a `%env()%`-backed
   `licenceKey` or `#[ConfigurationVariable]` is baked into the dumped container. True before and after G — no
   regression, but the docs must not imply runtime env reads.
3. **Laravel `config:cache`.** `mergeConfigFrom()` is skipped entirely when config is cached
   (`ServiceProvider.php:165`), so an upgrader with a 1.x cache never sees the new defaults *or* the guard until
   `php artisan config:clear`. First step of the Laravel upgrade section.
4. **`test: true` bootstraps are unchanged** — `test` stays a bootstrap key:
   `packages/Laravel/tests/Application/config/ecotone.php:13`,
   `Monorepo/ExampleApp/Symfony/config/services_test_es.php:16`,
   `packages/Tempest/tests/EcotoneIntegrationTestCase.php:27-32`,
   `EcotoneLite::bootstrapFlowTesting()` (`EcotoneLite.php:91`).
5. **Multi-app / monorepo.** A `#[ServiceContext]` in a shared namespace applies to *every* app scanning it.
   Where two apps need different values, use `#[Environment]` or per-app namespaces. Conversely, this is G's
   strongest in-repo win: `Monorepo/ExampleApp*` currently repeat `defaultErrorChannel` /
   `defaultSerializationMediaType` across four framework bindings, and one `#[ServiceContext]` in the shared
   `Common/` namespace replaces all four.
6. **`ConfigurationException` message quality.** `:77`/`:91` today name the field but not the classes. G adds the
   class/method names — without them, "Ecotone can't resolve defaultErrorChannel" is unactionable in an app with
   dozens of `#[ServiceContext]` methods.

## Migration / upgrade notes

`upgrade-2.0.md:278-305` is replaced (drop the "Planned — not implemented yet" banner). Full replacement text
is in `docs/superpowers/research/servicecontext-only-config/report.md` §9. Its core:

**Remaining keys**

| Framework | Keys still accepted |
|---|---|
| Symfony (`ecotone.yaml`) | `namespaces`, `loadSrcNamespaces`, `modulePackages`, `test`, `licenceKey` |
| Laravel (`config/ecotone.php`) | `namespaces`, `loadAppNamespaces`, `modulePackages`, `cacheConfiguration`, `test`, `licenceKey` |
| Tempest (`EcotoneConfig`) | `namespaces`, `loadAppNamespaces`, `modulePackages`, `cacheConfiguration`, `test`, `licenceKey` |

**Migration table**

| Symfony YAML | Laravel | Tempest | Replace with |
|---|---|---|---|
| `serviceName` | `serviceName` | `serviceName` | `ServiceConfiguration::withServiceName(string)` |
| `defaultSerializationMediaType` | `defaultSerializationMediaType` | `defaultSerializationMediaType` | `ServiceConfiguration::withDefaultSerializationMediaType(string\|MediaType)` |
| `defaultErrorChannel` | `defaultErrorChannel` | `defaultErrorChannel` | `ServiceConfiguration::withDefaultErrorChannel(string)` |
| `defaultMemoryLimit` | — | — | `ServiceConfiguration::withConsumerMemoryLimit(int)` |
| `defaultConnectionExceptionRetry: {initialDelay, maxAttempts, multiplier}` | `defaultConnectionExceptionRetry` | — | `ServiceConfiguration::withConnectionRetryTemplate(RetryTemplateBuilder::exponentialBackoffWithMaxDelay($initialDelay, $maxAttempts, $multiplier))` |
| `failFast` | — | — | **removed** — it had no effect since 1.x; delete the key |
| `cacheConfiguration` | *(kept)* | *(kept)* | **removed from Symfony only** — it was never read; Symfony uses `kernel.build_dir` |

**Failure modes after upgrade:** Symfony — `Unrecognized option "serviceName" under "ecotone"` at container
compile; Laravel — `ConfigurationException` naming the key and its replacement; Tempest — PHP
`Unknown named parameter`.

## Implementation plan

Sequential; docker only, never two package suites in parallel. The `service-cache-directory` removal lands
first (soft prerequisite, tracked separately).

1. **`ServiceConfiguration` immutability.** Clone in `:210,217,238,258`; fix the two callers that relied on
   mutation — `MessagingSystemConfiguration.php:207-219` (assign the return value) and `addCorePackage()`
   `:287-300`. Tests: `packages/Ecotone`, incl. a new "`with*()` does not mutate the receiver" unit test.
2. **Generalise `mergeWith()`.** `ServiceConfiguration.php:68-103` — add `serviceName`, `defaultMemoryLimit`,
   `connectionRetryTemplate`, `licenceKey` under the same rule; make `defaultMemoryLimitInMegabytes` nullable
   (`:40`) and move the `1024` default to `MessagingContainerBuilder.php:133`; unwrap `#[ServiceContext]`
   `getExtensionObjects()` at `MessagingSystemConfiguration.php:198`; name the offending classes in `:77,91`.
   Tests: extend `MessagingSystemConfigurationTest.php:673-717` with a merge/conflict pair per new field.
3. **Bootstrap-only keys throw from `#[ServiceContext]`.** A "was set" marker on `namespaces`/`loadCatalog`/
   `modulePackages`/`environment`; `MessagingSystemConfiguration.php:199-205` throws naming the class and the
   framework config file. Tests: `packages/Ecotone`.
4. **Optional `#[ConfigurationVariable]` in `#[ServiceContext]`.** `AnnotationModuleRetrievingService.php:68-79`
   routed through the `hasName()`/default/throw logic of `ValueConverter.php:32-42`. Tests: `packages/Ecotone`,
   then `packages/Symfony` for the `ParameterNotFoundException` case.
5. **Symfony.** `Configuration.php` → 5 nodes; `EcotoneExtension.php:38-82` trimmed. Tests: `packages/Symfony`
   (docker — covers the `Licence`, `EnvPlaceholder*`, `SingleTenant`, `MultiTenant`,
   `DbalConnectionRequirement*` apps).
6. **Laravel.** `config/ecotone.php` trimmed; `EcotoneProvider.php:47-98` trimmed, `@TODO` at `:56` removed;
   unknown-key + moved-key guard after `:45`. Tests: `packages/Laravel` (docker).
7. **Tempest.** `EcotoneConfig` → 6 properties; `MessagingSystemInitializer.php:110-152` trimmed; pass
   `configurationVariables` into `ContainerCacheLayout::resolve()` at `:175-182`. Tests: `packages/Tempest`
   (docker — watch `ProdCacheHashTest`, `ProductionCacheInvalidationTest`).
8. **Migrate examples.** `Monorepo/ExampleApp/Symfony/config/services.php:15-21`;
   `Monorepo/ExampleApp/Laravel/config/ecotone.php:9`;
   `Monorepo/ExampleAppEventSourcing/Symfony/config/services.php:13-20`;
   `Monorepo/ExampleAppEventSourcing/Laravel/config/ecotone.php:7`; one new `#[ServiceContext]` per app under
   `Monorepo/ExampleApp*/Common/`. Tests: Monorepo suites + `packages/OpenTelemetry` (it boots
   `Monorepo/ExampleApp` — `services.php:36-40`).
9. **Docs & skills.** `upgrade-2.0.md:278-305`; `.claude/skills/ecotone-symfony-setup/SKILL.md:52-60,147`;
   `.claude/skills/ecotone-symfony-setup/references/configuration-reference.md:3-55`;
   `.claude/skills/ecotone-laravel-setup/references/configuration-reference.md:8-52`;
   `.claude/skills/ecotone-laravel-setup/SKILL.md`;
   `.claude/skills/ecotone-enterprise/references/configuration-guide.md:9-56`.

**Files needing migration — complete list.** The four `Monorepo/ExampleApp*` config files above, and the five
skill docs. **No quickstart example needs a change**: every `quickstart-examples/**/config/ecotone.php` is
`return [];`, no `quickstart-examples/**/Symfony/config/services.php` calls `extension('ecotone', …)`, and the
Tempest quickstarts configure `DatabaseConfig`, not `EcotoneConfig`. Every `packages/*/tests` fixture uses only
bootstrap keys (`modulePackages`, `namespaces`, `test`, `loadAppNamespaces`, `licenceKey`) and is unaffected.

## Open questions for the maintainer

1. **`licenceKey` placement.** Recommendation: keep it in framework config (cache-invalidation integrity, secret
   shape, symmetry with `EcotoneLite::bootstrap(licenceKey:)`) while still merging it from `#[ServiceContext]`.
   The ServiceContext-only alternative is defensible but changes how a licence participates in the container
   hash. Confirm.
2. **Symfony `cacheConfiguration`.** The node exists (`Configuration.php:27-29`) and is never read;
   `EcotoneExtension.php:94,108` hard-codes `shouldUseCache: true`. Recommendation: delete the node, since
   Symfony's `kernel.build_dir`/debug machinery already governs this. Wiring it up instead would be a behaviour
   change, not a cleanup.
3. **`ServiceConfiguration` immutability** (plan step 1). Turning `:210,217,238,258` into cloning methods breaks
   any out-of-repo code relying on in-place mutation, silently. Acceptable as a 2.0 break, or does it need a
   deprecation shim?
4. **`failFast`.** Delete outright (no reader since `24b8a32c`), or is there an intended meaning to restore
   before 2.0 ships?
5. **Bootstrap keys set from `#[ServiceContext]`** (plan step 3). Recommendation: throw. Today
   `withNamespaces()` from a `#[ServiceContext]` is a silent no-op — but a throw could break someone currently
   doing it harmlessly.
6. **Deprecation window.** 2.0 is a major, so this plan removes keys outright. Should Symfony/Laravel instead
   accept the removed keys for one minor behind `trigger_deprecation()`, given that Symfony's rejection is a hard
   container-compile failure rather than a warning?
