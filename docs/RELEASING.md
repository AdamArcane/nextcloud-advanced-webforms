<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Releasing Advanced Webforms

## Before you tag

1. Bump `<version>` in `appinfo/info.xml` and add a `## <version>` section to `CHANGELOG.md`.
   The package script refuses to run if the changelog has no section for the version.
2. CI is green on `main`: typecheck, vitest, build, PHP lint and PHPUnit on 8.3 and 8.4, `reuse lint`.
3. Export any real forms from dev first, then deploy to the dev instance
   (`dev/deploy.sh user@dev-host`) and click through the
   builder, a public form, and the responses view.

## Deploy to the live site

The live Nextcloud is the Ploi site `cloud.example.com` on `live-server`. Ploi cannot
deploy it, because the site has no repository attached, so the app is installed from a GitHub
release instead.

1. Push a tag that matches `<version>` in `appinfo/info.xml`:

   ```bash
   git tag -a v0.3.1 -m "Advanced Webforms 0.3.1" && git push origin v0.3.1
   ```

   `.github/workflows/release.yml` builds `webforms-<version>.tar.gz` and attaches it to a
   GitHub release. Wait for it to finish (`gh release view v0.3.1`). If the build fails, fix it
   and tag the next version. Do not move a published tag.
2. On the live server, as the site user `siteuser`, run the install script. It downloads the
   latest release, replaces `custom_apps/webforms`, and runs `occ upgrade` and `occ app:enable`:

   ```bash
   curl -fsSL -H "Authorization: Bearer $(tr -d '[:space:]' < ~/.webforms-secret)" -H "Accept: application/vnd.github.raw" https://api.github.com/repos/AdamArcane/nextcloud-advanced-forms/contents/dev/live-install.sh | bash
   ```

   The source is `dev/live-install.sh`.
3. Reload PHP-FPM if the old build still shows (opcache), then check the app in the browser.

Setup that is already done on live, for reference:

- `~/.webforms-secret` holds a fine-grained GitHub token with read-only Contents access to this
  repo (mode 600). Replace it if the token expires or is revoked.
- Nextcloud root is `~/cloud.example.com/public`. Its `config.php` had no `apps_paths`, so
  Nextcloud ignored `custom_apps`. It was set once with:

  ```bash
  cd ~/cloud.example.com/public && php occ config:system:set apps_paths --type=json --value="[{\"path\":\"$PWD/apps\",\"url\":\"/apps\",\"writable\":false},{\"path\":\"$PWD/custom_apps\",\"url\":\"/custom_apps\",\"writable\":true}]"
  ```

- The live app is Nextcloud 35.0.3. The app only supports 35 (`info.xml` min and max).

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
