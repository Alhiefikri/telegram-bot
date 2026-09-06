# Customer Web Account Operations

Production must set `APP_URL` to the canonical HTTPS storefront URL and configure a transactional SMTP provider through deployment secrets. `MAIL_MAILER=smtp`, `MAIL_SCHEME=tls`, port `587`, `MAIL_HOST`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, and `MAIL_FROM_NAME` must be checked before enabling customer-facing email. Do not put SMTP credentials in the repository.

Guest checkout email is optional and remains order-scoped; it never populates or merges the anonymous `Customer`. The `notifications` queue worker delivers order-created, payment-confirmed, fulfillment-completed, expired, and cancelled milestone messages. Confirm that the `notifications` pool is present in `supervisord.conf` and that `ops:health --db --queue-schema --queue-depth` reports the `notifications` queue.

Guest-order mail contains only the invoice number, current status, non-secret item summary, relevant payment deadline, a signed invoice/status URL, and the `/akses-pesanan` CTA. The invoice/status URL does not grant credential access; the existing order access proof remains required.

The scheduled command `customers:cleanup-login-links --chunk=500` removes only consumed or expired links. Queue workers should be monitored using the existing queue health check. Login-link and guest-order notification failures are observable through failed jobs and the `customer.login_link_requested` / `customer.login_link_consumed` audit actions; tokens, Recovery Keys, credentials, and email addresses are not logged.

Support must not reveal Recovery Keys or attach an order manually. Customers recover a guest order with the invoice and Recovery Key at `/akun/klaim-pesanan`.
