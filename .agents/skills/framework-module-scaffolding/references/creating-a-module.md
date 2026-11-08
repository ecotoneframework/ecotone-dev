# Creating a module or a package

## Overview

This guide covers creating new Ecotone modules and packages. Read it when scaffolding a new package, implementing a module class with the `AnnotationModule` pattern, registering handlers/channels/converters in the messaging system, or accepting external configuration via `#[ServiceContext]`.

## 1. Module Class Structure

Every Ecotone module follows the `AnnotationModule` pattern:

```php
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;

#[ModuleAnnotation]
final class MyModule extends NoExternalConfigurationModule implements AnnotationModule
{
    public static function create(
        AnnotationFinder $annotationRegistrationService,
        InterfaceToCallRegistry $interfaceToCallRegistry
    ): static {
        return new self();
    }

    public function prepare(
        Configuration $messagingConfiguration,
        array $extensionObjects,
        ModuleReferenceSearchService $moduleReferenceSearchService,
        InterfaceToCallRegistry $interfaceToCallRegistry
    ): void {
        // Register handlers, converters, channels, etc.
    }


    public function getModulePackageName(): string
    {
        return 'myPackage';
    }
}
```

Key pieces:
- `#[ModuleAnnotation]` -- marks class as a module
- `AnnotationModule` interface -- required contract
- `NoExternalConfigurationModule` -- extend when no external config needed

## 2. Using AnnotationFinder

```php
// Find all classes with a specific attribute
$classes = $annotationRegistrationService->findAnnotatedClasses(MyAttribute::class);

// Find all methods with a specific attribute
$methods = $annotationRegistrationService->findAnnotatedMethods(MyHandler::class);

// Each result provides:
// - getClassName() -- fully qualified class name
// - getMethodName() -- method name
// - getAnnotationForMethod() -- the attribute instance
```

## 3. Using ExtensionObjectResolver

When your module accepts external configuration:

```php
public function prepare(
    Configuration $messagingConfiguration,
    array $extensionObjects,
    ...
): void {
    $configs = ExtensionObjectResolver::resolve(MyModuleConfig::class, $extensionObjects);
    foreach ($configs as $config) {
        // Apply configuration
    }
}
```

Users provide configuration via `#[ServiceContext]`:

```php
class UserConfig
{
    #[ServiceContext]
    public function myModuleConfig(): MyModuleConfig
    {
        return new MyModuleConfig(setting: 'value');
    }
}
```

## 4. Package Scaffolding

Start from the package template directory:

```
<PackageTemplate>/
├── src/
│   └── Configuration/
│       └── <PackageTemplate>Module.php
├── tests/
├── composer.json
└── phpstan.neon
```

Steps:
1. Copy the package template to `packages/<YourPackage>/`
2. Rename the template module class to `<YourPackage>Module`
3. Update namespace from template namespace to `Ecotone\<YourPackage>`
4. Update `composer.json` (name, autoload)
5. Register package: constant in `ModulePackageList`, then `packageNames()`, the `forPackage()` match case and the `composerPackageNameOf()` match case in `ModuleClassList`
6. Add the module class to its package's `ModuleClassList::*_MODULES` constant (`DBAL_MODULES`, `CORE_MODULES`, ...).
   This is also the only step a new module in an **existing** package needs, and the one nothing reports when it is
   missing: `forPackage()` returns that constant, so a module left out of it is never loaded
7. Add to root `composer.json` for monorepo

## 5. Testing Modules

A module is never named in its test ([conventions rule 10](../../framework-testing/references/rules.md#10-tests-validate-at-the-userland-level-only)):
it loads because its class is in its package's `ModuleClassList` constant and the package is installed. The test
registers an application class carrying the attribute the module reads, and asserts on what the application sees:

```php
public function test_a_handler_marked_with_the_attribute_is_reachable_through_the_bus(): void
{
    $handler = new class () {
        public array $received = [];

        #[MyAttribute]
        #[CommandHandler('my.command')]
        public function handle(string $payload): void
        {
            $this->received[] = $payload;
        }
    };

    $ecotone = EcotoneLite::bootstrapFlowTesting(
        classesToResolve: [$handler::class],
        containerOrAvailableServices: [$handler],
    );

    $ecotone->sendCommandWithRouting('my.command', 'payload');

    $this->assertSame(['payload'], $handler->received);
}
```

## Key Rules

- Every module needs `#[ModuleAnnotation]`
- Module classes should be `final`
- Use `NoExternalConfigurationModule` when no user config is needed
- Register package name in `ModulePackageList` for skip support
- Start from the package template directory for new packages

## Additional resources

- [Module anatomy reference](module-anatomy.md) -- Complete interface definitions and implementation examples: `AnnotationModule` interface, `NoExternalConfigurationModule` base class, `Configuration` interface methods (`registerMessageHandler`, `registerMessageChannel`, `registerConverter`, etc.), `AnnotationFinder` interface methods, `ModulePackageList` constants and registration steps, package directory structure with `composer.json` template, and a full module with external configuration class. Load when you need exact interface signatures, the package template module code, `composer.json` boilerplate, or a complete module with external configuration.
