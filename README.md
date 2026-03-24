# YUZ Translation WordPress.org Build

This repository is the WordPress.org-ready distribution repository for YUZ Translation.

It is intended to be pushed to both:

- `https://github.com/Youzurz/yuz-tra-wp`
- `https://git.youzurz.com/yuz/yuz-tra-wp`

## Release flow

1. Update the plugin version in `yuz-tra.php`.
2. Update `Stable tag` in `readme.txt` to the same version.
3. Run `scripts/check-release.sh`.
4. Run `scripts/build-zip.sh`.
5. Create and push an annotated tag such as `v1.2.1`.
6. Let GitHub Actions and GitLab CI publish the ZIP release.
7. Trigger the WordPress.org deploy job when the tag is validated.

## WordPress.org assets

Place plugin-directory assets in `.wordpress-org/`.

Expected filenames are documented in:

- `docs/release-wordpress-org.md`
- `.wordpress-org/README.md`

## Remotes bootstrap

```bash
git init
git branch -M main
git remote add github https://github.com/Youzurz/yuz-tra-wp.git
git remote add gitlab https://git.youzurz.com/yuz/yuz-tra-wp.git
```
