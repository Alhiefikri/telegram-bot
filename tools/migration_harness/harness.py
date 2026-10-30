#!/usr/bin/env python3
"""Local P01 admission and disposable lifecycle. Python standard library only."""
import argparse
import base64
import copy
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import subprocess
import sys
import tempfile
import time

ROOT = Path(__file__).resolve().parents[2]
STATE = ROOT / ".local/migration-p01"
TOOLS = Path(__file__).resolve().parent
PREFIX = "lhie-p01-"
IMAGE = "lhie-p01-boundary:1"
FIELDS = {"version", "stack", "mode", "dataset", "db_url", "redis_url", "bot_token",
          "bot_id", "bot_username", "safe_chat_ids", "sandbox_status_url", "sandbox_token",
          "approval"}


class Refused(Exception):
    pass


def validate(config):
    if not isinstance(config, dict) or set(config) != FIELDS:
        raise Refused("configuration fields do not match the P01 contract")
    if config["version"] != 1 or config["stack"] not in ("laravel", "candidate"):
        raise Refused("unsupported stack or contract")
    if config["mode"] not in ("fake", "sandbox", "testing-bot"):
        raise Refused("unsupported transport mode")
    if not re.fullmatch(r"synthetic-[a-z0-9-]{1,60}|sanitized-[a-f0-9]{64}", config["dataset"]):
        raise Refused("dataset must be synthetic or carry a sanitized manifest digest")
    if config["db_url"] != "postgresql://harness:harness@127.0.0.1:5432/disposable":
        raise Refused("only the generated disposable PostgreSQL endpoint is admitted")
    if config["redis_url"] != "redis://127.0.0.1:6379/0":
        raise Refused("only the generated disposable Redis endpoint is admitted")
    chats = config["safe_chat_ids"]
    if (not isinstance(chats, list) or not 1 <= len(chats) <= 10
            or any(type(chat) is not int or chat <= 0 for chat in chats)):
        raise Refused("only explicitly listed private testing/admin chats are admitted")
    if config["mode"] == "fake":
        if any(config[key] for key in ("bot_token", "bot_id", "bot_username", "sandbox_status_url",
                                       "sandbox_token", "approval")):
            raise Refused("fake mode rejects real credentials or destinations")
    else:
        approval = config["approval"]
        # H0 operator attestation, not a gate an agent can supply on the operator's behalf.
        if not isinstance(approval, dict) or set(approval) != {"gate", "scope_sha256"}:
            raise Refused("explicit H0 transport attestation required")
        scope = {key: config[key] for key in sorted(FIELDS - {"approval"})}
        digest = hashlib.sha256(json.dumps(scope, sort_keys=True).encode()).hexdigest()
        if approval != {"gate": "H0-approved-test-targets-only", "scope_sha256": digest}:
            raise Refused("H0 attestation does not match transport scope")
        if config["mode"] == "testing-bot":
            if (not re.fullmatch(r"[0-9]+:[A-Za-z0-9_-]{30,100}", config["bot_token"])
                    or type(config["bot_id"]) is not int
                    or str(config["bot_id"]) != config["bot_token"].split(":")[0]
                    or config["bot_username"] != "LhiestoreTestingBot"
                    or config["sandbox_status_url"] or config["sandbox_token"]):
                raise Refused("only the attested Lhiestore Testing Bot is admitted")
        else:
            from boundary import public_https
            try:
                parsed = public_https(config["sandbox_status_url"])
                prefixes = {"klikqris.com": "/api/sandbox/", "sakurupiah.id": "/api-sanbox/"}
                if (parsed.hostname not in prefixes or not parsed.path.startswith(prefixes[parsed.hostname])
                        or not parsed.path.endswith("/status") or not config["sandbox_token"]
                        or any(part in (".", "..") for part in parsed.path.split("/"))
                        or "%" in parsed.path):
                    raise ValueError()
            except Exception:
                raise Refused("exact attested sandbox status endpoint required") from None
            if any(config[key] for key in ("bot_token", "bot_id", "bot_username")):
                raise Refused("sandbox mode rejects Telegram credentials")
    return config


def default(stack):
    return dict(version=1, stack=stack, mode="fake", dataset="synthetic-empty",
                db_url="postgresql://harness:harness@127.0.0.1:5432/disposable",
                redis_url="redis://127.0.0.1:6379/0", bot_token="", bot_id=0,
                bot_username="", safe_chat_ids=[1], sandbox_status_url="",
                sandbox_token="", approval=None)


