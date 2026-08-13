# Literal Text and Single-Photo Admin Broadcasts

## Problem Statement

An authorized admin can currently create an Admin Broadcast from the Store Bot with `/broadcast`, but the flow retains only the command text. When the admin sends a photo with `/broadcast` in its caption, the photo reference is discarded and customers receive only the caption. Every queued Admin Broadcast is also wrapped in localized boilerplate such as `📣 Info from Admin`, a separator, and a support footer, so the customer-visible message is not the content the admin authored.

The existing delivery pipeline already provides durable recipient snapshots, bounded batches, per-recipient claims, retry, recovery, and status counts. The feature must add photo delivery without bypassing those safeguards, creating an inline fan-out, allowing stale preview controls to send newer drafts, or exceeding Telegram's broadcast limits.

## Solution

Make `/broadcast` the explicit, mandatory trigger for Store Bot broadcasts. An authorized admin in a private chat may create either a text-only Admin Broadcast or a single-photo Admin Broadcast with an optional caption. The bot strips only the leading `/broadcast` command, shows the exact outgoing content as a preview, and requires an explicit confirmation before queueing it.

Customers receive the admin-authored text or photo/caption literally, without an automatic title, separator, or support footer. The same literal-output rule applies to text broadcasts submitted from the admin dashboard, while photo upload remains exclusive to the Store Bot for this version. The optional localized product action remains supported.

The implementation reuses Telegram's opaque `file_id` for previews and recipient delivery, persists the confirmed content in the durable Admin Broadcast record, and keeps all fan-out in the existing `telegram-broadcast` queue. Draft-version checks, atomic single-use confirmation, conservative shared throttling, and Telegram-aware retry behavior protect the flow from stale callbacks, double taps, concurrent workers, and flood-control responses.

## User Stories

