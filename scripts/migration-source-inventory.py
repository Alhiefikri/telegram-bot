#!/usr/bin/env python3
"""Offline, deterministic source evidence from an immutable Git revision; no app boot."""
import hashlib
import json
import re
import subprocess
import sys


def git(*args):
    return subprocess.check_output(["git", *args])


def main():
    revision = git("rev-parse", "--verify", sys.argv[1] + "^{commit}").decode().strip()
    paths = git("ls-tree", "-r", "--name-only", "-z", revision).decode().split("\0")
    records = []
    for path in sorted(filter(None, paths)):
        # Never include ENV, even example ENV; source metadata does not need it.
        if ".env" in path.split("/")[-1]:
            continue
        if not (path.startswith(("src/", "docs/", "scripts/", "docker/", "nginx/", ".github/"))
                or path in ("CONTEXT.md", "AGENTS.md", "README.md", "DESIGN.md", "PRODUCT.md",
                            "Dockerfile", "supervisord.conf", "install.sh", "release.json", ".dockerignore")
                or path.startswith("docker-")):
            continue
        content = git("show", revision + ":" + path)
        text = content.decode("utf-8", errors="replace")
        kinds = []
        for fragment, kind in (("/routes/", "entrypoints"), ("/Models/", "model"),
                               ("/migrations/", "schema-history"), ("/Jobs/", "job"),
                               ("/Commands/", "command"), ("/Services/", "owner"),
                               ("/Controllers/", "adapter"), ("/tests/", "prior-test"),
                               ("/resources/", "presentation"), ("/public/", "asset"),
                               ("/config/", "configuration"), ("/adr/", "decision")):
            if fragment in path:
                kinds.append(kind)
        anchors = []
        if path.endswith(".php") and ("/routes/" in path or "/Commands/" in path
                                    or "/Telegram/" in path):
            for number, line in enumerate(text.splitlines(), 1):
                if re.search(r"Route::|Schedule::|\$signature\s*=|function (handle|matches|supports|route)|case ['\"]|\$text\s*===|str_starts_with\(", line):
                    anchors.append({"line": number, "declaration": line.strip()})
        records.append({"path": path, "sha256": hashlib.sha256(content).hexdigest(),
                        "kinds": kinds or ["supporting-source"], "anchors": anchors})
    json.dump({"baseline": revision, "scope": "tracked source; excludes ENV; no runtime activation proof",
               "files": records}, sys.stdout, ensure_ascii=False, indent=2)
    print()


if __name__ == "__main__":
    main()
