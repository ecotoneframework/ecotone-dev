# Pre-push review findings: reviews in resumable sub-sessions, commit dcff23901

Four independent read-only reviews (guidelines, breaking changes, behaviour, developer experience). No BLOCKING
finding. The developer-experience session returned NO USER-FACING SURFACE.

## Guidelines
- G1 (should fix). P3a hands a replacement session "the committed numbered findings", but P3 neither asks the
  sessions to number their findings nor says the list is committed before fixes cite it.
- G2 (should fix). "A session that had no finding touched by the fixes is not resumed" lets fix commits reach the
  push unreviewed by the sessions that raised nothing.
- G3 (nit). The four review skills say nothing about being resumed.

## Breaking changes
- B1 (nit). Step 4b of the command reference resumes only for BLOCKING findings; P3a also covers accepted
  SHOULD-FIX ones.

## Behaviour
- V1 (should fix). Same as G2.
- V2 (should fix). Same as G1; the replacement session also needs the whole range, not only the fix commits.
- V3 (should fix). A resumed behaviour session still has its worktree at the reviewed commit.
- V4 (should fix). The `gh pr create` example ticks the contribution terms box, against rule P4.
- V5 (nit). A later push to an open PR does not say whether its reviews resume the earlier sessions.
- V6 (nit). Over several fix rounds the range should start at the last commit that session verified.
- V7 (nit). Only the open-pr eval case grades P3a.

## Second round, on the fixes (commit 2db80a5d7), from the resumed sessions
- G4 (nit). P3 asks for the numbered lists to be committed, but its *Expected* does not.
- V8 (should fix). After a rebase or a merge from `main`, `<last verified sha>..HEAD` is wrong or pulls in main.
- V9 (should fix). P3 still opens with "start four separate review sessions" and the update-open-pr grader still
  expects fresh sessions, against P3a's resume on a later push. The eval re-run failed that grader (0.50).
- V10 (nit). Whether a fix adds user-facing surface was left for the implementer to judge.

## Third round (commit 5d9036842), and the maintainer's decision
- V11 (nit). P3 said the agent "launches" the sessions, which reads as starting new ones only.
- G5 (nit). The open-pr grader does not check that the numbered findings are committed. Left as is.
- Maintainer: reviewers never run in a separate worktree; each is a session in the worktree of the change.
