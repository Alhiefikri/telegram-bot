"""Manual Testing Bot QA: one persistent consumer fed admitted JSON updates on stdin."""
import json
import sys


def main():
    if sys.argv[1:] != ['--testing-bot-stdin']:
        raise ValueError('Explicit interactive QA mode required')
    from lhie_candidate.startup import configure_django
    configure_django()
    from lhie_candidate.readiness import verify_dependencies
    verify_dependencies()
    from lhie_candidate.navigation.ingress import UpdateInbox, InvalidUpdate
    from lhie_candidate.navigation.repository import CatalogRepository
    from lhie_candidate.navigation.presentation import CatalogPresenter
    from lhie_candidate.navigation.processor import NavigationProcessor
    from lhie_candidate.navigation.roots import NavigationRoots
    from lhie_candidate.navigation.transport import RelayTransport
    repository = CatalogRepository()
    transport = RelayTransport()
    scope = 'LhieStoreTestBot'
    banner = repository.banner_asset(scope) or {}
    roots = NavigationRoots(transport, bot_asset_scope=scope,
                            banner_reference=banner.get('file_id'), banner_version=banner.get('version'))
    processor = NavigationProcessor(repository, CatalogPresenter(repository, bot_scope=scope, custom_emoji=True), roots, transport)
    inbox = UpdateInbox()
    print(json.dumps({'status':'ready'}), flush=True)
    while True:
        line = sys.stdin.buffer.readline(16386)
        if not line:
            return
        if len(line) > 16384 or not line.endswith(b'\n'):
            raise ValueError('Bounded update required')
        try:
            payload = json.loads(line)
            admitted = inbox.receive(payload)
        except (InvalidUpdate, ValueError, UnicodeError):
            print(json.dumps({'status':'rejected'}), flush=True)
            continue
        callback = payload.get('callback_query')
        if callback:
            transport.execute('answerCallbackQuery', {
                'chat_id':callback['message']['chat']['id'], 'callback_query_id':callback['id']})
        outcome = processor.process(admitted.update_id)
        print(json.dumps({'status':'processed','outcome':outcome}), flush=True)


if __name__ == '__main__':
    try:
        main()
    except Exception:
        print('{"status":"interactive_navigation_refused"}', flush=True)
        raise SystemExit(1) from None
