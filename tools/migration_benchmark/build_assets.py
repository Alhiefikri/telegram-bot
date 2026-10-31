#!/usr/bin/env python3
"""Attach the just-built local assets to P01's verified-dependency comparator."""
import json
from pathlib import Path
import shutil
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'tools/migration_harness'))
import harness


def main():
    harness.docker_admission()
    assets = ROOT / 'src/public/build'
    if not (assets / 'manifest.json').is_file():
        raise harness.Refused('build current assets before freezing the comparator')
    if any(p.is_symlink() for p in assets.rglob('*')):
        raise harness.Refused('asset symlinks are not admitted')
    image = json.loads(harness.run(['docker', 'image', 'inspect', 'lhie-p01-laravel:1'], True))[0]
    if (image['Config'].get('Labels') or {}).get('lhie.p01.build-profile') != 'local-verified-dependencies':
        raise harness.Refused('requires the explicit P01 verified-dependency local build')
    base = 'lhie-p04-assets-base:' + image['Id'].removeprefix('sha256:')
    harness.run(['docker', 'tag', image['Id'], base])
    with tempfile.TemporaryDirectory(prefix='p04-assets-') as directory:
        context = Path(directory)
        shutil.copytree(assets, context / 'assets')
        labels = {'lhie.p04.assets-tree-sha256': harness.tree_digest(assets),
                  'lhie.p04.assets-base-image': image['Id']}
        (context / 'Dockerfile').write_text('FROM ' + base + '\n'
            'COPY --chown=1000:1000 assets/ /var/www/html/public/build/\n'
            'COPY --chown=1000:1000 assets/ /opt/lhiestore-public-build/\nLABEL '
            + ' '.join(k + '=' + json.dumps(v) for k, v in labels.items()) + '\n')
        harness.run(['docker', 'build', '--network', 'none', '-t', 'lhie-p01-laravel:1', str(context)])
    print(json.dumps(labels))


if __name__ == '__main__':
    main()
