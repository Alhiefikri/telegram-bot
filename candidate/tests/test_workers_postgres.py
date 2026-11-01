"""Disposable P10 worker proof; invoked by smoke_workers.py only.

The mixed-load phase runs the real supervisor (HTTP plus one process per queue)
against a fake Telegram relay and a fake, deliberately slow Sakurupiah sandbox.
The recovery phase runs with Redis stopped and proves PostgreSQL discovery,
expired-lease fencing and no resend of an unknown delivery.
"""

from datetime import datetime
import hashlib
import hmac
import json
import multiprocessing
from pathlib import Path
import random
import socket
import subprocess
import sys
import threading
import time
import urllib.request
import uuid
from zoneinfo import ZoneInfo


ROOT = Path(__file__).resolve().parents[1]
EVIDENCE = Path("/evidence")


def _admin():
    from tests.test_checkout_postgres import admin_connection
    return admin_connection()


def _closed_connections():
    from django.db import connections
    connections.close_all()


def _now():
    return datetime.now(ZoneInfo("Asia/Jakarta")).replace(tzinfo=None)


def _checkout(fixture, key, *, sakurupiah=False):
    from lhie_candidate.checkout.service import CheckoutRequest, CheckoutService
    if sakurupiah:
        from lhie_candidate.checkout.sakurupiah import SakurupiahPreparation
        from lhie_candidate.payments.relay import SandboxRelay
        from lhie_candidate.payments.sakurupiah import SakurupiahSandboxAdapter
        service = CheckoutService(preparation=SakurupiahPreparation(
            SakurupiahSandboxAdapter(transport=SandboxRelay())))
    else:
        service = CheckoutService()
    request = CheckoutRequest(fixture["customer_id"], fixture["cart_id"], key,
                              service.quote_cart(fixture["customer_id"], fixture["cart_id"]))
    result = service.checkout(request)
    assert not result.replayed
    return result


def _lifecycle():
    from lhie_candidate.compat.crypto import LaravelEncrypter
    from lhie_candidate.payments.lifecycle import SakurupiahLifecycle
    from lhie_candidate.payments.relay import SandboxRelay
    from lhie_candidate.payments.sakurupiah import SakurupiahSandboxAdapter
    from lhie_candidate.runtime import application_key
    return SakurupiahLifecycle(SakurupiahSandboxAdapter(transport=SandboxRelay()),
                               LaravelEncrypter(application_key()))


def _webhook(update_id, chat_id, text):
    from django.conf import settings
    secret = hmac.new(settings.SECRET_KEY.encode(), b"candidate-navigation-webhook-v1",
                      hashlib.sha256).hexdigest()
    payload = {"update_id": update_id, "message": {
        "message_id": update_id % 1000000 + 1,
        "date": int(time.time()),
        "chat": {"id": chat_id, "type": "private"},
        "from": {"id": chat_id, "is_bot": False, "first_name": "Synthetic P10"},
        "text": text,
    }}
    request = urllib.request.Request(
        "http://127.0.0.1:8080/webhook/telegram",
        data=json.dumps(payload).encode(),
        headers={"Content-Type": "application/json",
                 "X-Telegram-Bot-Api-Secret-Token": secret},
        method="POST",
    )
    with urllib.request.urlopen(request, timeout=5) as response:
        assert response.status == 200
        return json.loads(response.read())


def _pending_update(update_id, chat_id, text):
    payload = {"update_id": update_id, "message": {
        "message_id": update_id % 1000000 + 1,
        "chat": {"id": chat_id, "type": "private"},
        "from": {"id": chat_id, "is_bot": False, "first_name": "Synthetic P10"},
        "text": text,
    }}
    return payload


def _wait_http(timeout=30):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        try:
            with urllib.request.urlopen("http://127.0.0.1:8080/ready", timeout=2) as response:
                if response.status == 200:
                    return
        except Exception:
            time.sleep(0.1)
    raise RuntimeError("HTTP runtime did not become ready")


def _wait_for(admin, label, sql, params=(), timeout=30):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        row = admin.execute(sql, params).fetchone()
        if row is not None and row[0]:
            return time.monotonic()
        time.sleep(0.02)
    raise AssertionError("Durable work did not finish: " + label)


def _redis_length(queue):
    with socket.create_connection(("127.0.0.1", 6379), timeout=1) as connection:
        connection.sendall(("*2\r\n$4\r\nLLEN\r\n$" + str(len(queue)) + "\r\n" + queue + "\r\n").encode())
        reply = connection.recv(64)
        if not reply.startswith(b":"):
            raise ValueError("Invalid Redis reply")
        return int(reply[1:-2])


