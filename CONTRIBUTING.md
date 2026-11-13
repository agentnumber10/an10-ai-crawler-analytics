# Contributing

Thanks for helping. This file is the standard the plugin is held to, for
everyone including its maintainers.

## Set up

```sh
composer install          # dev tools, and turns on the git hooks in .githooks
vendor/bin/phpunit        # unit tests
vendor/bin/phpcs          # WordPress Coding Standards
bash bin/build.sh         # every check, then the release zip in build/
```

`bin/activation-test.sh build/an10-ai-crawler-analytics` installs a clean
WordPress with `WP_DEBUG` on and activates the built plugin. It needs wp-cli and
a MySQL database (see the comment at the top of the script).

## What is checked, and where

| Check | Before each commit | In CI |
| --- | --- | --- |
| Private text, internal ticket ids, tool attribution (`bin/guard.sh`) | staged files and the message | every file and every commit message |
| Commit message format | yes | yes |
| Generated files match `data/product-export.json` | yes | yes |
| WordPress Coding Standards (`phpcs`) | staged PHP files | everything |
| Unit tests | yes | PHP 8.0 to 8.4 |
| Secrets in the history (gitleaks) | | yes |
| WordPress.org Plugin Check | | the built zip |
| Clean install with `WP_DEBUG`: silent activation, no outbound request before opt-in | | the built zip |

A failing check is fixed, not skipped. Never commit with `--no-verify`.

## How changes are accepted

- Open an issue before a large change, so we can agree on the approach before
  you spend time on it. Small fixes can go straight to a pull request.
- New behaviour comes with a test that fails without it. A fix for a bug comes
  with a test that reproduces the bug where that is possible.
- You must understand, and have run, every line you submit, however it was
  written. Pull requests that were not tested, that change code the author
  cannot explain, or that arrive in bulk are closed with a short note.
- The maintainer makes the final call. Decisions and the reasons for them are
  written in the issue or pull request, not in private.
- Issues and pull requests get a first reply within a week. Security reports
  get one within three working days (see SECURITY.md).
- Everyone taking part follows the [Code of Conduct](CODE_OF_CONDUCT.md).

## Commits

```
type(scope): what changes, in the imperative

Why it changes, wrapped at 72 characters. What was wrong or missing,
and anything a reviewer would not see from the diff.
```

- **Types:** `feat`, `fix`, `docs`, `style`, `refactor`, `perf`, `test`,
  `build`, `ci`, `chore`, `revert`. Add `!` after the scope for a change that
  breaks stored data or settings.
- **Scopes:** the area touched: `verifier`, `observer`, `beacon`, `admin`,
  `changes`, `scanner`, `robots`, `store`, `readme`, `build`, `deps`.
- **First line:** a command, in lower case after the colon ("add", not
  "added" or "adds"), with no full stop, saying what changes for the site
  owner where possible: `fix(verifier): keep the last good list when a
  download fails`, not `fix: update verifier.php`. Aim for 50 characters;
  72 is the limit, because GitHub cuts longer titles.
- **Body:** a blank line after the first line, then what changed and why,
  wrapped at 72 characters. A long URL may run over. Leave out how; the
  diff shows that.
- **Footers:** `BREAKING CHANGE: ...` for a change that breaks stored data
  or settings, and `Fixes #123` to close an issue.
- **Atomic:** one logical change per commit, with its tests and docs. Every
  commit builds and passes the tests on its own, so `git bisect` works.
  Formatting-only changes get their own commit.
- No tool attribution lines (`Co-Authored-By` for tools, `AI-Assisted`,
  `Generated with ...`) and no internal ticket ids. The history is public
  and should read as the project's own record.
- The author is a real person committing with their GitHub noreply address
  (`ID+username@users.noreply.github.com`), so no personal email is
  published.
- `bin/guard.sh` checks all of this except atomicity before each commit and
  again in CI.

## Branches, pull requests, releases

- `main` is always releasable. Work happens on short-lived branches named
  `type/short-description`, for example `fix/stale-list-warning`.
- Every change reaches `main` through a pull request that CI has passed, and is
  squash-merged with a title that follows the commit format. `main` is never
  force-pushed.
- Versions follow semantic versioning; the plugin is `0.x` until its settings
  and stored data are stable. The version lives in three places (the plugin
  header, `Stable tag` in readme.txt and `AN10_VERSION`); `bin/build.sh` refuses
  to build when they differ.
- A release is a commit `chore(release): 0.2.0` that bumps the version and adds
  a `== Changelog ==` entry written for site owners, then a tag `v0.2.0`.
  WordPress.org holds every release for a security scan of about six hours
  before it reaches sites.

## Rules the plugin keeps

**Privacy**
- No request to another site unless the site owner has turned the feature on,
  and every such request is listed under External services in readme.txt, with
  what is sent and when. A unit test fails if a new class makes HTTP requests.
- Never store or send an IP address, a full user agent, a query string or
  anything that identifies a person. No tracking of the site's admins or
  visitors for the developer's benefit.

**Security**
- Every form or action handler checks a nonce and a capability.
- Input is sanitized where it arrives; output is escaped where it is printed.
- Every SQL query with a variable goes through `$wpdb->prepare()`.

**WordPress**
- Everything global is prefixed `an10` / `AN10_`; the text domain is the slug.
- Styles load only on the plugin's own screen and every selector is scoped
  under `.an10`. No scripts in wp-admin.
- Admin notices appear only on the Dashboard and Plugins screens, are
  dismissible, and never advertise. No upsell banners anywhere.
- Activation creates the table and schedules local housekeeping. It prints
  nothing and contacts no other site.

**Honesty**
- The readme and the screens claim only what the code does. Where a number can
  only be a floor (cached pages, hidden referrers), the screen says so.
- `includes/generated/` and `assets/admin/tokens.css` are generated from
  `data/product-export.json` by `bin/generate.php`. Never edit them by hand.

## Licence

The plugin is GPLv2 or later. By contributing you agree that your contribution
is licensed the same way.
