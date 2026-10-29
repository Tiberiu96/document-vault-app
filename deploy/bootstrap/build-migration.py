#!/usr/bin/env python3
"""Construieste installerul standalone; toate componentele provin din repository."""
import base64
import hashlib
import json
from pathlib import Path
import zlib

root = Path(__file__).resolve().parents[2]
bootstrap = root / 'deploy/bootstrap'
sources = {
    'runtime.sh': bootstrap / 'install-ubuntu-24.04.sh',
    'deploy-vaulty.sh': bootstrap / 'deploy-vaulty.sh',
    'restore-vaulty.sh': bootstrap / 'restore-vaulty.sh',
    'configure.py': bootstrap / 'migration/configure.py',
    'data.php': bootstrap / 'migration/data.php',
}
for name in ('vaulty-backup.service', 'vaulty-backup-monitor.service', 'vaulty-backup.timer',
             'vaulty-backup-monitor.timer', 'vaulty-restore.service', 'vaulty-restore.path'):
    sources[name] = root / 'deploy/systemd' / name
assets = {}
for name, path in sources.items():
    content = path.read_bytes()
    assets[name] = {'sha256': hashlib.sha256(content).hexdigest(), 'data': base64.b64encode(content).decode()}
payload = base64.b64encode(zlib.compress(json.dumps(assets, sort_keys=True, separators=(',', ':')).encode(), 9))
script = (bootstrap / 'migration/main.sh').read_bytes() + payload + b'\n'
output = bootstrap / 'migrare_finala_vaulty_24.04.sh'
output.write_bytes(script)
output.chmod(0o755)
print(str(output))
