---
type: llm
---
PASS if the findings carry a severity, a rule reference, a location and a fix, and they include at least three of these four: the nullable logger dependency (rule 2), the accumulating $replayed property (rule 3), the comment (rule 6), and the test constructing an internal class / SQL on an Ecotone table / camelCase name (rule 10/11). The change is given as a diff without line numbers and the reviewer cannot open the files: a report that says so and judges from the diff still passes, and a file, class, method or quoted line counts as a location. FAIL if two or more of the four are missing or findings lack rule references.