1. As an authorized admin, I want to send `/broadcast` followed by text, so that I can send an announcement to eligible Telegram customers.
2. As an authorized admin, I want to attach one photo and put `/broadcast` at the start of its caption, so that the photo is included in the announcement.
3. As an authorized admin, I want to send a photo whose caption is only `/broadcast`, so that I can create a photo-only announcement.
4. As an authorized admin, I want to include text after `/broadcast` in a photo caption, so that the photo and its caption arrive as one Telegram message.
5. As an authorized admin, I want the command itself removed from the outgoing content, so that customers never see `/broadcast`.
6. As an authorized admin, I want my text and caption characters delivered without automatic titles, separators, or support instructions, so that I control the complete customer-visible message.
7. As an admin using the web dashboard, I want my submitted text delivered literally too, so that Admin Broadcast behavior is consistent across admin entry points.
8. As an authorized admin, I want a preview before delivery, so that I can catch the wrong photo or wording before it reaches customers.
9. As an authorized admin, I want a photo preview to display the cleaned caption, so that the preview represents the outgoing content rather than the command input.
10. As an authorized admin, I want preview controls separate from the photo content, so that admin-only instructions cannot leak into the customer caption.
11. As an authorized admin, I want to add or replace the existing optional product action from the preview, so that a broadcast can lead customers to a purchasable product.
12. As an Indonesian customer, I want an attached product action in Bahasa Indonesia, so that the action remains understandable without changing the authored broadcast content.
13. As an English customer, I want an attached product action in English, so that the action remains understandable without changing the authored broadcast content.
14. As an authorized admin, I want `Kirim` to work only for the draft shown by that preview, so that an old preview cannot send a newer draft.
15. As an authorized admin, I want repeated taps on `Kirim` to create at most one Admin Broadcast, so that customers do not receive duplicates from an accidental double tap.
16. As an authorized admin, I want a failed queueing attempt to leave my draft recoverable, so that a transient database failure does not silently discard the announcement.
17. As an authorized admin, I want a draft to expire after 15 minutes, so that an old confirmation cannot unexpectedly send stale content.
18. As an authorized admin, I want an expired, cancelled, replaced, or already-consumed draft control rejected clearly, so that I know to start a new broadcast.
19. As an authorized admin, I want text longer than 3,500 characters rejected before preview, so that delivery does not fail after queueing.
20. As an authorized admin, I want photo captions longer than 1,024 characters rejected before preview, so that delivery respects Telegram's media-caption contract.
21. As an authorized admin, I want an overlong caption rejected rather than split into another message, so that a retry cannot leave customers with half of a multi-message announcement.
22. As an authorized admin, I want albums rejected for this version, so that an album cannot be partially captured or delivered out of order.
23. As an authorized admin, I want videos, animations, documents, audio, voice notes, stickers, and other unsupported media rejected, so that they cannot silently degrade into text-only broadcasts.
24. As a non-admin user, I want `/broadcast` denied, so that only authorized operators can contact the full customer audience.
25. As an admin in a group or channel, I want the broadcast flow redirected to a private bot chat, so that shared-chat participants cannot trigger or observe admin controls.
26. As a customer, I want one confirmed photo broadcast delivered as one photo message, so that its image and caption remain together.
27. As a customer, I want a text-only broadcast delivered as one text message, so that the new media capability does not change ordinary broadcasts.
28. As an operator, I want recipient materialization and sending to remain asynchronous, so that webhook and dashboard requests return without waiting for every customer.
29. As an operator, I want broadcasts sent in bounded batches, so that a large audience does not exhaust a request or worker's memory.
30. As an operator, I want duplicate jobs and overlapping workers to claim each recipient only once during normal processing, so that retries do not ordinarily resend successful deliveries.
31. As an operator, I want transient Telegram failures retried without resending successful recipients, so that temporary outages can recover safely.
32. As an operator, I want permanent recipient failures isolated, so that a blocked or invalid chat does not stop delivery to other customers.
33. As an operator, I want Telegram flood-control responses to delay retry for the requested interval, so that workers do not repeatedly violate Telegram's limit.
34. As an operator, I want Admin Broadcast send attempts throttled across concurrent broadcast workers, so that scaling the worker count does not multiply the send rate unexpectedly.
35. As an operator, I want an interrupted, unmaterialized, or expired-claim broadcast discoverable by the existing recovery command, so that photo and text broadcasts share the same operational recovery path.
36. As an operator, I want status counts to include photo broadcasts, so that I can monitor total, sent, pending, and failed recipients from the dashboard.
37. As a security-conscious operator, I want logs to omit authored content, captions, Telegram file identifiers, bot tokens, and raw recipient identifiers, so that operational diagnostics do not expose broadcast data.
38. As a maintainer, I want text and photo deliveries to share one durable delivery pipeline, so that security, concurrency, retry, and observability do not drift between content types.

## Implementation Decisions

