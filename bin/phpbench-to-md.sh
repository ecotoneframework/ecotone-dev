#!/usr/bin/env bash

# Transform the table output of phpbench
#  +------------------+-------------------------------------------+------+-----+----------+-----------+--------+
#  | benchmark        | subject                                   | revs | its | mem_peak | mode      | rstdev |
#  +------------------+-------------------------------------------+------+-----+----------+-----------+--------+
#  | EcotoneBenchmark | bench_running_ecotone_lite_with_fail_fast | 1    | 1   | 3.186mb  | 151.997ms | ±0.00% |
#  | EcotoneBenchmark | bench_running_ecotone_lite_with_cache     | 1    | 1   | 4.915mb  | 14.683ms  | ±0.00% |
#  +------------------+-------------------------------------------+------+-----+----------+-----------+--------+
#  (newline)
#
# into a valid markdown table:
#  | benchmark        | subject                                   | revs | its | mem_peak | mode      | rstdev |
#  | ---------------- | ----------------------------------------- | ---- | --- | -------- | --------- | ------ |
#  | EcotoneBenchmark | bench_running_ecotone_lite_with_fail_fast | 1    | 1   | 3.186mb  | 150.799ms | ±0.00% |
#  | EcotoneBenchmark | bench_running_ecotone_lite_with_cache     | 1    | 1   | 4.915mb  | 14.348ms  | ±0.00% |

# Read the input from stdin
# Remove the last two lines
# Remove the first line
# Replace the table borders with pipes
# Print the result
head -n -2 | sed -n -e '1d' -e 's/-+-/ | /g' -e 's/+-/| /g' -e 's/-+/ |/g' -e p