class _CgroupSampler:
    """Sample the container cgroup; never claim it is a sum of process RSS."""

    def __init__(self):
        self.peak_memory = 0
        self.peak_pids = 0
        self.cpu_start = None
        self.started = time.monotonic()
        self._stop = threading.Event()
        self._thread = threading.Thread(target=self._run, daemon=True)

    def _usage(self):
        total = 0
        try:
            for line in Path("/sys/fs/cgroup/cpu.stat").read_text().splitlines():
                if line.startswith("usage_usec "):
                    total = int(line.split()[1])
        except (OSError, ValueError, IndexError):
            return None
        return total

    def start(self):
        self.cpu_start = self._usage()
        self.started = time.monotonic()
        self._thread.start()

    def _run(self):
        while not self._stop.is_set():
            try:
                self.peak_memory = max(self.peak_memory, int(Path("/sys/fs/cgroup/memory.current").read_text()))
                self.peak_pids = max(self.peak_pids, int(Path("/sys/fs/cgroup/pids.current").read_text()))
            except (OSError, ValueError):
                pass
            time.sleep(0.02)

    def stop(self):
        self._stop.set()
        self._thread.join(timeout=5)
        elapsed = max(time.monotonic() - self.started, 0.001)
        finished = self._usage()
        cpu_seconds = (None if self.cpu_start is None or finished is None
                       else round((finished - self.cpu_start) / 1_000_000, 3))
        return {
            "peak_memory_current_bytes": self.peak_memory or None,
            "peak_pids": self.peak_pids or None,
            "cpu_usage_seconds": cpu_seconds,
            "observed_seconds": round(elapsed, 3),
            "average_cpus": round(cpu_seconds / elapsed, 3) if cpu_seconds is not None else None,
        }


class _ConnectionSampler:
    def __init__(self):
        self.maximum = 0
        self._stop = threading.Event()
        self._thread = threading.Thread(target=self._run, daemon=True)

    def start(self):
        self._thread.start()

    def _run(self):
        connection = _admin()
        try:
            while not self._stop.is_set():
                count = connection.execute(
                    "SELECT count(*) FROM pg_stat_activity WHERE usename='candidate_runtime'").fetchone()[0]
                self.maximum = max(self.maximum, count)
                time.sleep(0.02)
        finally:
            connection.close()

    def stop(self):
        self._stop.set()
        self._thread.join(timeout=5)
        return self.maximum


def _cgroup():
    values = {}
    for name in ("memory.current", "memory.max", "memory.swap.current", "pids.current"):
        try:
            values[name] = int(Path("/sys/fs/cgroup", name).read_text().strip())
        except (OSError, ValueError):
            values[name] = None
    try:
        values["cpu.max"] = Path("/sys/fs/cgroup/cpu.max").read_text().strip()
    except OSError:
        values["cpu.max"] = None
    try:
        values["processes"] = len([_ for _ in Path("/proc").iterdir() if _.name.isdigit()])
    except OSError:
        values["processes"] = None
    return values


def _metrics(directory, prefix=""):
    values = {}
    for queue in ("interaction", "payment", "receipt"):
        path = Path(directory) / f"{prefix}{queue}.json"
        if path.exists():
            values[queue] = json.loads(path.read_text())
    return values


