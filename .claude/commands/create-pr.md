# Create Pull Request

Create a GitHub Pull Request for the current branch using the repository's PR template.

## Instructions

### Step 0: Verify Branch

1. Run `git branch --show-current` to check the current branch
2. If the current branch is `main` or `master`:
   - Ask the user for a branch name using AskUserQuestion: "You're currently on the main branch. What should the new branch be named?" with options like:
     - `feat/<feature-name>` — for new features
     - `fix/<bug-description>` — for bug fixes
     - `refactor/<description>` — for refactoring
   - Create and switch to the new branch: `git checkout -b <branch-name>`
3. If already on a non-main branch, proceed to Step 1

### Step 1: Gather Context

1.Read the PR template at `.github/PULL_REQUEST_TEMPLATE.md`

### Step 2: Understand the "Why"

From the conversation history and the code changes, determine:
- **Why** is this change being introduced? What problem does it solve?
- **What is the intention** behind the change?

If the reasoning is unclear from the conversation context and code changes, **ask the user** using AskUserQuestion:
- "What problem does this change solve?"
- "What is the motivation behind this feature/fix?"

Do NOT proceed without understanding the "why".

### Step 3: Draft the PR

Use the PR template structure and fill it in:

#### Title
Use conventional commit prefix based on the change type:
- `feat:` for new features
- `fix:` for bug fixes
- `refactor:` for refactoring
- `docs:` for documentation
- `test:` for test-only changes

Keep the title under 70 characters, concise and descriptive.

#### Body

**"Why is this change proposed?"** section:
- Write 2-4 sentences explaining the problem/motivation
- Focus on the user-facing impact — why should someone care about this change?

**"Description of Changes"** section must include:

1. **Concise summary** — What was changed, in 3-5 bullet points max
2. **Usage examples** — Show PHP code examples of how end users would use the new/changed feature. Include attribute usage, handler registration, configuration, etc. Examples should be copy-pasteable and realistic.
3. **Use case scenarios** — Describe 2-3 real-world scenarios where this feature is useful
4. **Mermaid diagram** (when applicable) — For changes involving message flows, handler chains, async processing, sagas, interceptor pipelines, or any multi-step process, include a Mermaid diagram:
   ```mermaid
   sequenceDiagram
       participant User
       participant CommandBus
       participant Handler
       User->>CommandBus: SendCommand
       CommandBus->>Handler: #[CommandHandler]
   ```
   Use sequence diagrams for flows, flowcharts for decision logic, or class diagrams for structural changes.

**"Pull Request Contribution Terms"** section:
- Ask the user using AskUserQuestion: "Do you agree to the contribution terms outlined in CONTRIBUTING.md?" with options:
  - "Yes, I agree" — Mark the checkbox with `[X]`
  - "No" — Leave the checkbox empty `[]`
- If agreed: `- [X] I have read and agree to the contribution terms outlined in [CONTRIBUTING](https://github.com/ecotoneframework/ecotone-dev/blob/main/CONTRIBUTING.md)`
- If not agreed: `- [] I have read and agree to the contribution terms outlined in [CONTRIBUTING](https://github.com/ecotoneframework/ecotone-dev/blob/main/CONTRIBUTING.md)`

### Step 4: Review with User

Present the complete PR content (title + body) to the user for review. Ask if they want to modify anything before creating the PR.

### Step 5: Create the PR

Once approved, use `gh pr create` with the title and body. Use a HEREDOC for the body:

```bash
gh pr create --title "feat: description here" --body "$(cat <<'EOF'
## Why is this change proposed?

...

## Description of Changes

...

## Pull Request Contribution Terms

- [X] I have read and agree to the contribution terms outlined in [CONTRIBUTING](https://github.com/ecotoneframework/ecotone-dev/blob/main/CONTRIBUTING.md).
EOF
)"
```

Push the branch first if needed (`git push -u origin <branch-name>`).

Return the PR URL when done.

## Key Principles

- **Be concise** — No fluff, every sentence should add value
- **Show, don't tell** — Usage examples are more valuable than descriptions
- **End-user perspective** — Write for someone who will use this feature, not for the developer who built it
- **Ask when unsure** — Better to ask the user than to guess the motivation
