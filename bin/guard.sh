#!/usr/bin/env bash
# Keeps text that must not be published out of this public repository.
#
#   bin/guard.sh                  every tracked file
#   bin/guard.sh --staged         what is staged for the next commit (pre-commit hook)
#   bin/guard.sh --message FILE   a commit message (commit-msg hook)
#   bin/guard.sh --commits RANGE  the messages of every commit in RANGE (CI)
#
# Two pattern lists, one extended regular expression per line, case-insensitive:
#   - the public list below, safe to publish;
#   - a private list of names and hosts that must not appear here, which is why
#     the list itself is not in the repository. It is read from $GUARD_PATTERNS
#     (a CI secret) or from ~/.config/agentnumber10/guard-patterns. A match on a
#     private pattern is reported by file and line only, never by its text,
#     because CI logs are public.
set -euo pipefail
shopt -s lastpipe # `git show x | check_text` must count failures in this shell
cd "$(dirname "$0")/.."

public_patterns=(
	'^[[:space:]]*Co-Authored-By:'
	'(generated|written) (with|by) \[?(claude|chatgpt|copilot|cursor|gemini)'
	'\bCITE-[0-9]+\b'
)

private_patterns=()
private_source=''
if [ -n "${GUARD_PATTERNS:-}" ]; then
	private_source="$GUARD_PATTERNS"
elif [ -r "$HOME/.config/agentnumber10/guard-patterns" ]; then
	private_source="$(cat "$HOME/.config/agentnumber10/guard-patterns")"
fi
if [ -n "$private_source" ]; then
	while IFS= read -r line; do
		case "$line" in '' | '#'*) continue ;; esac
		private_patterns+=("$line")
	done <<<"$private_source"
else
	echo "guard: no private pattern list found, checking the public list only" >&2
fi

# Files that legitimately contain the public patterns or third-party text.
skip_file() {
	case "$1" in
		bin/guard.sh | composer.lock | LICENSE) return 0 ;;
	esac
	return 1
}

failures=0

# check_text NAME < text
check_text() {
	local name=$1 text n pattern
	text=$(cat)
	for pattern in "${public_patterns[@]}"; do
		while IFS= read -r n; do
			[ -n "$n" ] || continue
			echo "guard: $name:$n matches the public pattern '$pattern'" >&2
			failures=$((failures + 1))
		done < <(printf '%s\n' "$text" | grep -Ein -e "$pattern" | cut -d: -f1 || true)
	done
	local i=0
	for pattern in "${private_patterns[@]}"; do
		i=$((i + 1))
		while IFS= read -r n; do
			[ -n "$n" ] || continue
			echo "guard: $name:$n matches private pattern #$i" >&2
			failures=$((failures + 1))
		done < <(printf '%s\n' "$text" | grep -Ein -e "$pattern" | cut -d: -f1 || true)
	done
}

