#!/usr/bin/env python3
"""Explicit, time-bounded Testing Bot QA. Never starts from deployment or scheduler."""
import argparse
import json
from pathlib import Path
import signal
import subprocess
import sys
import time
import urllib.request

import harness
from smoke import container


def telegram(config, method, payload):
    if method not in {'getMe','getWebhookInfo','deleteWebhook','getUpdates','setWebhook'}:
        raise ValueError('Unsupported QA management operation')
    request = urllib.request.Request('https://api.telegram.org/bot' + config['bot_token'] + '/' + method,
        json.dumps(payload).encode(), {'Content-Type':'application/json'})
    with urllib.request.urlopen(request, timeout=25) as response:
        body = response.read(262145)
    if len(body) > 262144:
        raise ValueError('Oversized Telegram update response')
    result = json.loads(body)
    if result.get('ok') is not True:
        raise ValueError('Testing Bot management rejected')
    return result['result']


def admitted(update, safe_chats):
    source = update.get('callback_query', update.get('message', {}))
    if not isinstance(source, dict):
        return False
    message = source.get('message', source)
    chat = message.get('chat', {}) if isinstance(message, dict) else {}
    actor = source.get('from', {})
    return (chat.get('type') == 'private' and type(chat.get('id')) is int
            and chat['id'] in safe_chats and actor.get('id') == chat['id'])


def run(minutes, restore_path):
    config = harness.validate(json.loads((harness.STATE / 'candidate/config.json').read_text()))
    if config['mode'] != 'testing-bot' or config['approval']['gate'] != 'P06-approved-navigation-targets-only':
        raise ValueError('Explicit Testing Bot navigation approval required')
    if telegram(config,'getMe',{}).get('id') != config['bot_id']:
        raise ValueError('Testing Bot identity mismatch')
    restore = json.loads(restore_path.read_text())
    old = telegram(config,'getWebhookInfo',{})
    if old.get('has_custom_certificate') or old.get('url') != restore.get('url'):
        raise ValueError('Webhook restoration context mismatch')
    app = container('candidate','app')
    if not app:
        raise ValueError('Admitted candidate stack required')
    worker = subprocess.Popen(['docker','exec','-i',app,'python','-u','interactive_navigation.py',
        '--testing-bot-stdin'],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,text=True)
    switched = False
    try:
        if json.loads(worker.stdout.readline()).get('status') != 'ready':
            raise ValueError('Interactive candidate refused startup')
        switched = True
        telegram(config,'deleteWebhook',{'drop_pending_updates':False})
        print('PYTHON_NAVIGATION_READY', flush=True)
        offset = 0
        deadline = time.monotonic() + minutes * 60
        while time.monotonic() < deadline:
            updates = telegram(config,'getUpdates',{'offset':offset,'timeout':10,'limit':25,
                                                   'allowed_updates':['message','callback_query']})
            for update in updates:
                identity = update.get('update_id')
                if type(identity) is not int or identity < 1:
                    raise ValueError('Invalid update identity')
                if admitted(update,config['safe_chat_ids']):
                    encoded = json.dumps(update)
                    if len(encoded.encode()) < 16384:
                        worker.stdin.write(encoded + '\n'); worker.stdin.flush()
                        outcome = json.loads(worker.stdout.readline())
                        print(json.dumps(outcome),flush=True)
                offset = max(offset,identity + 1)
    finally:
        if worker.stdin:
            worker.stdin.close()
        try:
            worker.wait(timeout=10)
        except subprocess.TimeoutExpired:
            worker.terminate(); worker.wait(timeout=5)
        if switched:
            if restore['url']:
                payload = {'url':restore['url'],'drop_pending_updates':False,
                           'max_connections':restore.get('max_connections',40)}
                for key in ('secret_token','allowed_updates'):
                    if restore.get(key):
                        payload[key] = restore[key]
                telegram(config,'setWebhook',payload)
            print('TESTING_BOT_ROUTING_RESTORED',flush=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--approved-testing-bot-polling',action='store_true',required=True)
    parser.add_argument('--minutes',type=int,default=30)
    parser.add_argument('--restore-context',type=Path,required=True)
    args = parser.parse_args()
    if not 1 <= args.minutes <= 60 or not args.restore_context.resolve().is_relative_to(harness.STATE.resolve()):
        raise ValueError('Private bounded QA context required')
    signal.signal(signal.SIGTERM,lambda *_: (_ for _ in ()).throw(KeyboardInterrupt()))
    run(args.minutes,args.restore_context)


if __name__ == '__main__':
    try:
        main()
    except KeyboardInterrupt:
        pass
    except Exception:
        print('Interactive Testing Bot QA stopped; inspect routing restoration status.',flush=True)
        raise SystemExit(1) from None
