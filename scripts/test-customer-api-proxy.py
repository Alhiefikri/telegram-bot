#!/usr/bin/env python3
"""Exercise Customer API proxy routing with an isolated local Nginx.

Usage: python scripts/test-customer-api-proxy.py /path/to/nginx [nginx-prefix]
The FastCGI handlers return 204 in this fixture; no application or production
service is contacted. Nginx must include its usual conf/fastcgi_params file.
"""

import http.client
import os
from pathlib import Path
import socket
import subprocess
import sys
import tempfile
import time


def main() -> None:
    if len(sys.argv) not in (2, 3):
        raise SystemExit(__doc__)
    nginx = Path(sys.argv[1]).resolve()
    prefix = Path(sys.argv[2]).resolve() if len(sys.argv) == 3 else nginx.parent
    params = prefix / "conf" / "fastcgi_params"
    if not params.is_file():
        raise SystemExit(f"Missing Nginx fastcgi_params: {params}")

    with tempfile.TemporaryDirectory(prefix="customer-api-nginx-") as directory:
        root = Path(directory)
        (root / "index.php").touch()
        access_log = root / "access.log"
        error_log = root / "error.log"
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            port = sock.getsockname()[1]

        source = (Path(__file__).resolve().parent.parent / "nginx" / "default.conf").read_text()
        substitutions = {
            "listen 80;": f"listen 127.0.0.1:{port};",
            "/var/www/html/public": root.as_posix(),
            "/var/log/nginx/access.log": access_log.as_posix(),
            "/var/log/nginx/error.log": error_log.as_posix(),
            "fastcgi_pass $php_upstream;": "return 204;",
            "include fastcgi_params;": f'include "{params.as_posix()}";',
        }
        if os.name == "nt":
            substitutions["/dev/null"] = "NUL"
        for old, new in substitutions.items():
            if old not in source:
                raise RuntimeError(f"Expected Nginx fixture input not found: {old}")
            source = source.replace(old, new)
        config = root / "nginx.conf"
        config.write_text(f"events {{}}\nhttp {{\n{source}\n}}\n")
        command = [str(nginx), "-p", prefix.as_posix() + "/", "-c", config.as_posix()]
        subprocess.run(command + ["-t"], check=True, capture_output=True, text=True)
        process = subprocess.Popen(command + ["-g", "daemon off;"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        try:
            deadline = time.monotonic() + 10
            while True:
                try:
                    with socket.create_connection(("127.0.0.1", port), timeout=1):
                        break
                except OSError:
                    if time.monotonic() >= deadline or process.poll() is not None:
                        raise RuntimeError("Nginx fixture did not start")
                    time.sleep(0.05)

            for path, expected in (
                ("/index.php/api/v1/balance?private=SYNTHETIC_SECRET", 404),
                ("/index.php%2Fapi%2Fv1%2Fbalance?private=SYNTHETIC_ENCODED", 404),
                ("/api/v1/balance", 204),
                ("/index.php", 204),
            ):
                connection = http.client.HTTPConnection("127.0.0.1", port, timeout=5)
                try:
                    connection.request("GET", path)
                    response = connection.getresponse()
                    response.read()
                    if response.status != expected:
                        raise AssertionError(f"{path.split('?')[0]}: expected {expected}, got {response.status}")
                    if expected == 404 and "no-store" not in response.getheader("Cache-Control", ""):
                        raise AssertionError("Alias response is missing no-store")
                finally:
                    connection.close()
        finally:
            subprocess.run(command + ["-s", "quit"], capture_output=True, text=True)
            process.wait(timeout=5)

        if access_log.exists() and "SYNTHETIC_" in access_log.read_text():
            raise AssertionError("A private alias query entered the Nginx access log")
        print("Customer API Nginx alias routing and logging: PASS")


if __name__ == "__main__":
    main()
