import importlib.util
from pathlib import Path
import sys
import unittest
from unittest.mock import patch

TOOLS = Path(__file__).resolve().parents[2] / 'tools/migration_harness'
sys.path.insert(0,str(TOOLS))
spec = importlib.util.spec_from_file_location('p06_interactive_runner',TOOLS / 'interactive_navigation.py')
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)


class InteractiveNavigationTests(unittest.TestCase):
    def test_only_exact_allowlisted_private_actor_can_reach_python(self):
        message = {'message_id':1,'chat':{'id':11,'type':'private'},'from':{'id':11},'text':'/start'}
        self.assertTrue(runner.admitted({'message':message},[11]))
        self.assertTrue(runner.admitted({'callback_query':{'from':{'id':11},'message':message}},[11]))
        for update in (
            {'message':message},
            {'message':{**message,'from':{'id':12}}},
            {'message':{**message,'chat':{'id':11,'type':'group'}}},
            {'callback_query':{'from':{'id':12},'message':message}},
            {'message':{}},
        ):
            allowed = [12] if update == {'message':message} else [11]
            with self.subTest(update=update):
                self.assertFalse(runner.admitted(update,allowed))

    def test_management_adapter_cannot_dispatch_messages_or_payment_operations(self):
        with patch.object(runner.urllib.request,'urlopen') as request:
            for method in ('sendMessage','sendPhoto','pinChatMessage','transferBusinessAccountStars'):
                with self.subTest(method=method),self.assertRaises(ValueError):
                    runner.telegram({'bot_token':'synthetic'},method,{})
        request.assert_not_called()
