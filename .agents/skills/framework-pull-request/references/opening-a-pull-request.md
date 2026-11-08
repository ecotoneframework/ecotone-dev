## Opening a Pull Request

For a contribution targeting `main`. Work on 2.0 is merged through its own branches instead.

Run the checks in
[Before opening a pull request](checklist.md#before-opening-a-pull-request)
first — CI is `fail-fast`, so the first failure hides everything after it. A red check is reproduced and fixed, never rerun
(Testing Guidelines above); a flaky test or a flaky install step is a defect to push a fix for.

**Title and branch** use a conventional prefix, matching the commit subjects above: `feat:`, `fix:`, `refactor:`,
`docs:`, `test:`.

**Body** starts from `.github/PULL_REQUEST_TEMPLATE.md`, which has three sections — *Why is this change proposed?*,
*Description of Changes*, and the contribution terms. Fill all three:

1. **Why** — the problem, in the reporter's terms. What was not possible before
2. **Description of Changes** — what changed, and what an application observes differently
3. **Contribution terms** — tick the CLA checkbox. `.github/workflows/contribution-check.yml` fails the PR without
   it, on open and on every edit

Add, beyond the template, when the change warrants it:

- **Usage examples** for a new or changed feature — the PHP an application would write: handler registration,
  the attribute, the `#[ServiceContext]` configuration
- **A Mermaid diagram** for anything that changes a message flow — handler chains, async processing, sagas,
  interceptor pipelines:
  ````markdown
  ```mermaid
  sequenceDiagram
      participant User
      participant CommandBus
      participant Handler
      User->>CommandBus: PlaceOrder
      CommandBus->>Handler: #[CommandHandler]
      Handler-->>User: OrderPlaced event
  ```
  ````

**Draft the body and get it agreed before opening the PR.** Propose it, let the maintainer correct it, and do not
publish a description they have not seen.

`CONTRIBUTING.md` covers the fork-and-clone side for outside contributors.

