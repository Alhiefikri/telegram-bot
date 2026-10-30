#!/usr/bin/env python3
"""Disposable Docker smoke; no provider, customer or production calls."""
import json
from pathlib import Path
import subprocess
import sys
import urllib.request

import harness


def cli(*args, expected=0):
    result = subprocess.run([sys.executable, str(Path(harness.__file__)), *args],
                            capture_output=True, text=True)
    if result.returncode != expected:
        raise AssertionError("unexpected lifecycle result: " + " ".join(args))
    return result.stdout.strip()


def docker(*args):
    return subprocess.check_output(["docker", *args], text=True).strip()


def container(stack, service):
    return docker("ps", "-q", "--filter", "label=com.docker.compose.project=" + harness.PREFIX + stack,
                  "--filter", "label=com.docker.compose.service=" + service)


def main():
    evidence = []
    for stack in ("candidate", "laravel"):
        directory = harness.STATE / stack
        if not (directory / "config.json").exists():
            cli("init", stack)
        config = harness.validate(json.loads((directory / "config.json").read_text()))
        if config != harness.default(stack):
            raise RuntimeError("smoke requires unmodified synthetic-empty fake configuration")
        cli("reset", stack)
        # A synthetic original is outside disposable volumes and must survive reset.
        original = directory / "synthetic-original.snapshot"
        original.write_bytes(b"synthetic original; never imported")
        guarded = harness.PREFIX + stack + "_guard-negative"
        if docker("volume", "ls", "-q", "--filter", "name=" + guarded):
            raise RuntimeError("negative-test volume already exists; refusing to touch it")
        docker("volume", "create", guarded)
        try:
            cli("reset", stack, expected=1)
            assert docker("volume", "ls", "-q", "--filter", "name=" + guarded) == guarded
        finally:
            docker("volume", "rm", guarded)
        try:
            manifest_path = cli("start", stack)
            manifest = json.loads(Path(manifest_path).read_text())
            assert len(manifest["containers"]) == 4
            assert sum(c["memory"] for c in manifest["containers"]) <= 2 * 1024 ** 3
            assert sum(c["nano_cpus"] for c in manifest["containers"]) <= 2 * 10 ** 9
            assert all(c["memory_swap"] == c["memory"] for c in manifest["containers"])
            # Actual cgroup enforcement, no reliance on the generated compose document.
            for service in ("boundary", "database", "redis", "app"):
                cid = container(stack, service)
                observed = docker("exec", cid, "sh", "-c",
                    "cat /sys/fs/cgroup/memory.max /sys/fs/cgroup/memory.swap.max /sys/fs/cgroup/cpu.max")
                memory, swap, cpu = observed.splitlines()
                record = next(c for c in manifest["containers"] if c["service"] == service)
                quota, period = map(int, cpu.split())
                assert int(memory) == record["memory"] and int(swap) == 0
                assert quota / period == record["nano_cpus"] / 10 ** 9
            other = "laravel" if stack == "candidate" else "candidate"
            if not (harness.STATE / other / "config.json").exists():
                cli("init", other)
            cli("start", other, expected=1)
            # Transport probes execute inside the app's actual namespace and unprivileged UID.
            probe = r'''
import json, socket, smtplib, urllib.request
for target in (('1.1.1.1',443), ('host.docker.internal',80), ('api.telegram.org',443)):
    try:
        s=socket.create_connection(target,timeout=1)
    except OSError:
        pass
    else:
        s.close(); raise AssertionError('forbidden egress')
# Docker embedded DNS must not provide a DNS exfiltration bypass.
s=socket.socket(socket.AF_INET,socket.SOCK_DGRAM); s.settimeout(1)
query=bytes.fromhex('123401000001000000000000')+b'\x07example\x03com\x00'+bytes.fromhex('00010001')
try:
    s.sendto(query,('127.0.0.11',53))
    s.recv(4096); raise AssertionError('forbidden DNS')
except (TimeoutError, PermissionError):
    pass
s.close()
req=urllib.request.Request('http://127.0.0.1:8025/telegram',json.dumps({'chat_id':1,'text':'synthetic'}).encode())
assert json.load(urllib.request.urlopen(req,timeout=2))['fake']
with smtplib.SMTP('127.0.0.1',1025,timeout=2) as smtp:
    assert smtp.sendmail('test@example.invalid',['test@example.invalid'],'Subject: smoke\n\nsynthetic') == {}
print('isolated transport and mail sink passed')
'''
            # Boundary image supplies the Python probe; UID 1000 has the same firewall policy as app.
            docker("exec", "--user", "1000:1000", container(stack, "boundary"), "python", "-c", probe)
            # A fresh synthetic DB is intentionally unmigrated; Laravel's route may return 500.
            # PHP is still serving; this is transport smoke, not application parity.
            port = 18801 if stack == "laravel" else 18802
            try:
                response = urllib.request.urlopen(f"http://127.0.0.1:{port}/health", timeout=5)
                response.close()
            except urllib.error.HTTPError:
                pass
            db = container(stack, "database")
            docker("exec", db, "psql", "-U", "harness", "-d", "disposable", "-c",
                   "CREATE TABLE p01_isolation (id int); INSERT INTO p01_isolation VALUES (1)")
            cli("stop", stack)
            cli("start", stack, "--quarantine")
            assert not container(stack, "app")
            count = docker("exec", container(stack, "database"), "psql", "-U", "harness", "-d", "disposable",
                           "-Atc", "SELECT count(*) FROM p01_isolation")
            assert count == "1"
            cli("stop", stack)
            cli("start", stack, expected=1)  # Raw restore quarantine cannot be bypassed by restart.
            cli("reset", stack)
            assert original.read_bytes() == b"synthetic original; never imported"
            cli("start", stack, "--quarantine")
            count = docker("exec", container(stack, "database"), "psql", "-U", "harness", "-d", "disposable",
                           "-Atc", "SELECT count(*) FROM information_schema.tables WHERE table_name='p01_isolation'")
            assert count == "0"
            evidence.append({"stack": stack, "manifest": manifest_path, "cgroup": "passed",
                             "egress_mail": "passed", "alternation": "passed",
                             "stop_persistence_reset_quarantine": "passed"})
        finally:
            cli("reset", stack)
    print(json.dumps(evidence, indent=2))


if __name__ == "__main__":
    main()
