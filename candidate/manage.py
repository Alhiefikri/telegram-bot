from contextlib import redirect_stderr, redirect_stdout
import os
import sys

from lhie_candidate.startup import FAILURE, configure_django, suppress_payload_logging


COMMANDS = {("check",), ("check", "--deploy"), ("collectstatic", "--noinput")}


def main():
    suppress_payload_logging()
    try:
        if tuple(sys.argv[1:]) not in COMMANDS:
            raise ValueError()
        configure_django()
        from django.core.management import execute_from_command_line
        with open(os.devnull, "w") as discard, redirect_stdout(discard), redirect_stderr(discard):
            execute_from_command_line([sys.argv[0], *sys.argv[1:], "--verbosity", "0"])
    except (Exception, SystemExit):
        sys.stderr.write(FAILURE)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