def run(args, capture=False):
    # Never inherit app/provider credentials or Compose overrides from the operator shell.
    env = {"PATH": os.environ.get("PATH", "/usr/bin:/bin"), "HOME": os.environ["HOME"]}
    result = subprocess.run(args, cwd=STATE, env=env, text=True, stdout=subprocess.PIPE,
                            stderr=subprocess.PIPE)
    if result.returncode:
        raise Refused("local command failed; no command output retained (may contain secrets)")
    return result.stdout if capture else None


def docker_admission():
    if any(os.environ.get(k) for k in ("DOCKER_HOST", "DOCKER_CONTEXT", "DOCKER_TLS_VERIFY")):
        raise Refused("Docker endpoint overrides are not admitted")
    context = json.loads(run(["docker", "context", "inspect"], True))[0]
    endpoint = context["Endpoints"]["docker"]["Host"]
    if not endpoint.startswith("unix://"):
        raise Refused("only a local Unix Docker socket is admitted")


def compose(config, directory):
    stack = config["stack"]
    common = dict(cap_drop=["ALL"], security_opt=["no-new-privileges:true"],
                  restart="no", init=True, pids_limit=128, logging={"driver": "none"},
                  labels={"lhie.p01": "disposable", "lhie.p01.stack": stack})

    def service(cpu, mib, **kwargs):
        return copy.deepcopy(dict(common, cpus=cpu, mem_limit=f"{mib}m", memswap_limit=f"{mib}m", **kwargs))

    dependent = dict(network_mode="service:boundary", depends_on={"boundary": {"condition": "service_healthy"}})
    boundary = service(.1, 64, image=IMAGE, cap_add=["NET_ADMIN", "SETUID", "SETGID", "DAC_OVERRIDE"],
        read_only=True, tmpfs=["/tmp:size=8m"], networks=["isolated"],
        volumes=[f"{directory / 'config.json'}:/run/harness/config.json:ro"],
        ports=[f"127.0.0.1:{18801 if stack == 'laravel' else 18802}:8080"],
        healthcheck={"test": ["CMD", "test", "-f", "/tmp/harness-ready"],
                     "interval": "2s", "timeout": "3s", "retries": 30, "start_period": "10s"})
    db = service(.5, 640, image="postgres:16-alpine", user="70:70", **dependent,
        environment={"POSTGRES_USER": "harness", "POSTGRES_PASSWORD": "harness", "POSTGRES_DB": "disposable"},
        volumes=["database:/var/lib/postgresql/data"], tmpfs=["/var/run/postgresql:uid=70,gid=70,size=8m", "/tmp:size=8m"],
        command=["postgres", "-c", "listen_addresses=127.0.0.1", "-c", "max_connections=30", "-c", "shared_buffers=128MB"],
        healthcheck={"test": ["CMD", "pg_isready", "-U", "harness", "-d", "disposable"], "interval": "2s", "timeout": "3s", "retries": 30})
    redis = service(.15, 128, image="redis:7-alpine", user="999:999", **dependent,
        read_only=True, tmpfs=["/data:size=1m,uid=999,gid=999"],
        command=["redis-server", "--bind", "127.0.0.1", "--save", "", "--appendonly", "no",
                 "--maxmemory", "96mb", "--maxmemory-policy", "noeviction"],
        healthcheck={"test": ["CMD", "redis-cli", "ping"], "interval": "2s", "timeout": "3s", "retries": 15})
    app = service(1.25, 1216, image=IMAGE, **dependent, user="1000:1000", read_only=True,
        tmpfs=["/tmp:size=64m"], volumes=["storage:/disposable"])
    app["depends_on"].update({"database": {"condition": "service_healthy"}, "redis": {"condition": "service_healthy"}})
    if stack == "candidate":
        db["volumes"].append(
            f"{ROOT / 'candidate/postgres-init.sql'}:/docker-entrypoint-initdb.d/05-candidate.sql:ro")
        app.update(image="lhie-p05-candidate:1", working_dir="/opt/candidate",
                   entrypoint=["python", "serve.py"],
                   volumes=["storage:/disposable",
                            f"{directory / 'candidate.json'}:/run/harness/candidate.json:ro",
                            f"{directory / 'django-key'}:/run/harness/django-key:ro"])
        app["healthcheck"] = {
            "test": ["CMD", "python", "-c",
                     "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8080/ready', timeout=4).close()"],
            "interval": "3s", "timeout": "5s", "retries": 15}
    else:
        app.update(image="lhie-p01-laravel:1", working_dir="/var/www/html",
                   entrypoint=["/bin/sh", "/run/harness/comparator.sh"], read_only=False,
                   volumes=["storage:/var/www/html/storage", f"{TOOLS / 'comparator.sh'}:/run/harness/comparator.sh:ro"],
                   environment={"APP_ENV": "testing", "APP_DEBUG": "false", "APP_KEY": "base64:" + directory.joinpath("app-key").read_text().strip(),
                       "APP_URL": "http://127.0.0.1:18801", "DB_CONNECTION": "pgsql", "DB_HOST": "127.0.0.1", "DB_PORT": "5432",
                       "DB_DATABASE": "disposable", "DB_USERNAME": "harness", "DB_PASSWORD": "harness",
                       "REDIS_HOST": "127.0.0.1", "REDIS_PORT": "6379", "QUEUE_CONNECTION": "redis",
                       "CACHE_STORE": "redis", "SESSION_DRIVER": "redis", "LOG_CHANNEL": "null",
                       "MAIL_MAILER": "smtp", "MAIL_HOST": "127.0.0.1", "MAIL_PORT": "1025", "MAIL_USERNAME": "",
                       "MAIL_FROM_ADDRESS": "test@example.invalid", "TELEGRAM_ADMIN_CHAT_ID": str(config["safe_chat_ids"][0]),
                       "TELEGRAM_BOT_TOKEN": "", "APP_AUTO_MIGRATE": "false", "APP_AUTO_SET_TELEGRAM_WEBHOOK": "false"})
    return {"name": PREFIX + stack, "services": {"boundary": boundary, "database": db, "redis": redis, "app": app},
            "networks": {"isolated": {"internal": False, "enable_ipv6": False,
                "labels": {"lhie.p01": "disposable"}}},
            "volumes": {key: {"labels": {"lhie.p01": "disposable", "lhie.p01.stack": stack}}
                        for key in ("database", "storage")}}


