#!/usr/bin/env bash

#
# licence Apache-2.0
#


_run_tests() {
  local ok=0
  local title="$1"
  local start=$(date -u +%s)

  OUTPUT=$(bash -xc "$2" 2>&1) || ok=$?
  local end=$(date -u +%s)

  if [[ $ok -ne 0 ]]; then
    printf "\n%-70s%10s\n" "$title" $(($end-$start))s
    echo "$OUTPUT"
    echo "Job exited with: $ok"
    echo -e "\n::error::KO $title\n"
  else
    printf "::group::%-68s%10s\n" "$title" $(($end-$start))s
    echo "$OUTPUT"
    echo -e "\n\e[32mOK\e[0m $title\n\n::endgroup::"
  fi

  (exit $ok)
}
export -f _run_tests