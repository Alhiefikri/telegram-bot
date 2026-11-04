"""Manual bounded auth-mail recovery in the admitted P01 namespace."""
import json
import sys

from lhie_candidate.startup import configure_django, FAILURE


def main():
    if sys.argv[1:] != ["--once"]:
        raise ValueError("Use auth_mail.py --once")
    configure_django()
    from lhie_candidate.auth.mail import MailWork
    from lhie_candidate.compat.crypto import LaravelEncrypter
    from lhie_candidate.readiness import verify_dependencies
    from lhie_candidate.runtime import application_key
    verify_dependencies()
    key = application_key()
    count = MailWork(LaravelEncrypter(key), app_key=key).recover(limit=100)
    print(json.dumps({"sent": count, "batch_limit": 100}))


if __name__ == "__main__":
    try:
        main()
    except Exception:
        sys.stderr.write(FAILURE)
        raise SystemExit(1)
