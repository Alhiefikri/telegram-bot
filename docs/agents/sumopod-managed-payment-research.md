# Sumopod Managed Payment: merchant-suitability research

> Checked against public, provider-owned Sumopod sources on 2026-09-08. This is a risk and documentation review, not legal advice or production approval. “Not documented” means the reviewed first-party material neither permits nor prohibits the item expressly; it must not be read as permission.

## Decision

**Do not migrate this Telegram premium-account business to Sumopod Managed Payment yet.**

The decisive issue is policy, not API capability. Sumopod’s published Acceptable Use Policy says users agree not to use its services for **“Automated bots and scripts.”** It also prohibits illegal activity, phishing or fraud, and intellectual-property violations ([Terms of Service, §4](https://sumopod.com/terms)). A Telegram sales bot is therefore **prohibited on the literal wording of the current general Terms**, unless Sumopod gives a written, product-specific exception and confirms that the exception controls over the general Terms.

At the same time, Sumopod’s own dashboard code provides a Managed Payment API, QRIS selection, hosted payment links, webhooks, a merchant wallet, withdrawals, and KYC ([Managed Payment quick start](https://sumopod.com/assets/Wallet-CrdiCSzj.js), [wallet and withdrawal UI](https://sumopod.com/assets/WalletBalanceHeader-BmIKhJ2a.js), [KYC form](https://sumopod.com/assets/WalletKycForm-Bk7kxCnx.js)). That proves technical product functionality, **not merchant or product-category approval**. The contradiction between a programmatic payment API and the broad bot ban is unresolved publicly.

Sumopod does not publicly say that Telegram commerce, premium digital accounts, shared subscription slots, or delivery of account credentials is accepted. Its KYC form instead asks what products/services will use Payment and requires a website, then submits the business for review ([KYC form](https://sumopod.com/assets/WalletKycForm-Bk7kxCnx.js)). Premium-account sales are therefore **undocumented by name and unapproved**, not implicitly permitted.

## Direct acceptance answer

| Proposed activity | Public classification | Evidence and consequence |
| --- | --- | --- |
| Operating an automated Telegram sales bot with Sumopod services | **Prohibited on the literal general Terms; Managed Payment scope is ambiguous** | The AUP expressly lists “Automated bots and scripts.” The same Terms describe the governed platform as cloud hosting and container management, while Managed Payment exists elsewhere in the dashboard. There is no published Managed Payment carve-out, so production use requires a written exception that resolves the scope conflict ([Terms §§1–4](https://sumopod.com/terms), [Managed Payment dashboard](https://sumopod.com/assets/Wallet-CrdiCSzj.js)). |
| Telegram or social-commerce as the storefront/channel | **Undocumented; not expressly accepted** | No reviewed official landing page, policy, onboarding form, or Managed Payment UI names Telegram as an accepted sales channel. KYC requires a non-empty Website field; it does not say whether a `t.me` bot/channel URL qualifies ([homepage](https://sumopod.com/), [KYC form](https://sumopod.com/assets/WalletKycForm-Bk7kxCnx.js)). |
| Selling “premium” third-party digital accounts or subscription access | **Undocumented by product name; not expressly accepted** | The KYC asks the merchant to describe the products/services, but Sumopod publishes no Managed Payment restricted-product list covering account resale. If the sourcing or resale infringes rights, violates law, or is fraudulent, the general prohibitions on illegal activity, IP infringement, phishing, and fraud would apply ([KYC form](https://sumopod.com/assets/WalletKycForm-Bk7kxCnx.js), [Terms §4](https://sumopod.com/terms)). |
| Delivering usernames, passwords, shared/family slots, activation links, or other credentials | **Undocumented; not expressly accepted** | None of these delivery models is addressed in the reviewed first-party material. Absence of a named ban is not approval; written category approval must cover the exact sourcing and delivery model ([Terms](https://sumopod.com/terms), [KYC form](https://sumopod.com/assets/WalletKycForm-Bk7kxCnx.js)). |
| Using the API after KYC | **Technically supported and conditionally accessible; not category approval** | Live Managed Payment requires KYC review. The UI also offers test-only sandbox access without KYC and expressly says sandbox data is not real ([no-access/onboarding UI](https://sumopod.com/assets/WalletNoAccess-DvTeCEou.js), [wallet UI](https://sumopod.com/assets/WalletBalanceHeader-BmIKhJ2a.js)). |

## What the product publicly documents

### Payment and QRIS flow

1. The dashboard quick start shows `POST https://api-pay.sumopod.com/api/v1/payments` with `X-Api-Key`. Its example sends `order_id`, `amount`, `currency: "IDR"`, return URLs, and optional `payment_method_type_code: "QRIS"`. It says `expires_in_hours` defaults to 24 hours and has a 24-hour maximum ([quick start](https://sumopod.com/assets/Wallet-CrdiCSzj.js)).
2. The example response contains a Sumopod-hosted `payment_link_url`, `payment_id`, fee, net amount, status, and expiry. When a separate direct-pay-in mode is enabled, extra payment-code/channel fields may be returned. These are examples, not a complete published OpenAPI schema or a guarantee of the channels enabled for a particular merchant ([quick start](https://sumopod.com/assets/Wallet-CrdiCSzj.js), [checkout settings](https://sumopod.com/assets/DirectPayInSection-PQA1mS4B.js)).
3. By default, the customer uses a hosted payment-link page and selects a channel. Sumopod also exposes a configurable checkout mode and says its create response then includes additional fields for building a custom payment page ([checkout settings](https://sumopod.com/assets/DirectPayInSection-PQA1mS4B.js)). The reviewed material does **not** promise a raw QRIS EMV string or QR image suitable for sending directly inside Telegram.
4. The quick start lists `payment.completed`, `payment.failed`, `payment.expired`, and `payment.test` webhook events. It requires a `2xx` response within ten seconds and says failed deliveries can be resent from the Webhooks tab. It documents Svix-style HMAC verification through `svix-id`, `svix-timestamp`, and `svix-signature`, plus an alternative `X-Webhook-Token` comparison ([quick start](https://sumopod.com/assets/Wallet-CrdiCSzj.js), [webhook settings](https://sumopod.com/assets/WebhookSection-DPCsl6CG.js), [webhook log UI](https://sumopod.com/assets/WalletWebhooks-DYIPbzF_.js)).
5. The payment list supports cancelling a pending hosted payment link. For one direct-pay type, the UI warns: **“There is no cancel endpoint for this payment type,”** and a customer payment of the VA/QR can still complete as real money after Sumopod marks it cancelled locally ([payment UI](https://sumopod.com/assets/WalletPayments-DWULdMAi.js)). A merchant refund API or Managed Payment customer-refund procedure was not found in the reviewed public material.

The technical flow is usable as a hosted-checkout integration, but the public quick start does not establish a server-to-server payment-status inquiry, idempotency/duplicate-order rules, authoritative late-payment handling, complete webhook retry schedule, or production channel matrix. Those are production blockers until documented in writing ([quick start](https://sumopod.com/assets/Wallet-CrdiCSzj.js)).

### KYC and access

Live access requires KYC. The form requires a KTP image, full name, company name, WhatsApp number, business description, website, full Indonesian address, receiving bank, account number, and account-holder name matching the KTP. The product prompt specifically asks, **“What products/services will use the Payment feature?”** and says review usually takes 1–3 business days with email notification ([KYC form](https://sumopod.com/assets/WalletKycForm-Bk7kxCnx.js)). The access screen says sandbox can be created instantly without KYC but uses test data only ([onboarding UI](https://sumopod.com/assets/WalletNoAccess-DvTeCEou.js)).

These fields show that Sumopod reviews a proposed business and withdrawal beneficiary. They do not disclose accepted entity types, required proof of resale authorization, who legally owns the QRIS merchant account/NMID, who is merchant of record, or which licensed processor/acquirer holds and settles the customer funds.

### Fees, settlement, withdrawals, and holds

- The dashboard obtains enabled payment methods per merchant and displays each method’s percentage/fixed fee and `settlement_days`; it does not publish a universal live fee schedule in the reviewed public page. Its sample response’s fee is illustrative only ([quick start](https://sumopod.com/assets/Wallet-CrdiCSzj.js)).
- Fee passthrough is configurable: by default the method fee is deducted from the merchant’s receipt; a toggle can add it to the customer’s amount instead ([fee settings](https://sumopod.com/assets/FeeSettingsSection-xwgifgVv.js)). Whether that is enabled or contractually allowed for this account remains merchant-specific.
- The wallet calls completed payments awaiting settlement **“Pending Balance”** and describes timing as **“usually H+1.”** “Available Balance” is withdrawable to the registered bank. This is UI guidance, not a published settlement SLA with business-day, holiday, cutoff, or failure terms ([wallet UI](https://sumopod.com/assets/WalletBalanceHeader-BmIKhJ2a.js)).
- The withdrawal screen obtains the account’s fee, minimum, maximum, available balance, and beneficiary bank from the API; no general public values are stated. KYC is required for withdrawal ([wallet and withdrawal UI](https://sumopod.com/assets/WalletBalanceHeader-BmIKhJ2a.js)).
- The same UI exposes “Hold Balance” for funds reserved for pending withdrawals and “Frozen Balance” for funds **“temporarily locked by admin.”** A suspended Managed Payment account can view data, but withdrawals and settings changes are disabled ([wallet UI](https://sumopod.com/assets/WalletBalanceHeader-BmIKhJ2a.js), [dashboard access guard](https://sumopod.com/assets/index-SWGR7__9.js)). No public policy defines reserve triggers, admin-freeze grounds, duration, notice, appeal, or treatment of funds on termination.

### Refunds, disputes, and account ownership

Sumopod’s public Refund Policy is about the customer’s prepaid **Sumopod Balance**, not clearly about a merchant refunding a buyer. It says Balance is only platform credit, is non-transferable/non-withdrawable, and that top-ups are final and non-refundable. Its “Disputed Charges” section asks a Sumopod user to report erroneous top-up charges within seven days and warns that initiating a chargeback first may suspend the account ([Refund Policy](https://sumopod.com/refund-policy)). It must not be repurposed as a Managed Payment refund or buyer-dispute contract.

The Privacy Policy says payment information is processed through third-party providers and may be shared with payment processors, but it does not identify those parties or describe the merchant fund flow ([Privacy Policy §§1, 3, 8](https://sumopod.com/privacy-policy)). Public material therefore does not establish:

- the licensed payment gateway/acquirer behind Managed Payment;
- whether Sumopod, the seller, or another entity owns the payment/QRIS merchant account and NMID;
- whose name the buyer sees on the QRIS/payment receipt;
- who owes the buyer a refund or answers a dispute;
- whether balances are safeguarded, segregated, or merely contractual receivables; or
- how chargebacks, negative balances, reserves, fraud reviews, termination, and final payout work.

## Risk compared with a direct payment gateway

| Risk | Sumopod Managed Payment based on current public evidence | Direct-gateway comparison |
| --- | --- | --- |
| Product/channel approval | **High.** The general Terms literally ban automated bots, while premium-account/credential resale and Telegram commerce are not expressly accepted ([Terms](https://sumopod.com/terms)). | Lower only if the direct gateway gives written approval for the exact products, sourcing, credential-delivery model, and Telegram channel. A working API or successful KYC alone is not approval in either model. |
| Counterparty and legal clarity | **High.** Sumopod acknowledges third-party payment processors but does not publicly identify the processor, merchant-account owner, NMID owner, or merchant of record ([Privacy Policy](https://sumopod.com/privacy-policy)). | A direct relationship can remove this Sumopod-specific intermediary layer and put processor identity and merchant obligations in one merchant agreement. It does not remove QRIS/acquirer or product-policy obligations. |
| Access to funds | **High.** Funds pass through pending, available, hold, and admin-frozen states; suspension disables withdrawals, while freeze/reserve rules are unpublished ([wallet UI](https://sumopod.com/assets/WalletBalanceHeader-BmIKhJ2a.js)). | Prefer a direct provider only when its contract states settlement timing, reserves, suspension, final payout, and appeal. The comparison is contractual, not a claim that every direct gateway is safer. |
| Refund/dispute operations | **High.** Hosted-link cancellation exists, one direct-pay cancellation can still be paid, and no Managed Payment refund/dispute contract was found ([payment UI](https://sumopod.com/assets/WalletPayments-DWULdMAi.js), [Refund Policy](https://sumopod.com/refund-policy)). | A direct gateway is preferable if it documents merchant-initiated QRIS refunds, dispute evidence/deadlines, late-payment treatment, and account debits. |
| Integration | **Medium.** Payment creation and signed/token-authenticated webhooks are documented, but the public material is an in-dashboard quick start rather than a complete versioned API contract ([quick start](https://sumopod.com/assets/Wallet-CrdiCSzj.js)). | Direct integration may cost more engineering effort, but a versioned create/status/refund API and explicit notification contract reduce fulfillment ambiguity. |

**Overall:** compared with a direct gateway that gives written category approval and a complete merchant contract, Sumopod currently adds policy ambiguity and an extra wallet/freeze/withdrawal dependency without publishing the corresponding merchant protections. That makes it a materially higher-risk replacement for a merchant already banned elsewhere over product/channel compatibility. This conclusion changes only if Sumopod answers the questions below in writing and the answers are incorporated into the merchant agreement or a binding product-specific approval.

## Questions Sumopod must answer in writing before migration

Send these to the official support contacts and require answers tied to the applying legal person/business and its live Managed Payment account—not a generic “it should work” response.

1. **Bot exception:** Does the §4 ban on “Automated bots and scripts” apply to a Telegram bot that calls Managed Payment from infrastructure outside Sumopod? If not, provide the exact product-specific exception and state which terms prevail.
2. **Telegram channel:** Is a Telegram bot/channel expressly approved as the sole storefront and fulfillment channel? Does a `t.me/...` URL satisfy the mandatory KYC Website field, or is a separately owned public website required?
3. **Exact product approval:** Are sales of third-party “premium” accounts/subscriptions expressly allowed? Answer separately for newly created accounts, shared/family-plan slots, upgrades to a buyer-owned account, activation links/codes, and delivery of usernames/passwords.
4. **Authorization evidence:** What supplier invoices, reseller authorization, brand permission, proof of account ownership, and end-customer terms are required? Which services/brands or account-transfer models are prohibited even if the merchant can technically fulfill them?
5. **Decision durability:** Is approval revocable solely at Sumopod’s discretion after KYC? Will written category approval remain valid unless the disclosed business model changes, and what notice/cure period applies?
6. **Payment-chain ownership:** Name the licensed payment processor, QRIS acquirer/aggregator, and regulated entity holding funds. Who owns the QRIS merchant account/NMID, who is merchant of record, and what merchant/brand name appears to the buyer?
7. **Contract set:** Provide the Managed Payment merchant agreement, acceptable/restricted-product policy, privacy/data-processing terms, refund/dispute rules, and the order of precedence over the general Sumopod Terms.
8. **KYC eligibility:** Which merchant entity types are accepted (individual, sole proprietor, PT/CV, etc.) and which KTP/NPWP/NIB/company/beneficial-owner documents are required? Must the company name, KTP name, website owner, and bank-account holder match?
9. **Complete pricing:** State live QRIS MDR, fixed fee, VAT/tax treatment, withdrawal fee, refund/dispute fee, monthly/minimum fee, and every other deduction. Confirm whether customer fee passthrough is approved and how it must appear on receipts.
10. **Settlement and withdrawal:** Define T/H+N from successful payment to available balance, timezone/cutoff, weekends/holidays, minimum and maximum withdrawal, payout SLA, supported banks, failed-payout handling, and final payout after suspension/termination.
11. **Reserves, holds, and freezes:** State all triggers, formulas/amounts, maximum duration, notice, evidence requests, review SLA, appeal/escalation path, and whether funds can be withheld after account closure.
12. **Refunds and disputes:** Who refunds the buyer, by what API/dashboard/manual process, with what full/partial limits and time window? Who handles QRIS complaints, fraud claims, duplicate/late payments, chargebacks, negative balances, and evidence deadlines?
13. **Payment lifecycle:** Provide versioned live API documentation covering idempotency, duplicate `order_id`, amount limits, enabled QRIS channels, raw QR/image availability, payment status inquiry, expiry, cancellation, payment-after-cancel, webhook retries/order/deduplication, and authoritative fulfillment status.
14. **Support commitment:** Give the production incident/funds escalation channel, support hours and timezone, response SLA, and named route for policy or frozen-balance appeals.

Official public contacts are `support@sumopod.com` and WhatsApp `+62 851-9005-2577` ([Terms §14](https://sumopod.com/terms), [Privacy Policy §11](https://sumopod.com/privacy-policy)). Obtain the decisive approval by email or signed agreement so the precise product/channel description and the bot-policy exception are preserved.

## Recommendation and migration gate

**Recommendation: no-go pending written approval; prefer a direct gateway with explicit category acceptance.** Do not pre-fund, route real buyers, or rely on sandbox success or API availability. Sumopod becomes a candidate only after it:

1. expressly approves the Telegram bot/channel and every premium-account fulfillment model actually sold;
2. provides a binding exception to or interpretation of the published bot ban;
3. identifies the regulated payment chain and merchant-account ownership;
4. supplies complete fees, settlement, reserve/freeze, refund/dispute, and termination terms; and
5. passes a merchant-sandbox exercise of create, QRIS presentation, authenticated webhook, independent status inquiry, expiry, late payment, cancel, refund, and withdrawal using the approved business profile.

If any answer is informal, conditional on undisclosed downstream rules, or refuses to name the underlying processor and fund-control terms, use a direct payment gateway that will contract for the exact category instead.

## Public-source coverage and limitations

The stable public site currently markets container/application management and links general Terms, Privacy, Refund, registration, email, and WhatsApp support; it does not expose a Managed Payment landing page or a dedicated public documentation/policy center ([homepage](https://sumopod.com/), [registration](https://sumopod.com/register)). Managed Payment mechanics were found in first-party JavaScript served by Sumopod for its dashboard. Those hashed asset URLs are provider-owned but can change on deployment. No provider-owned announcement located in the reviewed public surface granted Telegram or premium-account category approval. That absence is recorded only as **unverified**, never as permission.
