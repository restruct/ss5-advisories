# Advisory feed for the CMS 5 security backport forks

*Maintained by [Restruct](https://github.com/restruct). If this saves you time, you can [support ongoing maintenance](https://github.com/sponsors/restruct).*

A small Composer repository, published at `https://restruct.github.io/ss5-advisories`, that lets a Silverstripe CMS 5
project install the restruct security backport forks without `composer audit` flagging the very advisories they fix,
and without hiding anything they do not fix.

> **What to expect**
>
> - It exists for the forks below and goes with them: best effort, for as long as CMS 5 is in partial support upstream,
>   and it may be discontinued at any time. Not affiliated with or endorsed by Silverstripe.
> - It rebuilds itself from Packagist every day. A new advisory reaches your project without anyone editing anything.
> - It never hides an advisory it has not been told about. See "What it does" below.

## Why it is needed

The forks ([framework](https://github.com/restruct/silverstripe-framework),
[versioned](https://github.com/restruct/silverstripe-versioned), [assets](https://github.com/restruct/silverstripe-assets),
[userforms](https://github.com/restruct/silverstripe-userforms)) keep the upstream package names and tag their releases
`<upstream version>-pN`, for example `5.4.30-p1`. Packagist records advisories by package name and version range, and
those ranges (such as `<6.2.13`) include the patched tags. So, out of the box, Composer refuses to install them and
`composer audit` reports advisories that are fixed.

The alternatives were measured before this was built (Composer 2.10):

- `policy.advisories.ignore-id` per project works, but has to be edited for every release, and silences the advisory
  for every version of the package, including an unpatched upstream release.
- Renaming the fork and using `replace` does not help: Composer checks the replaced package's advisories against the
  renamed package's own version, and `composer audit` then reports nothing at all for it, including future advisories.
- A second repository cannot overrule Packagist's advisories: Composer merges the advisories of all repositories.

## What it does

`build.php` copies Packagist's advisories for the forked packages, plus `silverstripe/cms`, into a static Composer
repository. For each advisory triaged in [`advisories.json`](advisories.json) it narrows the affected range:

- **Backported** (`fixed-in`): the fork's patched tags from that one on drop out of the range. Every upstream release
  stays exactly as affected as before, including later upstream releases on the same line. Example:
  `<6.2.13` becomes `<6.2.13,<5.4.30-p1 || <6.2.13,>=5.4.31`.
- **Not applicable** (`affected`): the range is replaced by the version lines it really affects, with the reason
  recorded in `advisories.json`. Today only `silverstripe/cms` CVE-2026-54717, which does not affect cms 5.

Before anything is published, each rewritten range is checked against every upstream release on Packagist and every
patched fork tag. A mismatch stops the build, and the previous feed stays live.

An advisory that is **not** in `advisories.json` is passed through unchanged, so it blocks and reports exactly as it
would from Packagist. If it affects what a CMS 5 site installs, the daily build opens an issue here to have it
triaged.

`silverstripe/cms` is not forked. To be able to rewrite its advisory, the feed also serves a copy of Packagist's
metadata for it: the same versions and the same download URLs as on Packagist.

## Use it in a project

Add the forks, the feed, and Packagist with these five names excluded. Order matters: the forks first.

```json
"repositories": [
    {"type": "vcs", "url": "https://github.com/restruct/silverstripe-framework", "only": ["silverstripe/framework"]},
    {"type": "vcs", "url": "https://github.com/restruct/silverstripe-versioned", "only": ["silverstripe/versioned"]},
    {"type": "vcs", "url": "https://github.com/restruct/silverstripe-assets", "only": ["silverstripe/assets"]},
    {"type": "vcs", "url": "https://github.com/restruct/silverstripe-userforms", "only": ["silverstripe/userforms"]},
    {"type": "composer", "url": "https://restruct.github.io/ss5-advisories"},
    {"type": "composer", "url": "https://repo.packagist.org",
     "exclude": ["silverstripe/framework", "silverstripe/versioned", "silverstripe/assets", "silverstripe/userforms", "silverstripe/cms"]},
    {"packagist.org": false}
]
```

Then `composer update silverstripe/framework silverstripe/versioned silverstripe/assets silverstripe/userforms silverstripe/cms`.

Notes:

- Use the forks and the feed together. The feed provides no versions of the four forked packages itself, so without
  the forks they cannot be installed at all.
- If the feed cannot be reached, `composer update` carries on with a warning and without these advisories, while
  `composer audit` fails. To make `update` fail as well, add `"config": {"policy": {"ignore-unreachable": false}}`.
- `silverstripe/mfa` CVE-2026-55186 is not handled here: it is fixed by installing
  [restruct/silverstripe-mfa-bundle](https://github.com/restruct/silverstripe-mfa-bundle), which a shared feed cannot
  see. A project that has the bundle at 1.9.1 or later (require `"restruct/silverstripe-mfa-bundle": "^1.9.1"`)
  ignores that one advisory itself, with a reason:
  `"config": {"policy": {"advisories": {"ignore-id": {"PKSA-xxk3-rjxm-jftv": "fixed by restruct/silverstripe-mfa-bundle >= 1.9.1"}}}}`.
- When the project moves to CMS 6, remove all of the above.

## Maintaining it

- **An issue "Untriaged advisory ..." was opened:** backport the fix into the fork and tag it, then add the advisory to
  `advisories.json` with `fixed-in` set to that tag. Or record in `advisories.json`, with the reason, which versions it
  really affects.
- **A fork got a new upstream release merged in** (tag `5.4.31-p1` and so on): nothing to do here. The next build reads
  the fork's tags and adjusts every range.
- **Run it locally:** `composer install`, then `php build.php file://$PWD/public`, and point a test project at
  `file://<path>/public`. `FORK_TAGS='{"silverstripe/framework":["5.4.30-p1"]}'` stands in for the fork tags before they
  are pushed.

## License

BSD-3-Clause.
