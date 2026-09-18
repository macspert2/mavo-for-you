#!/bin/sh
# Runs every test file. Exits non-zero if any assertion fails.
#
# No WordPress needed: harness.php stubs enough of it, so this runs anywhere PHP
# does. Each file prints PASS/FAIL per assertion, then its own failure count, and
# exits non-zero if anything failed — the summary below is the total.
#
# Some files print a worked example after their summary (a scored candidate, a
# rendered row) as a tuning aid. That output is diagnostic, not a result; the
# exit code is what decides.
status=0
passed=0
failed=0

for file in "$(dirname "$0")"/test-*.php; do
	echo "== $(basename "$file")"
	out=$(php "$file" 2>&1) || status=1
	echo "$out"
	echo

	passed=$(( passed + $(printf '%s\n' "$out" | grep -c '^PASS') ))
	failed=$(( failed + $(printf '%s\n' "$out" | grep -c '^FAIL') ))
done

echo "──────────────────────────────────────────"
echo "$passed passed, $failed failed"

exit $status
