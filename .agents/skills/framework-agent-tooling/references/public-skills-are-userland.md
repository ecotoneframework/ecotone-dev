## Public skills are for Ecotone users

`api/skills/ecotone-*` are shared with Ecotone users (split to `ecotoneframework/skills` on a stable release): each
one teaches an application developer how to use one area of the user-facing API, in their own project. Nothing about
how *this repository* is developed belongs there.

- **A public skill describes user-land usage only** - attributes, gateways, configuration objects, console commands,
  the testing support an application uses. Every sample is one an Ecotone user could paste into their application.
- **Internal conventions go to `.agents/skills/framework-*`** - how tests are shaped here, that the suite runs on
  Ecotone's own testing support and never around it, how a module or a package is scaffolded, naming and docblock
  rules, the review and brief process. A public skill never links outside `api/` (`check:agent-skills` refuses it).
- **The test for a sentence:** would it read as instructions to someone who has never cloned ecotone-dev? If not, it
  is a convention, not a public skill.
