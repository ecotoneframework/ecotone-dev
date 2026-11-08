---
type: llm
---
PASS if the plan starts from _PackageTemplate/ and registers the package name in ModulePackageList and in ModuleClassList::packageNames(), ModuleClassList::forPackage() and ModuleClassList::composerPackageNameOf(). FAIL if any of the four registrations is missing.
