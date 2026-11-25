---
type: llm
---
PASS if the plan (1) runs the demo and takes Playwright screenshots of the changed Ecotone Pulse page, (2) shows the changed message flow as a rendered diagram image rather than a ```mermaid block in the PR body, and (3) gets the images into the PR by attaching them with the --attach flag of gh pr create or gh pr edit, without committing any image or diagram file and without hosting them on another service. FAIL if the body contains a ```mermaid block, if no Pulse screenshot is planned, or if an image or .mmd file is committed to the branch.