def compose_cmd(directory, *args):
    return ["docker", "compose", "--env-file", "/dev/null", "-f", str(directory / "compose.json"), *args]


def tree_digest(directory):
    digest = hashlib.sha256()
    for path in sorted(directory.rglob("*")):
        if path.is_file():
            digest.update(str(path.relative_to(directory)).encode() + b"\0")
            digest.update(hashlib.sha256(path.read_bytes()).digest())
    return digest.hexdigest()


def local_comparator_build():
    """Offline setup option: current tracked source and verified installed dependencies."""
    vendor = ROOT / "src/vendor"
    lock = json.loads((ROOT / "src/composer.lock").read_text())
    installed = json.loads((vendor / "composer/installed.json").read_text())
    def packages(items):
        return {p["name"]: (p["version"], p.get("source", {}).get("reference"),
                            p.get("dist", {}).get("reference")) for p in items}
    required = packages(lock["packages"] + lock["packages-dev"])
    if required != packages(installed["packages"]):
        raise Refused("installed dependencies do not exactly match lock")
    for path in vendor.rglob("*"):
        if path.is_symlink() and not path.resolve().is_relative_to(vendor.resolve()):
            raise Refused("dependency symlink escapes vendor")
    base = json.loads(run(["docker", "image", "inspect", "telegram-bot:php-security-8.4.27"], True))[0]["Id"]
    base_tag = "lhie-p01-php-base:" + base.removeprefix("sha256:")
    run(["docker", "tag", base, base_tag])
    with tempfile.TemporaryDirectory(prefix="p01-source-") as temporary:
        context = Path(temporary)
        paths = run(["git", "-C", str(ROOT), "ls-files", "-z", "src"], True).split("\0")
        for name in filter(None, paths):
            source = ROOT / name
            if source.name.startswith(".env") or source.is_symlink():
                continue
            target = context / name
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(source, target)
        shutil.copytree(vendor, context / "src/vendor", symlinks=True)
        labels = {"lhie.p01.build-profile": "local-verified-dependencies",
                  "lhie.p01.source-tree-sha256": tree_digest(context / "src"),
                  "lhie.p01.vendor-tree-sha256": tree_digest(vendor),
                  "lhie.p01.composer-lock-sha256": hashlib.sha256((ROOT / "src/composer.lock").read_bytes()).hexdigest(),
                  "lhie.p01.php-base-id": base}
        (context / "Dockerfile").write_text("FROM " + base_tag + "\nCOPY --chown=1000:1000 src/ /var/www/html/\nLABEL "
            + " ".join(key + "=" + json.dumps(value) for key, value in labels.items()) + "\n")
        run(["docker", "build", "--network", "none", "-t", "lhie-p01-laravel:1", str(context)])


