"""Prepare, never publish, an exact reviewed artifact in a clean SVN checkout."""
import argparse
import hashlib
import pathlib
import re
import stat
import subprocess
import tempfile
import zipfile

def run(*args):
    return subprocess.check_output(args, text=True).strip()

def stage(archive, checkout, slug, version, digest, repository=None):
    if not re.fullmatch(r'[a-z0-9]+(?:-[a-z0-9]+)*', slug):
        raise ValueError('Invalid slug')
    if not re.fullmatch(r'\d+\.\d+\.\d+', version):
        raise ValueError('Invalid version')
    if not re.fullmatch(r'[a-f0-9]{64}', digest):
        raise ValueError('Expected SHA-256 required')
    if hashlib.sha256(archive.read_bytes()).hexdigest() != digest:
        raise ValueError('Artifact digest mismatch')
    expected = repository or 'https://plugins.svn.wordpress.org/' + slug
    # Test override cannot redirect publication to another remote host.
    if repository and not repository.startswith('file:///'):
        raise ValueError('Test repository must be local file URI')
    if not checkout.is_dir() or checkout.is_symlink():
        raise ValueError('Existing real checkout directory required')
    actual = run('svn', 'info', '--show-item', 'url', str(checkout))
    if actual.rstrip('/') != expected.rstrip('/'):
        raise ValueError('Wrong SVN repository')
    if run('svn', 'status', str(checkout)):
        raise ValueError('Dirty checkout: preserve existing work')
    run('svn', 'update', '--non-interactive', str(checkout))
    if run('svn', 'status', str(checkout)):
        raise ValueError('Checkout not clean after update')
    if (checkout / 'tags' / version).exists():
        raise ValueError('Immutable tag already exists')
    with zipfile.ZipFile(archive) as package, tempfile.TemporaryDirectory(prefix='yuz-svn-') as tmp:
        names = set()
        for entry in package.infolist():
            name = pathlib.PurePosixPath(entry.filename)
            if (name.is_absolute() or '..' in name.parts or '\\' in entry.filename
                    or not name.parts or name.parts[0] != slug or '.svn' in name.parts
                    or stat.S_ISLNK(entry.external_attr >> 16) or entry.filename in names):
                raise ValueError('Unsafe archive entry')
            names.add(entry.filename)
        package.extractall(tmp)
        source = pathlib.Path(tmp) / slug
        if not (source / (slug + '.php')).is_file():
            raise ValueError('Main plugin file missing')
        if 'Stable tag: ' + version not in (source / 'readme.txt').read_text().splitlines():
            raise ValueError('Stable tag mismatch')
        for directory in ('trunk', 'tags'):
            if not (checkout / directory).is_dir() or (checkout / directory).is_symlink():
                raise ValueError('Expected trunk/tags directories required')
        run('rsync', '-a', '--delete', str(source) + '/', str(checkout / 'trunk') + '/')
        run('svn', 'add', '--force', str(checkout / 'trunk'))
        for line in run('svn', 'status', str(checkout / 'trunk')).splitlines():
            if line.startswith('!'):
                run('svn', 'rm', '--force', line[8:])
        run('svn', 'copy', str(checkout / 'trunk'), str(checkout / 'tags' / version))
    print('PASS staged exact artifact; no commit performed')

if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    for name in ('archive', 'checkout', 'slug', 'version', 'sha256'):
        parser.add_argument(name)
    args = parser.parse_args()
    stage(pathlib.Path(args.archive), pathlib.Path(args.checkout), args.slug, args.version, args.sha256)
