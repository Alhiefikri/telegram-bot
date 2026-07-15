# Customer Web Account Operations

Customer magic links use Laravel's existing database queue and cache stores. Production must set `APP_URL` to the canonical HTTPS storefront URL and configure the SMTP variables in `.env` or deployment secrets. Do not put SMTP credentials in the repository.

The scheduled command `customers:cleanup-login-links --chunk=500` removes only consumed or expired links. Queue workers should be monitored using the existing queue health check. Login-link failures are observable through failed jobs and the `customer.login_link_requested` / `customer.login_link_consumed` audit actions; tokens, Recovery Keys, credentials, and email addresses are not logged.

Support must not reveal Recovery Keys or attach an order manually. Customers recover a guest order with the invoice and Recovery Key at `/akun/klaim-pesanan`.
