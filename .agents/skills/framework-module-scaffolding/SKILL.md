---
name: framework-module-scaffolding
description: >-
  Scaffolding an Ecotone module or a new monorepo package: AnnotationModule with
  #[ModuleAnnotation], AnnotationFinder, ExtensionObjectResolver, explicit registration in
  ModuleClassList and ModulePackageList, and the _PackageTemplate. Use when creating a new
  module class, a new package under packages/, or registering one, in the ecotone-dev
  repository.
---

# Modules and packages

## Applies when

- Creating a class implementing `AnnotationModule`
- Adding a new directory under `packages/`

## Rules

### M1. Modules are registered explicitly
A module implements `AnnotationModule`, carries `#[ModuleAnnotation]`, and its class is added to the right
`ModuleClassList` constant. There is no discovery.
- **Expected:** a new module class appears in a `ModuleClassList` constant in the same change.
- **Verified by:** `eval:new-package`

### M2. A new package starts from `_PackageTemplate/` and is registered in four places
Its name goes into `ModulePackageList` (`Ecotone\Api\ExtensionObject\ModulePackageList`), into
`ModuleClassList::packageNames()`, `ModuleClassList::forPackage()` and `ModuleClassList::composerPackageNameOf()`,
which names its Composer package and refuses an unknown package at bootstrap. It has its own `composer.json`,
`phpstan.neon`, `phpunit.xml.dist`, `Api/` beside `src/`, and is split by `monorepo-builder.php`.
- **Expected:** the plan copies `_PackageTemplate/` and names `ModulePackageList`,
  `ModuleClassList::packageNames()`, `ModuleClassList::forPackage()` and `ModuleClassList::composerPackageNameOf()`.
- **Verified by:** `eval:new-package`

### M3. A module is never named in its test
It is proved through the application surface (`framework-testing` rule 10).
- **Expected:** the module's test boots `EcotoneLite` and asserts behaviour, never instantiating the module.
- **Verified by:** `eval:new-package`

## References

- [references/creating-a-module.md](references/creating-a-module.md) — module class, `AnnotationFinder`,
  `ExtensionObjectResolver`, package scaffolding, testing. Load when scaffolding.
- [references/module-anatomy.md](references/module-anatomy.md) — full interface definitions.
