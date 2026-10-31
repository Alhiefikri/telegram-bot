"""Process one bounded local navigation batch; never starts a persistent worker."""
import json
import sys


def main():
    from lhie_candidate.startup import configure_django
    configure_django()
    from lhie_candidate.readiness import verify_dependencies
    verify_dependencies()
    from lhie_candidate.navigation.processor import NavigationProcessor
    from lhie_candidate.navigation.repository import CatalogRepository
    from lhie_candidate.navigation.presentation import CatalogPresenter
    from lhie_candidate.navigation.roots import NavigationRoots
    from lhie_candidate.navigation.transport import RelayTransport
    transport = RelayTransport()
    repository = CatalogRepository()
    scope = "LhieStoreTestBot"
    banner = repository.banner_asset(scope) or {}
    roots = NavigationRoots(transport, bot_asset_scope=scope, banner_reference=banner.get("file_id"),
                            banner_version=banner.get("version"))
    presenter = CatalogPresenter(repository, bot_scope=scope, custom_emoji=True)
    outcomes = NavigationProcessor(repository, presenter, roots, transport).drain()
    print(json.dumps({"status": "processed", "outcomes": outcomes}))


if __name__ == "__main__":
    try:
        if sys.argv[1:] != ["--once"]:
            raise ValueError()
        main()
    except Exception:
        print('{"status":"navigation_refused"}')
        raise SystemExit(1) from None