def owned_volumes(stack):
    names = [json.loads(line) for line in run(["docker", "volume", "ls", "--format", "json"], True).splitlines()]
    # Only known volume names can ever be removed; labels must match too.
    expected = {PREFIX + stack + "_" + key for key in ("database", "storage")}
    for volume in names:
        name = volume["Name"]
        if name.startswith(PREFIX + stack + "_"):
            if name not in expected:
                raise Refused("unexpected volume in disposable project")
            labels = json.loads(run(["docker", "volume", "inspect", name], True))[0].get("Labels") or {}
            if labels.get("lhie.p01") != "disposable" or labels.get("lhie.p01.stack") != stack:
                raise Refused("volume lacks disposable ownership proof")
    ids = run(["docker", "ps", "-aq", "--filter", "label=com.docker.compose.project=" + PREFIX + stack], True).split()
    for cid in ids:
        labels = json.loads(run(["docker", "inspect", cid], True))[0]["Config"]["Labels"]
        if labels.get("lhie.p01") != "disposable" or labels.get("lhie.p01.stack") != stack:
            raise Refused("container lacks disposable ownership proof")


def manifest(config, directory, command):
    ids = run(compose_cmd(directory, "ps", "-aq"), True).split()
    records = []
    for cid in ids:
        obj = json.loads(run(["docker", "inspect", cid], True))[0]
        host = obj["HostConfig"]
        image = json.loads(run(["docker", "image", "inspect", obj["Image"]], True))[0]
        records.append({"service": obj["Config"]["Labels"]["com.docker.compose.service"],
                        "image_id": obj["Image"], "memory": host["Memory"], "memory_swap": host["MemorySwap"],
                        "nano_cpus": host["NanoCpus"], "state": obj["State"]["Status"],
                        "oom_killed": obj["State"]["OOMKilled"], "restarts": obj["RestartCount"],
                        "build_identity": {k: v for k, v in (image["Config"].get("Labels") or {}).items()
                                           if k.startswith("lhie.p01.")},
                        "volumes": [{"name": m["Name"], "destination": m["Destination"]}
                                    for m in obj["Mounts"] if m["Type"] == "volume"],
                        "published_ports": host["PortBindings"],
                        "networks": list(obj["NetworkSettings"]["Networks"]),
                        "network_mode": host["NetworkMode"]})
    sources = {str(path.relative_to(ROOT)): hashlib.sha256(path.read_bytes()).hexdigest()
               for path in TOOLS.iterdir() if path.is_file()}
    if config["stack"] == "candidate":
        for path in (ROOT / "candidate").rglob("*"):
            relative = path.relative_to(ROOT / "candidate")
            if (path.is_file() and not path.is_symlink()
                    and not any(part.startswith(".") or part == "__pycache__" for part in relative.parts)):
                sources[str(path.relative_to(ROOT))] = hashlib.sha256(path.read_bytes()).hexdigest()
    versions = {}
    for item in records:
        service = item["service"]
        if item["state"] != "running":
            continue
        cid = next(cid for cid in ids if json.loads(run(["docker", "inspect", cid], True))[0]["Config"]["Labels"]["com.docker.compose.service"] == service)
        cmd = {"boundary": ["python", "--version"], "database": ["postgres", "--version"],
               "redis": ["redis-server", "--version"], "app": ["php", "--version"]
               if config["stack"] == "laravel" else ["python", "--version"]}[service]
        versions[service] = run(["docker", "exec", cid, *cmd], True).splitlines()[0]
        if service == "app" and config["stack"] == "candidate":
            versions["candidate_dependencies"] = json.loads(run(
                ["docker", "exec", cid, "python", "-c",
                 "import importlib.metadata as m,json; print(json.dumps({n:m.version(n) for n in "
                 "('Django','psycopg','psycopg-binary','asgiref','sqlparse')}))"], True))
    record = {"time_utc": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()), "command": command,
              "revision": run(["git", "-C", str(ROOT), "rev-parse", "HEAD"], True).strip(),
              "stack": config["stack"], "mode": config["mode"], "dataset": config["dataset"],
              "config_sha256": hashlib.sha256((directory / "config.json").read_bytes()).hexdigest(),
              "docker_version": run(["docker", "version", "--format", "{{.Server.Version}}"], True).strip(),
              "compose_version": run(["docker", "compose", "version", "--short"], True).strip(),
              "harness_source_sha256": sources, "runtime_versions": versions,
              "host_mem_total": next(line for line in Path("/proc/meminfo").read_text().splitlines() if line.startswith("MemTotal:")),
              "containers": records, "scheduler": "disabled", "workers": 0,
              "limit": "2 CPU / 2048 MiB, no swap; host OS/daemon headroom required",
              "application_parity": "unproven; candidate P05 bootstrap only"}
    path = directory / (str(time.time_ns()) + "-manifest.json")
    path.write_text(json.dumps(record, indent=2) + "\n")
    return path


