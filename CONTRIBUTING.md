<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Contributing

Thanks for helping. Issues and pull requests are welcome.

## Branches

- `main` is released code only. Every release is tagged on it. It is protected: changes arrive by pull
  request and CI must pass.
- `dev` is where finished work collects before a release. Open pull requests against `dev`.
- Work on a short-lived branch from `dev`: `feature/<topic>` or `fix/<topic>`. Urgent fixes to a
  released version use `hotfix/<topic>` from `main`, and are merged back into `dev` afterwards.

## A change

1. Branch from `dev`.
2. Build it and run the checks (see README.md, Development): `npm run typecheck`, `npm test`,
   `composer test:unit`. Keep tests passing, and add tests for new behaviour.
3. Add a line to the `Unreleased` section of `CHANGELOG.md` for anything a user would notice.
4. Open a pull request into `dev`. CI runs the checks. Squash merge is fine.

New files need an SPDX header (`reuse lint`). Do not use em dashes in the documentation.

## A release (maintainer)

1. Open a pull request from `dev` into `main` that sets the new `<version>` in `appinfo/info.xml` and
   dates the changelog section. Merge it with a merge commit, not a squash, so `dev` and `main` stay
   related.
2. Tag the merge commit on `main` as `vX.Y.Z`. The release workflow builds the archive and attaches it
   to a GitHub release. It refuses a tag that is not on `main`.
3. Merge `main` back into `dev`.

See [docs/RELEASING.md](docs/RELEASING.md) for building, installing and signing a release.

## Reporting a problem

Open an issue. For a security problem, do not describe it publicly: open an issue asking for a private
way to report it.
