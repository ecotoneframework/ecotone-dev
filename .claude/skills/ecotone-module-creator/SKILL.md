---
name: ecotone-module-creator
description: >-
  Scaffolds new Ecotone packages and modules: AnnotationModule pattern,
  module registration, Configuration building, and package template
  usage. Use when creating new framework modules, extending the module
  system, or scaffolding new packages.
disable-model-invocation: true
argument-hint: "[module-name]"
---

# Ecotone Module Creator

## 1. Module Class Structure

Every Ecotone module follows the `AnnotationModule` pattern:

```php
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
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

    public function canHandle($extensionObject): bool
    {
        return false;
    }

    public function getModulePackageName(): string
    {
        return 'myPackage';
    }
}
```

Key pieces:
- `#[ModuleAnnotation]` — marks class as a module
- `AnnotationModule` interface — required contract
- `NoExternalConfigurationModule` — extend when no external config needed

## 2. Required Methods

### `create()` — Static Factory

Called during bootstrap. Use `AnnotationFinder` to scan for attributes:

```php
public static function create(
    AnnotationFinder $annotationRegistrationService,
    InterfaceToCallRegistry $interfaceToCallRegistry
): static {
    $handlers = $annotationRegistrationService->findAnnotatedMethods(MyCustomAttribute::class);
    return new self($handlers);
}
```

### `prepare()` — Register Components

Called to wire the module into the messaging system:

```php
public function prepare(
    Configuration $messagingConfiguration,
    array $extensionObjects,
    ModuleReferenceSearchService $moduleReferenceSearchService,
    InterfaceToCallRegistry $interfaceToCallRegistry
): void {
    // Register a service activator
    $messagingConfiguration->registerMessageHandler(
        ServiceActivatorBuilder::createWithDirectReference(
            $this->handler, 'handle'
        )->withInputChannelName('myChannel')
    );

    // Register a channel
    $messagingConfiguration->registerMessageChannel(
        SimpleMessageChannelBuilder::createQueueChannel('myQueue')
    );
}
```

### `canHandle()` — Extension Object Support

Declares which extension objects the module accepts from user configuration:

```php
public function canHandle($extensionObject): bool
{
    return $extensionObject instanceof MyModuleConfiguration;
}
```

### `getModulePackageName()` — Package Identity

Returns the module identifier used in `ModulePackageList`:

```php
public function getModulePackageName(): string
{
    return ModulePackageList::DBAL_PACKAGE;
}
```

## 3. Using AnnotationFinder

```php
// Find all classes with a specific attribute
$classes = $annotationRegistrationService->findAnnotatedClasses(MyAttribute::class);

// Find all methods with a specific attribute
$methods = $annotationRegistrationService->findAnnotatedMethods(MyHandler::class);

// Each result provides:
// - getClassName() — fully qualified class name
// - getMethodName() — method name
// - getAnnotationForMethod() — the attribute instance
```

## 4. Using ExtensionObjectResolver

When your module accepts external configuration:

```php
public function canHandle($extensionObject): bool
{
    return $extensionObject instanceof MyModuleConfig;
}

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

## 5. Package Scaffolding

Start from `_PackageTemplate/`:

```
_PackageTemplate/
├── src/
│   └── Configuration/
│       └── _PackageTemplateModule.php
├── tests/
├── composer.json
└── phpstan.neon
```

Steps:
1. Copy `_PackageTemplate/` to `packages/MyPackage/`
2. Rename `_PackageTemplateModule` → `MyPackageModule`
3. Update namespace from `Ecotone\_PackageTemplate` → `Ecotone\MyPackage`
4. Update `composer.json` (name, autoload)
5. Register package in `ModulePackageList` (add constant + match case)
6. Add to root `composer.json` for monorepo

## 6. Testing Modules

```php
public function test_module_registers_handlers(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting(
        classesToResolve: [MyModule::class, TestHandler::class],
        containerOrAvailableServices: [new TestHandler()],
    );

    // Verify the module's handlers are active
    $ecotone->sendCommand(new TestCommand());
    // Assert expected behavior
}
```

## Key Rules

- Every module needs `#[ModuleAnnotation]`
- Module classes should be `final`
- Use `NoExternalConfigurationModule` when no user config is needed
- Register package name in `ModulePackageList` for skip support
- Start from `_PackageTemplate/` for new packages
- See `references/module-anatomy.md` for real module examples