def mixed_load(navigation, checkout):
    from lhie_candidate.startup import configure_django
    configure_django()
    from lhie_candidate.workers import wakeup

    admin = _admin()
    nav_chat = admin.execute("SELECT telegram_id FROM public.customers WHERE id=%s",
                             (navigation["customer_id"],)).fetchone()[0]
    stack = subprocess.Popen([sys.executable, str(ROOT / "stack.py"), "--metrics-dir", str(EVIDENCE)],
                             cwd=ROOT, stdin=subprocess.DEVNULL,
                             stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, close_fds=True)
    sampler = _ConnectionSampler()
    cgroups = _CgroupSampler()
    blocker = _admin()
    observations = {}
    try:
        _wait_http()
        admin.execute("UPDATE public.manual_payment_settings SET payment_mode='sakurupiah',"
                      "sakurupiah_channel='QRIS'")
        payment_order = _checkout(checkout["release"], "p10-mixed-payment", sakurupiah=True)
        suffix = format(random.getrandbits(40), "010x")
        merchant_ref = "p10-slow-" + suffix
        admin.execute("UPDATE public.payments SET merchant_ref=%s WHERE id=%s",
                      (merchant_ref, payment_order.payment_id))
        created = _lifecycle().create(payment_order.payment_id, phone="08000000000",
                                      callback_url="https://synthetic.invalid/callback",
                                      return_url="https://synthetic.invalid/return")
        assert created.state == "created" and created.presentation is not None
        provider_reference = admin.execute(
            "SELECT provider_reference FROM public.payments WHERE id=%s",
            (payment_order.payment_id,)).fetchone()[0]
        assert provider_reference

        # The slow payment starts before the required navigation and receipt work.
        started = time.monotonic()
        event_id = admin.execute(
            """INSERT INTO public.payment_events
               (provider,event_type,merchant_ref,provider_reference,provider_status,raw_payload,
                idempotency_key,received_at,last_received_at,received_count)
               VALUES ('sakurupiah','callback',%s,%s,'berhasil',%s::jsonb,%s,now(),now(),1)
               RETURNING id""",
            (merchant_ref, provider_reference, json.dumps({"synthetic": True}), "p10-hint-" + suffix),
        ).fetchone()[0]
        assert wakeup.enqueue("payment.event", event_id)

        receipt_order = _checkout(checkout["duplicate"], "p10-mixed-receipt")
        receipt_oid = str(receipt_order.order_id)
        assert _webhook(910000, nav_chat, "/start") == {"ok": True}
        backlog = [_pending_update(910001 + index, nav_chat, "/start")
                   for index in range(8)]
        for payload in backlog:
            admin.execute(
                "INSERT INTO django_candidate.navigation_updates (update_id,chat_id,payload) "
                "VALUES (%s,%s,%s::jsonb) ON CONFLICT (update_id) DO NOTHING",
                (payload["update_id"], payload["message"]["chat"]["id"], json.dumps(payload)),
            )
        notification_key = "p10-notification:" + suffix
        notification_id = str(uuid.uuid4())
        admin.execute(
            """INSERT INTO public.telegram_outbound_effects
               (id,effect_key,effect_group_key,sequence,effect_type,source_type,source_id,target_type,
                telegram_chat_id,queue,telegram_method,locale,template_version,state,attempts,
                next_attempt_at,outcome_metadata,created_at,updated_at)
               VALUES (%s,%s,%s,1,'notification.admin','App\\Models\\Order',%s,'customer',%s,
                       'notifications','sendMessage','en','admin-notice-v1','pending',0,now(),'{}'::json,
                       %s,%s)""",
            (notification_id, notification_key, notification_key, receipt_oid,
             "1", _now(), _now()),
        )
        blocker.autocommit = False
        blocker.execute("SELECT id FROM public.telegram_outbound_effects WHERE id=%s FOR UPDATE",
                        (notification_id,))

        _closed_connections()
        sampler.start()
        cgroups.start()
        try:
            observations["navigation"] = _wait_for(
                admin, "navigation",
                "SELECT state='processed' FROM django_candidate.navigation_updates WHERE update_id=910000")
            observations["backlog"] = _wait_for(
                admin, "navigation backlog",
                "SELECT count(*)=0 FROM django_candidate.navigation_updates "
                "WHERE state IN ('pending','processing')")
            observations["receipt"] = _wait_for(
                admin, "receipt_order projection",
                "SELECT telegram_receipt_sent_at IS NOT NULL FROM public.orders WHERE id=%s",
                (receipt_oid,))
            observations["payment"] = _wait_for(
                admin, "slow payment",
                "SELECT status='paid' FROM public.payments WHERE id=%s",
                (payment_order.payment_id,))
            observations["payment_receipt"] = _wait_for(
                admin, "payment receipt projection",
                "SELECT telegram_receipt_sent_at IS NOT NULL FROM public.orders WHERE id=%s",
                (str(payment_order.order_id),))
        except AssertionError as error:
            diagnostic = {
                "receipt_order": admin.execute(
                    "SELECT order_status,payment_status,telegram_receipt_sent_at FROM public.orders WHERE id=%s",
                    (receipt_oid,)).fetchone(),
                "receipt_effects": admin.execute(
                    "SELECT sequence,state,attempts,last_error_code FROM public.telegram_outbound_effects "
                    "WHERE source_id=%s ORDER BY sequence", (receipt_oid,)).fetchall(),
                "payment": admin.execute(
                    "SELECT status,provider_create_state,provider_reference,provider_total_payment "
                    "FROM public.payments WHERE id=%s", (payment_order.payment_id,)).fetchone(),
                "payment_order": admin.execute(
                    "SELECT order_status,payment_status,telegram_receipt_sent_at FROM public.orders WHERE id=%s",
                    (str(payment_order.order_id),)).fetchone(),
                "payment_effects": admin.execute(
                    "SELECT sequence,state,attempts,last_error_code FROM public.telegram_outbound_effects "
                    "WHERE source_id=%s ORDER BY sequence", (str(payment_order.order_id),)).fetchall(),
                "navigation": admin.execute(
                    "SELECT update_id,state,outcome FROM django_candidate.navigation_updates "
                    "WHERE update_id BETWEEN 910000 AND 910008 ORDER BY update_id").fetchall(),
            }
            raise AssertionError(str(error) + " | " + json.dumps(diagnostic, default=str)) from None
        elapsed = {name: round(moment - started, 3) for name, moment in observations.items()}
        assert observations["navigation"] < observations["payment"], elapsed
        assert observations["receipt"] < observations["payment"], elapsed
        assert observations["backlog"] < observations["payment"], elapsed
        assert elapsed["payment"] >= 2.5, elapsed
        assert admin.execute("SELECT state FROM public.telegram_outbound_effects WHERE id=%s",
                             (notification_id,)).fetchone() == ("pending",)

        for queue in ("candidate:interaction", "candidate:payments", "candidate:telegram-receipts"):
            deadline = time.monotonic() + 10
            while time.monotonic() < deadline and _redis_length(queue):
                time.sleep(0.05)
        depths = {queue: _redis_length(queue) for queue in
                  ("candidate:interaction", "candidate:payments", "candidate:telegram-receipts")}
        assert all(depth == 0 for depth in depths.values()), depths
        maximum_connections = sampler.stop()
        assert maximum_connections <= 4, maximum_connections
        cgroup_peak = cgroups.stop()
        stack.terminate()
        assert stack.wait(timeout=90) == 0
        metrics = _metrics(EVIDENCE)
        assert max(metrics["payment"]["durations"][name]["max_ms"]
                   for name in ("handle", "sweep")) >= 2500
        assert metrics["interaction"]["counters"]["finished"] >= 1
        assert metrics["receipt"]["counters"]["finished"] >= 1
        assert not any(value["counters"]["timeout"] for value in metrics.values())
        assert metrics["receipt"]["counters"]["sweep"] >= 1
        return {
            "navigation_state": "processed",
            "backlog_drained": True,
            "receipt_completed": True,
            "slow_payment_seconds": round(elapsed["payment"], 3),
            "observations_seconds": elapsed,
            "maximum_runtime_connections": maximum_connections,
            "redis_depths": depths,
            "worker_metrics": metrics,
            "cgroup": _cgroup(),
            "cgroup_peak": cgroup_peak,
            "notification_effect_isolated": True,
            "stack_exit": 0,
        }
    finally:
        for item in (sampler, cgroups):
            try:
                if item._thread.is_alive():
                    item.stop()
            except Exception:
                pass
        try:
            blocker.rollback()
        except Exception:
            pass
        blocker.close()
        if stack.poll() is None:
            stack.kill()
            stack.wait()
        _closed_connections()
        admin.close()


