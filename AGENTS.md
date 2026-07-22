# AGENTS.md — tds-ext-support-tickets-pkg

Support-ticket extension, ported from `tds-customer-api`. Read
`tds-frontend-contract-pkg` + `tds-core-frontend-api` AGENTS first — this consumes the
contract and the core services.

## Model / deviations from customer-api

- **Own DB tables** (`ticket`/`ticket_status`/`ticket_comment`) via the core PDO.
  `customer_id`/`project_id` carry **no foreign key** (those entities live in
  another domain) — `customer_id` = the JWT active company, NULLABLE for
  contact-form tickets. `status_id` keeps its FK to the same-DB status registry.
- **Auth via the core `UserContext`** — customer routes require
  `tickets:read`/`tickets:write` (admins bypass) + scope by `activeCompanyId`;
  admin routes require `isAdmin`. The module never verifies a token.
- **Email via the core `Mailer`** (`Notifier`) — no per-extension SMTP; no-ops
  when the core mailer is unconfigured. Admin recipient = `TICKET_ADMIN_EMAIL`;
  customer recipient = ticket `from_email` when present (a customer directory for
  portal-customer emails is a later port).
- **`author_type` is derived from the principal** (`owner` if admin else
  `customer`), never trusted from the client. `is_internal` comments are never
  returned to a customer principal (the customer `comments()` query filters them).

## Gotchas

- Migration class names are **module-prefixed** (`SupportTickets*`) — the
  in-process auto-migrator loads all modules' migrations into one process.
- Routes are closures resolving `UserContext`/`TicketRepository`/`Notifier` from
  the container **at request time** (UserContext is rebound per request by the
  core AuthMiddleware). Don't capture UserContext at register time.
- DB-backed tests skip without `TDS_TEST_DB_DSN`; the committed test covers
  routes + RBAC without a DB (auth short-circuits before repo access).

## Checkpoint status

- **CP1:** schema + repository + customer/admin CRUD + comments + triage update +
  RBAC + widget.
- **CP2:** status-registry CRUD (admin, single-default enforce + delete-guards:
  409 when in use or last status) + the portal board UI (list + detail + comment
  thread + new-ticket form).
- **CP3:** attachments — `ticket_attachment` table, `Support\AttachmentStorage`
  (on-disk under `TICKET_UPLOAD_DIR`, MIME + 25 MB whitelist), customer + admin
  upload/download routes (cookie-authenticated streaming download, not signed
  URLs — the session cookie is sent on `<a download>`), attachments surfaced in
  the board detail + an upload control.
- **CP4:** notification toggles — `ticket_setting` registry + `Domain\TicketSettings`,
  admin `GET`/`PUT /admin/ticket-settings` + a toggle island in the settings slot.
  Three gated events via `Notifier`: new ticket→admin, owner reply→customer, status
  change→customer (all through the core Mailer; no-op when off/unconfigured/no recipient).
- **CP5a:** contact-form ingest — `POST /tickets/contact` (server-to-server,
  `INGEST_TOKEN` via `?token=`/`X-Ingest-Token`, constant-time). tds-contact-api
  forwards each submission; creates a `type/source='contact'`, NULL-customer ticket
  with `from_*` details + a validated payload (name≥2, valid email, message≥20).
- **CP5b:** IMAP ingest — `Service\ImapTicketIngest` (webklex/php-imap over sockets,
  no ext-imap; needs **ext-zip** → enabled in the CI setup-php). `POST /tickets/ingest`
  (INGEST_TOKEN, external scheduler) + admin `POST /admin/tickets/ingest` ("Jetzt
  abrufen") + `GET /admin/tickets/imap-test`. Dedupe on Message-ID, thread replies onto
  an owned ticket (`#<id>` subject / In-Reply-To/References match a stored Message-ID
  whose ticket carries the sender's `from_email`). **Adaptation:** with no customer
  directory it only threads `from_email`-bearing tickets; mail from an unknown sender is
  skipped (opening new tickets for arbitrary senders needs the customer directory).
  Pure parsing helpers are unit-tested (no mailbox); webklex loads only on connect().
- **CP6:** portal-customer notification recipient — a portal ticket now stores the
  creator's `UserContext::email()` (contract 1.2.0) in `from_email`, so owner-reply +
  status-change emails reach portal customers (previously only contact/email tickets had
  a recipient).
- **TODO (next):** a customer directory so IMAP can open NEW tickets for unknown senders
  safely (threading already works); then the contact-tickets split.

Env (host-side): `TICKET_ADMIN_EMAIL`, `TICKET_UPLOAD_DIR` (unset → uploads 503),
`INGEST_TOKEN` (unset → ingest 503), `IMAP_HOST`/`IMAP_PORT`/`IMAP_USER`/`IMAP_PASS`/
`IMAP_SECURITY` (ssl|tls|none)/`IMAP_FOLDER` (unset host/user → poll no-ops).

## After a change

Bump `version` in `package.json` + `composer.json` (lockstep), update docs,
commit together.
