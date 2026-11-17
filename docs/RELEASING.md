<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Releasing Advanced Webforms

See [CONTRIBUTING.md](../CONTRIBUTING.md) for the branch workflow. Releases are tagged on `main`.

## Before you tag

1. Bump `<version>` in `appinfo/info.xml` and add a `## <version>` section to `CHANGELOG.md`.
   The package script refuses to run if the changelog has no section for the version.
2. CI is green on `main`: typecheck, vitest, build, PHP lint and PHPUnit on 8.3 and 8.4, `reuse lint`.
3. Export any real forms from dev first, then deploy to the dev instance
   (`dev/deploy.sh <user>@<dev host>`) and click through the
   builder, a public form, and the responses view.

## Install a release on a server

Pushing a tag that matches `<version>` in `appinfo/info.xml` builds the release:

```bash
git tag -a v0.3.1 -m "Advanced Webforms 0.3.1" && git push origin v0.3.1
```

`.github/workflows/release.yml` runs `tools/package.sh` and attaches `webforms-<version>.tar.gz`
to a GitHub release. Wait for it to finish (`gh release view v0.3.1`). If the build fails, fix it and
tag the next version. Do not move a published tag.

On the Nextcloud server, as the user that owns the Nextcloud files, run:

```bash
NC_ROOT=/path/to/nextcloud tools/install-release.sh
```

`NC_ROOT` is the folder that holds `occ`. The script downloads the latest release (or `VERSION=v0.3.1`),
replaces `custom_apps/webforms`, runs `occ upgrade` and `occ app:enable webforms`, and then reloads
PHP-FPM. Set `TOKEN_FILE` to a file holding a read-only GitHub token if the repository is private.

The reload matters: PHP-FPM is often set not to look for changed files (`opcache.validate_timestamps=0`),
and then the site keeps running the old code until it is reloaded. Checking `php -i` on the command line
does not show this, because the command line reads a different ini. The script reloads `php<version>-fpm`
through `sudo -n`, so the user needs permission for it (hosts such as Ploi give the site user exactly
that). Set `FPM_SERVICE` if the service has another name, or `SKIP_FPM_RELOAD=1` to leave it alone. When
the reload is not allowed, the script says so and exits normally: reload PHP-FPM yourself.

Nextcloud only loads apps from the folders in the `apps_paths` setting. If a server has no
`custom_apps` entry, enabling the app fails with "not found on the appstore". The script checks
for this and prints the one-time `occ config:system:set apps_paths` command to run.

## Translations

English is the source language and needs no file. Every `t('webforms', '...')` call in
`src/` is collected into `translationfiles/templates/webforms.pot`:

```bash
npm run l10n:extract
```

A translator adds `translationfiles/<lang>/webforms.po` (Poedit, Transifex, or by hand).
Untranslated entries are skipped, so a partial translation falls back to English. Compile them
into the files Nextcloud loads, `l10n/<lang>.js` and `l10n/<lang>.json`, and commit the result:

```bash
npm run l10n:compile
```

Re-run the extract step after adding or changing UI text, and commit the new `.pot`.

## Build the archive

```bash
tools/package.sh
```

This does a clean `npm ci`, compiles translations, wipes `js/` and `css/`, builds, and writes
`dist/webforms-<version>.tar.gz` with a single top-level `webforms/` folder. Only
runtime files are included: no sources, tests, tooling, `node_modules` or source maps.

## Sign and publish

The app store needs the app registered once with a signing certificate, and every release
signed with the matching private key. Check the current steps in the Nextcloud developer
documentation ("Publishing apps on the app store") before the first release.

One-time setup:

```bash
openssl req -nodes -newkey rsa:4096 -keyout webforms.key -out webforms.csr -subj "/CN=webforms"
```

Keep `webforms.key` private and out of the repository. Submit the `.csr` to the Nextcloud
app certificate repository, then register the app on https://apps.nextcloud.com with the
certificate you get back.

For each release:

1. Attach `dist/webforms-<version>.tar.gz` to a GitHub release for the tag, so it has a
   stable download URL.
2. Sign the archive:

   ```bash
   openssl dgst -sha512 -sign webforms.key dist/webforms-<version>.tar.gz | openssl base64
   ```

3. On the app store, upload the release with that download URL and the signature.

## Still to prepare

- Screenshots for the app store listing (add `<screenshot>` entries to `appinfo/info.xml`
  once they are hosted).
- At least one translation besides English, if you want the app store to show language support.