def _slow_receipt_child(effect_id, marker):
    from lhie_candidate.startup import configure_django
    configure_django()
    from lhie_candidate.receipt.runtime import receipt_service
    from lhie_candidate.receipt.transport import RelayTransport

    class SendThenSleep:
        def __init__(self):
            self.relay = RelayTransport()

        def execute(self, method, payload):
            result = self.relay.execute(method, payload)
            with open(marker, "a") as stream:
                stream.write(result.outcome + "\n")
                stream.flush()
            time.sleep(30)
            return result

    service = receipt_service(lease_seconds=60)
    service.transport = SendThenSleep()
    service.deliver(effect_id)
    _closed_connections()


def recovery(navigation, checkout):
    from lhie_candidate.startup import configure_django
    configure_django()
    from lhie_candidate.receipt.runtime import receipt_service
    from lhie_candidate.workers import wakeup

    admin = _admin()
    nav_chat = admin.execute("SELECT telegram_id FROM public.customers WHERE id=%s",
                             (navigation["customer_id"],)).fetchone()[0]
    marker = EVIDENCE / "abandoned-sent.txt"
    marker.unlink(missing_ok=True)
    try:
        admin.execute("UPDATE public.manual_payment_settings SET payment_mode='sakurupiah',"
                      "sakurupiah_channel='QRIS'")
        try:
            wakeup.take("candidate:interaction", timeout=1)
            raise AssertionError("Recovery drill requires Redis to be stopped")
        except OSError:
            pass
        lost = _checkout(checkout["pause"], "p10-recovery-lost")
        abandoned = _checkout(checkout["maintenance"], "p10-recovery-abandoned")
        service = receipt_service(lease_seconds=60)
        effect = service.materialize(str(abandoned.order_id))
        assert effect is not None
        _closed_connections()

        context = multiprocessing.get_context("spawn")
        child = context.Process(target=_slow_receipt_child,
                                args=(str(effect["id"]), str(marker)))
        child.start()
        deadline = time.monotonic() + 20
        while time.monotonic() < deadline and not (marker.exists() and marker.read_text().strip()):
            time.sleep(0.05)
        assert marker.exists() and marker.read_text().strip(), "worker never sent before the kill"
        child.kill()
        child.join(timeout=10)
        assert child.exitcode != 0
        time.sleep(62)

        pending = _pending_update(920000, nav_chat, "/start")
        admin.execute(
            "INSERT INTO django_candidate.navigation_updates (update_id,chat_id,payload) "
            "VALUES (%s,%s,%s::jsonb) ON CONFLICT (update_id) DO NOTHING",
            (pending["update_id"], pending["message"]["chat"]["id"], json.dumps(pending)),
        )
        payment_order = _checkout(checkout["retry_success"], "p10-recovery-payment", sakurupiah=True)
        suffix = format(random.getrandbits(40), "010x")
        admin.execute("UPDATE public.payments SET merchant_ref=%s WHERE id=%s",
                      ("p10-recovery-" + suffix, payment_order.payment_id))
        created = _lifecycle().create(payment_order.payment_id, phone="08000000000",
                                      callback_url="https://synthetic.invalid/callback",
                                      return_url="https://synthetic.invalid/return")
        assert created.state == "created"
        provider_reference = admin.execute(
            "SELECT provider_reference FROM public.payments WHERE id=%s",
            (payment_order.payment_id,)).fetchone()[0]
        admin.execute(
            """INSERT INTO public.payment_events
               (provider,event_type,merchant_ref,provider_reference,provider_status,raw_payload,
                idempotency_key,received_at,last_received_at,received_count)
               VALUES ('sakurupiah','callback',%s,%s,'berhasil',%s::jsonb,%s,now(),now(),1)""",
            ("p10-recovery-" + suffix, provider_reference, json.dumps({"synthetic": True}),
             "p10-recovery-hint-" + suffix),
        )
        before_balance = admin.execute(
            "SELECT count(*) FROM public.store_balance_entries WHERE source_type='order' AND source_id=%s",
            (str(lost.order_id),)).fetchone()[0]
        before_order = admin.execute(
            "SELECT order_status,payment_status FROM public.orders WHERE id=%s",
            (str(lost.order_id),)).fetchone()
        _closed_connections()

        for queue, depth in (("interaction", 2), ("receipt", 4), ("payment", 3)):
            result = subprocess.run(
                [sys.executable, str(ROOT / "workers.py"), "--queue", queue,
                 "--iterations", str(depth), "--metrics", str(EVIDENCE / f"recovery-{queue}.json")],
                cwd=ROOT, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL, timeout=120)
            assert result.returncode == 0, (queue, result.returncode)

        abandoned_state = admin.execute(
            "SELECT state,attempts FROM public.telegram_outbound_effects WHERE id=%s",
            (str(effect["id"]),)).fetchone()
        sent_lines = marker.read_text().splitlines()
        assert abandoned_state == ("unknown", 1), abandoned_state
        assert len(sent_lines) == 1, sent_lines
        assert admin.execute("SELECT state FROM django_candidate.navigation_updates WHERE update_id=920000",
                             ).fetchone() == ("processed",)
        assert admin.execute("SELECT status='paid' FROM public.payments WHERE id=%s",
                             (payment_order.payment_id,)).fetchone() == (True,)
        assert admin.execute("SELECT telegram_receipt_sent_at IS NOT NULL FROM public.orders WHERE id=%s",
                             (str(lost.order_id),)).fetchone() == (True,)
        assert admin.execute(
            "SELECT count(*) FROM public.store_balance_entries WHERE source_type='order' AND source_id=%s",
            (str(lost.order_id),)).fetchone()[0] == before_balance
        assert admin.execute("SELECT order_status,payment_status FROM public.orders WHERE id=%s",
                             (str(lost.order_id),)).fetchone() == before_order
        metrics = _metrics(EVIDENCE, prefix="recovery-")
        return {
            "lost_wakeup_recovered": True,
            "interaction_recovered_without_redis": True,
            "payment_recovered_without_redis": True,
            "abandoned_effect": {"state": abandoned_state[0], "attempts": abandoned_state[1],
                                 "sends": len(sent_lines)},
            "no_duplicate_money": True,
            "metrics": metrics,
        }
    finally:
        _closed_connections()
        admin.close()


if __name__ == "__main__":
    raise SystemExit("Invoked by smoke_workers.py only")
