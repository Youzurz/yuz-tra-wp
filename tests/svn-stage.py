import hashlib
import importlib.util
import pathlib
import subprocess
import tempfile
import unittest
import zipfile

root = pathlib.Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('stage_svn', root / 'tools/stage-svn.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

class Acceptance(unittest.TestCase):
    def test_real_local_repository(self):
        archive = root / 'release-inputs/yuz-tra-1.5.6.zip'
        digest = hashlib.sha256(archive.read_bytes()).hexdigest()
        with tempfile.TemporaryDirectory(prefix='yuz-svn-acceptance-') as tmp:
            base = pathlib.Path(tmp)
            repo, checkout = base / 'repo', base / 'checkout'
            subprocess.run(['svnadmin', 'create', str(repo)], check=True)
            uri = repo.as_uri()
            module.run('svn', 'mkdir', uri + '/trunk', uri + '/tags', '-m', 'Isolated fixture')
            module.run('svn', 'checkout', uri, str(checkout))
            with self.assertRaisesRegex(ValueError, 'digest mismatch'):
                module.stage(archive, checkout, 'yuz-tra', '1.5.6', '0' * 64, uri)
            module.stage(archive, checkout, 'yuz-tra', '1.5.6', digest, uri)
            with zipfile.ZipFile(archive) as package:
                for name in package.namelist():
                    if not name.endswith('/'):
                        relative = pathlib.PurePosixPath(name).relative_to('yuz-tra')
                        self.assertEqual(package.read(name), (checkout / 'trunk' / relative).read_bytes())
                        self.assertEqual(package.read(name), (checkout / 'tags/1.5.6' / relative).read_bytes())
            with self.assertRaisesRegex(ValueError, 'Dirty checkout'):
                module.stage(archive, checkout, 'yuz-tra', '1.5.6', digest, uri)
            module.run('svn', 'commit', str(checkout), '-m', 'Local acceptance only')
            with self.assertRaisesRegex(ValueError, 'Immutable tag'):
                module.stage(archive, checkout, 'yuz-tra', '1.5.6', digest, uri)
            with self.assertRaisesRegex(ValueError, 'Wrong SVN'):
                module.stage(archive, checkout, 'yuz-tra', '1.5.6', digest)

if __name__ == '__main__':
    unittest.main()