def candidate_admission(config, directory):
    """Project only non-secret P01 facts into the application's smaller contract."""
    runtime = {
        "version": 1, "identity": "candidate", "dataset": config["dataset"],
        "quarantine": False,
        "database": {"host": "127.0.0.1", "port": 5432, "name": "disposable", "user": "candidate_runtime"},
        "redis": {"host": "127.0.0.1", "port": 6379, "db": 0},
        "limits": {"processes": 1, "threads": 1, "db_connections": 1},
    }
    (directory / "candidate.json").write_text(json.dumps(runtime, sort_keys=True) + "\n")
    (directory / "candidate.json").chmod(0o600)
    key = directory / "django-key"
    if not key.exists():
        with key.open("x") as handle:
            handle.write(secrets.token_hex(32))
    key.chmod(0o600)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("command", choices=["init", "validate", "build", "start", "stop", "reset", "manifest"])
    parser.add_argument("stack", choices=["laravel", "candidate"])
    parser.add_argument("--quarantine", action="store_true", help="start DB/Redis only; no application/producers")
    parser.add_argument("--local-dependencies", action="store_true", help="build Laravel with verified local vendor and installed PHP base, without downloads")
    args = parser.parse_args()
    os.umask(0o077)
    STATE.mkdir(parents=True, exist_ok=True)
    with (STATE / "lifecycle.lock").open("w") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        directory = STATE / args.stack
        if args.command == "init":
            directory.mkdir(exist_ok=True)
            path = directory / "config.json"
            if path.exists():
                raise Refused("configuration already exists")
            path.write_text(json.dumps(default(args.stack), indent=2) + "\n")
            (directory / "app-key").write_text(base64.b64encode(secrets.token_bytes(32)).decode())
            print("Created synthetic fake-mode configuration")
            return
        config = validate(json.loads((directory / "config.json").read_text()))
        if config["stack"] != args.stack:
            raise Refused("stack identity mismatch")
        if args.command == "validate":
            print("Admission passed (configuration only)")
            return
        docker_admission()
        spec = compose(config, directory)
        (directory / "compose.json").write_text(json.dumps(spec, indent=2))
        if args.command == "build":
            run(["docker", "build", "-t", IMAGE, str(TOOLS)])
            if args.stack == "candidate":
                run(["docker", "build", "-t", "lhie-p05-candidate:1", str(ROOT / "candidate")])
            if args.stack == "laravel":
                if args.local_dependencies:
                    local_comparator_build()
                else:
                    run(["docker", "build", "-f", str(ROOT / "Dockerfile"), "-t", "lhie-p01-laravel:1", str(ROOT)])
        elif args.command == "start":
            if args.quarantine and config["mode"] != "fake":
                raise Refused("quarantine requires fake mode")
            if (directory / "quarantine").exists() and not args.quarantine:
                raise Refused("quarantine is sticky until disposable reset; P02 owns sanitized release")
            active = run(["docker", "ps", "-q", "--filter", "label=lhie.p01=disposable"], True).split()
            if active:
                raise Refused("stop the existing P01 stack before starting either stack")
            owned_volumes(args.stack)
            if args.quarantine:
                (directory / "quarantine").touch()
            if args.stack == "candidate" and not args.quarantine:
                candidate_admission(config, directory)
            run(compose_cmd(directory, "up", "-d", "--wait", "--wait-timeout", "90",
                            *( ["boundary", "database", "redis"] if args.quarantine else [])))
            print(manifest(config, directory, "start-quarantine" if args.quarantine else "start"))
        elif args.command in ("stop", "reset"):
            owned_volumes(args.stack)
            run(compose_cmd(directory, "down", *( ["--volumes"] if args.command == "reset" else [])))
            if args.command == "reset":
                (directory / "quarantine").unlink(missing_ok=True)
            print("Stopped disposable stack" if args.command == "stop" else "Reset only owned disposable volumes; evidence/config retained")
        else:
            print(manifest(config, directory, "manifest"))


if __name__ == "__main__":
    try:
        main()
    except Refused as error:
        print("P01 refused: " + str(error), file=sys.stderr)
        sys.exit(1)
    except (ValueError, TypeError, KeyError, OSError):
        print("P01 refused: invalid admission or local operation; secret-bearing details suppressed", file=sys.stderr)
        sys.exit(1)
