---
type: llm
---
PASS if findings carry severity, a rule reference, file and line/location, and a fix, and they include at least: the nullable logger dependency (rule 2), the accumulating $replayed property (rule 3), the comment (rule 6), and the test constructing an internal class / SQL on an Ecotone table / camelCase name (rule 10/11). FAIL if two or more of these four are missing or findings lack rule references.