- **Canonical concept:** Use **Admin Broadcast** for the intentionally confirmed message sent by an authorized admin to eligible Telegram customers. Keep automatic product and stock notifications distinct from Admin Broadcasts.
- **Explicit trigger:** `/broadcast` remains mandatory. Ordinary admin chat content is never fanned out automatically.
- **Authorization boundary:** Draft creation, preview callbacks, product selection, confirmation, and cancellation remain admin-only and private-chat-only. Callback authorization is checked again on every action; a callback token is not an authorization credential.
- **Supported content:** Version one supports text only, one photo without a caption, or one photo with a caption. Albums and all nonphoto media are rejected.
- **Literal customer content:** Strip the leading command and its surrounding whitespace, then preserve the remaining text characters. Do not prepend a localized title or separator and do not append support copy. This rule applies to both Store Bot and dashboard Admin Broadcasts.
- **Plain-text scope:** Telegram message/caption formatting entities are not preserved in this version. Literal means the authored characters after command removal, delivered without an injected wrapper.
- **Length contracts:** Text-only content is limited to 3,500 characters. Photo captions are limited to 1,024 characters after command removal. An empty caption is valid only when a photo is present. Overlong content is rejected before draft persistence and preview.
- **Ingress minimization:** Persist only the Telegram fields needed to route and validate the update. For photos, retain the sanitized photo-size references needed to select the largest available `file_id`. Retain a sanitized media-kind marker and media-group identifier so unsupported media and albums can be rejected rather than misclassified as text.
- **Opaque photo reference:** Reuse the largest incoming Telegram photo `file_id` with `sendPhoto`. Do not download photo bytes, fetch a public URL, or store binary media. The identifier is valid only for the bot that received it and is never accepted from an admin-supplied free-text field.
- **Draft contract:** A draft contains an unpredictable draft identifier, literal message/caption, optional photo reference, and optional product identifier. It remains scoped to the admin's Telegram identity and expires after 15 minutes.
- **Version-bound controls:** Every preview, picker, select, send, and cancel callback carries the draft identifier within Telegram's callback-size limit. A callback whose identifier does not match the current draft is rejected as stale.
- **Exact preview:** Text content is shown before its control panel. A photo is resent by reference with the cleaned caption, followed by a separate admin-only control panel. The panel states whether a product action is selected and provides add/change, send, and cancel actions. Preview labels are not part of customer content.
- **Atomic draft operations:** Draft mutation, cancellation, and confirmation use the same distributed cache-lock boundary. Confirmation verifies the draft identifier, creates or reuses the durable Admin Broadcast intent, and only then removes the matching draft. If persistence fails, the draft remains available for retry.
- **Idempotent confirmation:** The draft identifier becomes or derives a stable idempotency key protected by a unique database constraint. Concurrent or repeated confirmations can resolve to the same Admin Broadcast, never two records.
- **Durable content model:** A nullable photo reference distinguishes photo delivery from text delivery; the existing message field stores literal text or caption and may be empty only for a photo broadcast. Dashboard submissions continue to create text-only records.
- **Product action:** Keep the optional product action. Product eligibility is revalidated before confirmation. Only the action label is localized per recipient; the authored text/caption is not translated or wrapped.
- **Shared delivery path:** Recipient materialization, ordering, uniqueness, claim tokens, attempt counts, success markers, terminal-failure markers, status aggregation, continuation jobs, and scheduled recovery remain shared for text and photo broadcasts.
- **Transport selection:** The per-recipient delivery step calls `sendMessage` for text records and `sendPhoto` by stored `file_id` for photo records. Both attach the same resolved optional product reply markup.
- **Bounded work:** Keep recipient materialization chunked and delivery batches capped at 25 recipients. Webhook and dashboard request paths only create an intent and enqueue work; neither performs recipient fan-out.
- **Broadcast throttling:** Coordinate Admin Broadcast send attempts across workers with a shared limiter capped conservatively below Telegram's default 30-messages-per-second broadcast limit. Limiter exhaustion requeues remaining work without consuming a recipient attempt or holding a database transaction during a wait.
- **Telegram retry:** HTTP 400/403 recipient failures remain terminal where appropriate. HTTP 429 remains transient and uses Telegram's sanitized, bounded `retry_after` value when present; other transient failures retain bounded backoff. Successful recipients are excluded from retry.
- **External-effect caveat:** Telegram does not provide an idempotency key for `sendMessage` or `sendPhoto`. A worker crash after Telegram accepts a send but before `sent_at` commits can still cause an at-least-once duplicate after claim expiry. The feature preserves the existing claim-based reduction of this window and does not claim exactly-once external delivery.
- **Security and privacy:** Never log authored text/captions, photo identifiers, tokens, callback draft identifiers, or raw Telegram IDs. Operational logs retain only redacted correlations, internal broadcast/recipient IDs, outcome, timing, counts, HTTP status, and exception class.
- **Observability:** Existing queued/in-progress/completed/partially-failed/failed/skipped statuses and dashboard counts apply unchanged to both content forms. Timing logs distinguish the Telegram operation without logging its content.
- **No ADR:** These decisions are feature-level, easy to evolve, and visible in the spec and behavior tests; they do not meet the repository threshold for a hard-to-reverse ADR.

