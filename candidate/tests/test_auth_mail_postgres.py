"""Synthetic PostgreSQL auth-mail MIME parity and OTP binding regressions."""


def exercise_auth_mail_parity(service, admin, cipher, php_oracles):
    from email import policy
    from email.parser import BytesParser
    from html import escape
    from urllib.parse import urlsplit
    from unittest.mock import patch
    from uuid import uuid4

    from django.test import override_settings

    from lhie_candidate.auth.mail import MailWork
    from lhie_candidate.auth.types import Browser

    source_url = urlsplit(php_oracles["email_verification"]["action_url"])
    origin = f"{source_url.scheme}://{source_url.netloc}"
    marker = uuid4().hex
    browser = Browser("7" * 64, "127.0.9.7")
    verification_email = f"auth-mail-verification-{marker}@example.invalid"
    account_email = f"auth-mail-account-{marker}@example.invalid"
    magic_email = f"auth-mail-magic-{marker}@example.invalid"
    password = "Synthetic-Mail42!"

    def work_for(email, purpose):
        row = admin.execute(
            """SELECT w.id,w.payload FROM public.customer_auth_mail_works w
            JOIN public.customer_auth_tokens t ON t.id=w.auth_token_id
            WHERE t.email=%s AND t.purpose=%s ORDER BY w.id DESC LIMIT 1""",
            (email, purpose),
        ).fetchone()
        assert row is not None, (email, purpose)
        payload = cipher.decrypt_json(row[1])
        assert payload["email"] == email and payload["token"] not in row[1]
        return row[0], payload["token"]

    # This fixture compares multiple mail purposes, not repeat-send pacing.
    with patch.object(service.limits, "mail", return_value=()):
        service.register(verification_email, password, "Synthetic", browser)
        verification_id, verification_token = work_for(verification_email, "email_verification")
        service.register(account_email, password, "Synthetic", browser)
        _, account_verification_token = work_for(account_email, "email_verification")
        service.verify(account_verification_token, browser)
        service.request_otp(account_email, browser)
        otp_id, otp = work_for(account_email, "email_otp")
        service.request_reset(account_email, browser)
        reset_id, reset = work_for(account_email, "password_reset")
        service.request_magic(magic_email, browser)
        magic_row = admin.execute(
            """SELECT w.id,w.payload FROM public.customer_auth_mail_works w
            JOIN public.customer_login_links l ON l.id=w.login_link_id
            WHERE l.email=%s ORDER BY w.id DESC LIMIT 1""",
            (magic_email,),
        ).fetchone()
        assert magic_row is not None
        magic_payload = cipher.decrypt_json(magic_row[1])
        magic_id, magic = magic_row[0], magic_payload["token"]
        assert magic_payload["email"] == magic_email and magic not in magic_row[1]

    class FakeSMTP:
        messages = []

        def __init__(self, host, port, timeout):
            assert (host, port, timeout) == ("127.0.0.1", 1025, 5)

        def __enter__(self):
            return self

        def __exit__(self, *_):
            return False

        def send_message(self, message):
            self.messages.append(BytesParser(policy=policy.default).parsebytes(message.as_bytes()))
            return {}

    deliveries = [
        (verification_id, verification_email, verification_token, "email_verification"),
        (otp_id, account_email, otp, "email_otp"),
        (reset_id, account_email, reset, "password_reset"),
        (magic_id, magic_email, magic, "magic"),
    ]
    with override_settings(CANDIDATE_LEGACY_APP_URL=origin), patch(
        "lhie_candidate.auth.mail.smtplib.SMTP", FakeSMTP
    ):
        mailer = MailWork(cipher, app_key=service.app_key)
        for work_id, recipient, capability, purpose in deliveries:
            assert mailer.deliver(work_id)
            if purpose == "email_verification":
                service.verify(capability, browser)
            message = FakeSMTP.messages[-1]
            oracle = php_oracles[purpose]
            assert message["To"] == recipient
            assert message["Subject"] == oracle["subject"], (
                purpose, str(message["Subject"]), oracle["subject"]
            )
            text_part = message.get_body(preferencelist=("plain",))
            html_part = message.get_body(preferencelist=("html",))
            assert text_part is not None and html_part is not None
            text, rendered_html = text_part.get_content().strip(), html_part.get_content().strip()

            greeting = oracle["greeting"]
            intro = list(oracle["intro_lines"])
            if purpose == "email_otp":
                token_lines = [index for index, line in enumerate(intro) if line.isdecimal()]
                assert len(token_lines) == 1
                intro[token_lines[0]] = capability
            action_text = oracle["action_text"]
            action_url = oracle["action_url"]
            if action_url is not None:
                source_prefix, source_token = action_url.rsplit("#token=", 1)
                assert len(source_token) == 64 and all(char in "0123456789abcdef" for char in source_token)
                action_url = source_prefix + "#token=" + capability
            lines = [greeting, *intro]
            if action_text is not None:
                lines.append(action_text + ": " + action_url)
            lines.extend(oracle["outro_lines"])
            assert text == "\n\n".join(lines)

            html_parts = ["<!doctype html><html><body><h1>" + escape(greeting) + "</h1>"]
            html_parts.extend("<p>" + escape(line) + "</p>" for line in intro)
            if action_text is not None:
                html_parts.append(
                    '<p><a href="' + escape(action_url, quote=True) + '">' + escape(action_text) + "</a></p>"
                )
            html_parts.extend("<p>" + escape(line) + "</p>" for line in oracle["outro_lines"])
            expected_html = "".join(html_parts) + "</body></html>"
            assert rendered_html == expected_html
            assert admin.execute(
                "SELECT status,payload,last_error_code FROM public.customer_auth_mail_works WHERE id=%s",
                (work_id,),
            ).fetchone() == ("sent", None, None)
            assert capability not in str(admin.execute(
                "SELECT status,payload,last_error_code FROM public.customer_auth_mail_works WHERE id=%s",
                (work_id,),
            ).fetchone())
            assert admin.execute(
                "SELECT count(*) FROM public.audit_logs WHERE new_values::text LIKE %s",
                ("%" + capability + "%",),
            ).fetchone() == (0,)


        from datetime import timedelta
        from django.db import transaction
        from lhie_candidate.compat.identity import email_otp_digest, sha256_digest

        code, challenge = "123456", sha256_digest("synthetic-unbound-otp")
        customer = admin.execute(
            "SELECT id,auth_version,enrollment_version FROM public.customers WHERE email=%s",
            (verification_email,),
        ).fetchone()
        created = service.clock()
        grant = admin.execute(
            """INSERT INTO public.customer_auth_tokens
            (customer_id,email,purpose,token_hash,challenge_hash,session_binding_hash,
             auth_version,enrollment_version,attempts,expires_at,created_at,updated_at)
            VALUES (%s,%s,'email_otp',%s,%s,NULL,%s,%s,0,%s,%s,%s) RETURNING id""",
            (customer[0], verification_email,
             email_otp_digest(verification_email, challenge, code, service.app_key),
             challenge, customer[1], customer[2], created + timedelta(minutes=10), created, created),
        ).fetchone()
        with transaction.atomic():
            unbound_id = service.mail.persist(
                grant[0], verification_email, code, created + timedelta(minutes=10))
        unbound = code
        prior_calls = len(FakeSMTP.messages)
        assert not mailer.deliver(unbound_id)
        assert len(FakeSMTP.messages) == prior_calls
        assert admin.execute(
            "SELECT status,payload FROM public.customer_auth_mail_works WHERE id=%s", (unbound_id,)
        ).fetchone() == ("cancelled", None)
        assert unbound not in str(admin.execute(
            "SELECT status,payload,last_error_code FROM public.customer_auth_mail_works WHERE id=%s",
            (unbound_id,),
        ).fetchone())
        assert admin.execute(
            "SELECT count(*) FROM public.audit_logs WHERE new_values::text LIKE %s",
            ("%" + unbound + "%",),
        ).fetchone() == (0,)

    return "four purpose-specific synthetic MIME messages match PHP copy/actions/routes; malformed OTP binding cancels before loopback SMTP"
