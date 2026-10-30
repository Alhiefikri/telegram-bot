#!/usr/bin/env python3
"""P05 fake/synthetic runtime smoke. Starts and resets only the P01 candidate."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import urllib.error
import urllib.request

import harness
from smoke import cli, container, docker


def request(path, status, payload, *, method="GET", host="127.0.0.1:18802"):
    req = urllib.request.Request("http://127.0.0.1:18802" + path, method=method,
                                 headers={"Host": host, "Authorization": "Bearer synthetic-sentinel"})
    try:
        response = urllib.request.urlopen(req, timeout=6)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        body = response.read(4096)
        assert response.status == status
        assert body == b"" if method == "HEAD" else json.loads(body) == {"status": payload}
        assert b"synthetic-sentinel" not in body
        assert response.headers["Cache-Control"] == "no-store"
        assert response.headers["X-Content-Type-Options"] == "nosniff"


def negative_startups(directory):
    from copy import deepcopy
    runtime = json.loads((directory / "candidate.json").read_text())
    cases = []
    for field, value in (("quarantine", True), ("identity", "production-synthetic-sentinel"),
                         ("dataset", "raw-synthetic-sentinel")):
        cases.append({**runtime, field: value})
    document = deepcopy(runtime)
    document["database"]["host"] = "production-synthetic-sentinel.invalid"
    cases.append(document)
    document = deepcopy(runtime)
    document["limits"]["threads"] = 2
    cases.append(document)
    with tempfile.TemporaryDirectory() as temporary:
        config = Path(temporary) / "candidate.json"
        for document in cases:
            config.write_text(json.dumps(document))
            config.chmod(0o600)
            result = subprocess.run([
                "docker", "run", "--rm", "--network", "none", "--read-only", "--cap-drop", "ALL",
                "--security-opt", "no-new-privileges:true", "--mount",
                f"type=bind,src={config},dst=/run/harness/candidate.json,readonly", "--mount",
                f"type=bind,src={directory / 'django-key'},dst=/run/harness/django-key,readonly",
                "lhie-p05-candidate:1"], capture_output=True, timeout=20)
            assert result.returncode == 1
            assert result.stdout == b"" and result.stderr == b"candidate startup refused\n"
    return len(cases)


def main():
    os.umask(0o077)
    directory = harness.STATE / "candidate"
    config = harness.validate(json.loads((directory / "config.json").read_text()))
    if config != harness.default("candidate"):
        raise RuntimeError("P05 smoke requires default fake synthetic-empty configuration")
    if docker("ps", "-q", "--filter", "label=lhie.p01=disposable"):
        raise RuntimeError("Stop the existing disposable stack before P05 smoke")
    cli("reset", "candidate")
    try:
        manifest_path = cli("start", "candidate")
        request("/health", 200, "ok")
        request("/ready", 200, "ready")
        request("/health", 200, "ok", method="HEAD")
        request("/synthetic-sentinel?token=synthetic-sentinel", 404, "not_found")
        request("/ready?token=synthetic-sentinel", 405, "method_not_allowed", method="POST")
        request("/health", 400, "bad_request", host="synthetic-sentinel.invalid")
        app = container("candidate", "app")
        docker("exec", app, "python", "manage.py", "check")
        docker("exec", app, "python", "manage.py", "collectstatic", "--noinput")
        probe = '''
from lhie_candidate.startup import configure_django
configure_django()
from django.template import engines
assert engines['django'].from_string('{{ value }}').render({'value':'<script>synthetic</script>'}) == '&lt;script&gt;synthetic&lt;/script&gt;'
from django.db import connection
from django.db.utils import ProgrammingError
try:
    with connection.cursor() as c:
        c.execute('CREATE TABLE public.p05_forbidden (id int)')
except ProgrammingError:
    pass
else:
    raise AssertionError('Runtime role wrote legacy schema')
finally:
    connection.close()
print('escaped templates and runtime public-schema denial passed')
'''
        docker("exec", app, "python", "-c", probe)
        outcome = json.loads(docker("exec", "-e", "CANDIDATE_POSTGRES_SMOKE=disposable-local-only",
                                   app, "python", "smoke_postgres.py"))
        assert outcome["status"] == "passed"
        database = container("candidate", "database")
        tables = docker("exec", database, "psql", "-U", "harness", "-d", "disposable", "-Atc",
                        "SELECT count(*) FROM information_schema.tables WHERE table_schema IN ('public','django_candidate')")
        assert tables == "0"
        assert docker("inspect", "--format", "{{.HostConfig.LogConfig.Type}}", app) == "none"
        negatives = negative_startups(directory)
        docker("pause", container("candidate", "redis"))
        try:
            request("/ready", 503, "not_ready")
            request("/health", 200, "ok")
        finally:
            docker("unpause", container("candidate", "redis"))
        request("/ready", 200, "ready")
        print(json.dumps({"status": "passed", "dataset": "synthetic-empty", "manifest": manifest_path,
                          "http_health_readiness_redaction": "passed", "dependency_loss_recovery": "passed",
                          "runtime_schema_denial": "passed", "template_escape_static_collection": "passed",
                          "startup_refusals": negatives, "postgres": outcome}, indent=2))
    finally:
        cli("reset", "candidate")


if __name__ == "__main__":
    main()