## Testing Decisions

- Prefer behavior tests at the highest existing seams: Telegram webhook input through preview/confirmation, and durable Admin Broadcast processing through recorded Telegram HTTP requests.
- Extend the existing Telegram admin-menu feature coverage to prove that a photo caption beginning with `/broadcast` creates a photo draft, resends an exact preview, keeps controls separate, and rejects non-admin, nonprivate, album, unsupported-media, overlong-caption, stale-draft, expired-draft, and repeated-confirmation scenarios.
- Extend the existing Admin Broadcast queue feature coverage to prove transport selection: text records call `sendMessage` with literal text; photo records call `sendPhoto` with the stored `file_id`, literal optional caption, and localized product reply markup.
- Keep the dashboard submission test as the contract that dashboard Admin Broadcasts remain text-only, durable, queued, and nonblocking. Add an assertion that delivery contains no automatic title, separator, or support footer.
- Exercise duplicate and concurrent confirmation at the service/webhook seam with two callbacks targeting the same draft and assert one durable broadcast intent and one effective dispatch.
- Exercise a stale preview by creating draft A, replacing it with draft B, then invoking draft A's callback and asserting that neither draft is incorrectly sent.
- Preserve bounded-batch tests with more recipients than one batch, continuation dispatch, successful-recipient exclusion, permanent failure isolation, expired-claim recovery, and aggregate status transitions for both content forms where transport matters.
- Add shared-limiter coverage with multiple workers/broadcasts contending in the same window. Assert that throttled recipients remain pending, do not increment attempts, and are scheduled for later processing.
- Add HTTP 429 coverage with `parameters.retry_after`; assert that retry does not occur before the bounded provider interval and does not resend successful recipients.
- Assert log redaction with sensitive text, caption, and photo identifiers while retaining internal IDs, timing, status, and counts.
- Use fake Telegram HTTP at integration seams; do not inspect private helper methods or assert cache/database implementation calls unless they are required to prove cross-worker atomicity.
- Run the focused Telegram admin-menu, Admin Broadcast queue, dashboard authorization/return-target, latency instrumentation, Bot API client, recovery, and infrastructure worker tests. Run the full test suite after focused coverage passes.
- Because production uses PostgreSQL and database-backed cache/queue, include the concurrency lane when adding database uniqueness or cross-worker claim behavior that SQLite cannot prove.

## Out of Scope

- Automatically broadcasting every message sent by an admin.
- A two-step mode where `/broadcast` arms the next unrelated message or photo.
- Photo upload or media preview in the admin web dashboard.
- Albums, multiple photos, video, animation/GIF, audio, voice, document, sticker, or other media.
- Splitting a long caption into a photo message plus a separate text message.
- Preserving Telegram rich-text entities, parse modes, spoilers, or caption placement options.
- Editing, recalling, or cancelling an Admin Broadcast after durable queueing has begun.
- Exactly-once guarantees across a crash between Telegram accepting a message and the local success marker committing.
- Paid Telegram broadcasts or raising throughput to 1,000 messages per second.
- Redesigning automatic product/stock notifications or moving their current synchronous fan-out as part of this feature.
- Adding automated sensitive-content detection; preview, explicit confirmation, access control, and existing admin warnings remain the protection against operator mistakes.

## Further Notes

- Telegram's Bot API accepts an existing bot-owned `file_id` as the `photo` value, supports an inline keyboard on a photo, and limits photo captions to 1,024 characters after entity parsing. Reusing `file_id` avoids binary transfer for every recipient.
- Telegram documents a default broadcast limit of 30 messages per second. This design deliberately stays below it and treats `retry_after` as authoritative flood-control feedback.
- The existing recovery command already rediscovers unmaterialized broadcasts and recipients whose claim expired. A nullable photo reference keeps media recovery inside that same lifecycle.
- Source: [Telegram Bot API](https://core.telegram.org/bots/api).