# check_message NAME < message: format rules on top of the text rules.
# Conventional Commits 1.0 for the first line; git's 50/72 layout for the rest.
check_message() {
	local name=$1 message header summary first n line
	# Drop comments and, for `git commit -v`, everything below the scissors line.
	message=$(sed '/^# -* >8 -*$/,$d' | grep -v '^#' || true)
	header=$(printf '%s\n' "$message" | head -n1)
	if ! printf '%s\n' "$header" | grep -Eq '^(feat|fix|docs|style|refactor|perf|test|build|ci|chore|revert)(\([a-z0-9-]+\))?!?: [^ ].*[^. ]$'; then
		echo "guard: $name: the first line must read 'type(scope): summary' with no full stop, for example 'fix(verifier): keep lists while a download fails'" >&2
		failures=$((failures + 1))
	fi
	# A squash merge appends " (#123)"; GitHub adds it, so it does not count.
	local measured=$header
	if [[ "$header" =~ ^(.*)\ \(#[0-9]+\)$ ]]; then measured=${BASH_REMATCH[1]}; fi
	if [ "${#measured}" -gt 72 ]; then
		echo "guard: $name: the first line is ${#measured} characters; keep it to 72" >&2
		failures=$((failures + 1))
	elif [ "${#measured}" -gt 50 ]; then
		echo "guard: $name: note: the first line is ${#measured} characters; aim for 50" >&2
	fi
	summary=${header#*: }
	first=$(printf '%s' "${summary%% *}" | tr '[:upper:]' '[:lower:]')
	case "$first" in
		added | adds | adding | fixed | fixes | fixing | updated | updates | updating | changed | changes | changing | removed | removes | removing | improved | improves | renamed | renames | created | creates | implemented | implements | refactored | moved | moves)
			echo "guard: $name: write the summary as a command: 'add', not 'added' or 'adds'" >&2
			failures=$((failures + 1))
			;;
	esac
	if [ -n "$(printf '%s\n' "$message" | sed -n 2p)" ]; then
		echo "guard: $name: leave the second line blank, between the first line and the body" >&2
		failures=$((failures + 1))
	fi
	n=0
	while IFS= read -r line; do
		n=$((n + 1))
		[ "$n" -gt 2 ] || continue
		# A squash merge lists each commit as "* subject"; the bullet does not count.
		local len=${#line}
		[[ "$line" == "* "* ]] && len=$((len - 2))
		[ "$len" -gt 72 ] || continue
		# A URL or a single long word cannot be wrapped; anything else can.
		if [[ "$line" == *://* || "$line" != *" "* ]]; then continue; fi
		echo "guard: $name: body line $n is $len characters; wrap the body at 72" >&2
		failures=$((failures + 1))
	done < <(printf '%s\n' "$message")
	if printf '%s\n' "$message" | grep -Eiq '^(AI-Assisted|AI-Model|Generated-by):'; then
		echo "guard: $name: tool attribution lines do not belong in this repository's history" >&2
		failures=$((failures + 1))
	fi
	printf '%s\n' "$message" | check_text "$name"
}

mode=${1:-all}
case "$mode" in
	all)
		while IFS= read -r -d '' file; do
			skip_file "$file" && continue
			[ -f "$file" ] || continue
			grep -Iq . "$file" 2>/dev/null || continue # binary or empty
			check_text "$file" <"$file"
		done < <(git ls-files -z)
		;;
	--staged)
		while IFS= read -r -d '' file; do
			skip_file "$file" && continue
			case "$file" in
				.env | .env.* | */.env | */.env.*)
					echo "guard: $file: environment files are never committed" >&2
					failures=$((failures + 1))
					continue
					;;
			esac
			size=$(git cat-file -s ":$file")
			if [ "$size" -gt 2097152 ]; then
				echo "guard: $file is $size bytes; files over 2 MB do not belong in the repository" >&2
				failures=$((failures + 1))
			fi
			git show ":$file" | grep -Iq . 2>/dev/null || continue
			git show ":$file" | check_text "$file"
		done < <(git diff --cached --name-only -z --diff-filter=ACMR)
		;;
	--message)
		check_message "commit message" <"$2"
		;;
	--commits)
		range=$2
		if [[ "$range" == *..* ]] && ! git cat-file -e "${range%%..*}^{commit}" 2>/dev/null; then
			# A new branch (zero SHA) or a base that no longer exists, as after a
			# rewrite: check every commit the tip contains.
			range=${range#*..}
		fi
		while IFS= read -r sha; do
			git log -1 --format=%B "$sha" | check_message "commit ${sha:0:7}"
		done < <(git rev-list --no-merges "$range")
		;;
	*)
		echo "usage: bin/guard.sh [--staged | --message FILE | --commits RANGE]" >&2
		exit 2
		;;
esac

if [ "$failures" -gt 0 ]; then
	echo "guard: $failures problem(s). See CONTRIBUTING.md." >&2
	exit 1
fi
