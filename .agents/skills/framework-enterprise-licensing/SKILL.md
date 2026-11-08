---
name: framework-enterprise-licensing
description: >-
  Ecotone framework rules for Enterprise features: separate licence Enterprise files chosen
  via LicenceDecider, Apache-2.0 headers on open-source files, open core unchanged, and how
  to treat the Enterprise notice inside this repository versus copying out. Use when adding,
  editing or reviewing an Enterprise feature or a file carrying licence Enterprise, or a
  licence header, in the ecotone-dev repository.
---

# Enterprise features and licence headers

[references/rules.md](references/rules.md) has the reasoning and the licence header text.

## Applies when

- Adding an Enterprise-only feature or changing a `licence Enterprise` file
- Adding any new file under `src/` or `Api/` (it needs a licence header)

## Rules

### 7. Enterprise features live in separate files
Marked `licence Enterprise`, chosen in the container via `LicenceDecider`. Open-source files carry
`licence Apache-2.0`. Open-core behaviour stays byte-for-byte unchanged: no `if ($hasLicence)` branches.
- **Expected:** the Enterprise behaviour is a separate class with a `licence Enterprise` header, selected by
  `LicenceDecider` at container build; the open-core class has no licence branch.
- **Verified by:** `eval:enterprise-feature`, `check:licence-header`

### 7a. Edit Enterprise files here like any other file
Their notice governs copying them *out* into another codebase, not work in this repository. Never copy one into
another codebase, adapt it or reimplement an "inspired" version there.
- **Expected:** asked to fix a `licence Enterprise` file in this repository, the agent edits it normally; asked to
  port it into another codebase, the agent declines and implements from functional requirements instead.
- **Verified by:** `eval:enterprise-feature`

## References

- [references/rules.md](references/rules.md) — rules 7 and 7a, header text, `bin/add-apache-licence.php` and
  `bin/add-enterprise-licence.php`.
