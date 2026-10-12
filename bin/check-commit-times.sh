#!/bin/bash
#
# Every commit is timestamped at 08:00, or at any time from 18:00 onwards - never between
# the two. Checks the author AND the committer date: a cherry-pick or a rebase leaves a
# compliant author date beside a wall-clock committer date.
#
# Commits that reached this branch from origin/main keep upstream's timestamps. Re-dating
# them would fork them from main, so every later merge from main would bring the originals
# back and the branch would carry both.
#
# Usage: bin/check-commit-times.sh [base]       default base: origin/2.0

set -u
BASE="${1:-origin/2.0}"
git rev-parse --verify -q "$BASE" >/dev/null || { echo "Unknown base: $BASE"; exit 2; }

violations=0
while IFS='|' read -r sha ad cd subject; do
    git merge-base --is-ancestor "$sha" origin/main 2>/dev/null && continue

    bad=''
    for pair in "author:$ad" "committer:$cd"; do
        kind=${pair%%:*}; t=${pair#*:}
        h=${t%%:*}; m=${t##*:}
        h=$((10#$h)); m=$((10#$m))
        if { [ "$h" -gt 8 ] || { [ "$h" -eq 8 ] && [ "$m" -gt 0 ]; }; } && [ "$h" -lt 18 ]; then
            bad="$bad $kind=$t"
        fi
    done

    if [ -n "$bad" ]; then
        violations=$((violations + 1))
        printf '%s %-62s%s\n' "${sha:0:9}" "${subject:0:62}" "$bad"
    fi
done < <(git log --format='%H|%ad|%cd|%s' --date=format:'%H:%M' "$BASE..HEAD")

if [ "$violations" -gt 0 ]; then
    echo
    echo "$violations commit(s) are timestamped between 08:00 and 18:00."
    echo "Re-date one with:"
    echo '  git rebase --committer-date-is-author-date <sha>^   # or, for a single tip commit:'
    echo '  GIT_AUTHOR_DATE="$(git log -1 --format=%ad --date=format:"%Y-%m-%d") 08:00:00" \'
    echo '  GIT_COMMITTER_DATE="$(git log -1 --format=%ad --date=format:"%Y-%m-%d") 08:00:00" git commit --amend --no-edit'
    exit 1
fi

echo "Commit times: every commit is at 08:00 or from 18:00 onwards (base $BASE, commits from origin/main exempt)."
