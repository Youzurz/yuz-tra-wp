# WordPress.org release procedure

## Preconditions

- The plugin version in `yuz-tra.php` matches `Stable tag` in `readme.txt`.
- `scripts/check-release.sh` passes.
- The release tag follows semantic versioning, for example `v1.2.1`.
- WordPress.org assets are prepared in `.wordpress-org/`.

## Required CI/CD secrets or variables

Use the same variable names on GitHub Actions and GitLab CI:

- `WPORG_SLUG`
- `WPORG_SVN_USERNAME`
- `WPORG_SVN_PASSWORD`

Recommended value:

- `WPORG_SLUG=yuz-tra-wp`

## ZIP build

Build the distributable ZIP locally:

```bash
scripts/check-release.sh
scripts/build-zip.sh
```

The ZIP is created in `dist/` with the format:

```text
dist/yuz-tra-wp-<version>.zip
```

## GitHub release

Push `main` and the version tag to GitHub:

```bash
git push github main
git push github v1.2.1
```

The GitHub workflow:

- validates the release
- builds the ZIP
- uploads the ZIP as a workflow artifact
- creates a GitHub release and attaches the ZIP

## GitLab release

Push the same branch and tag to GitLab:

```bash
git push gitlab main
git push gitlab v1.2.1
```

The GitLab pipeline:

- validates the release
- builds the ZIP
- stores the ZIP as a job artifact
- creates a GitLab release with a direct asset link to the ZIP artifact

## WordPress.org SVN deploy

Use the dedicated deploy script:

```bash
WPORG_SLUG=yuz-tra-wp \
WPORG_SVN_USERNAME=... \
WPORG_SVN_PASSWORD=... \
scripts/deploy-wporg.sh
```

What the script does:

1. Re-runs release validation.
2. Builds a clean staging directory from `.distignore`.
3. Checks out the WordPress.org SVN repository.
4. Syncs the plugin to `trunk/`.
5. Syncs `.wordpress-org/` to SVN `assets/`.
6. Creates `tags/<version>/`.
7. Commits the release to SVN.

## Assets naming

WordPress.org plugin assets live in the SVN top-level `assets/` directory, not in `trunk/`.

Recommended filenames:

- `banner-772x250.png`
- `banner-1544x500.png`
- `icon-128x128.png`
- `icon-256x256.png`
- `screenshot-1.png`
- `screenshot-2.png`

Optional:

- `icon.svg`

## Operational recommendation

Keep WordPress.org deployment as a controlled release step.

Recommended order:

1. Tag the release.
2. Validate the generated ZIP on GitHub and GitLab.
3. Trigger the WordPress.org deployment once the tag is confirmed.
