# AGENTS.md — tds-ext-support-tickets

Support-ticket extension, ported from `tds-customer-api`. Read
`tds-panel-contract` + `tds-core-panel-api` AGENTS first — this consumes the
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
- **TODO (next):** attachments (ticket_attachment + signed download), richer
  notifications + a customer directory (portal-customer emails), IMAP +
  contact-form ingest, then the contact-tickets split.

## After a change

Bump `version` in `package.json` + `composer.json` (lockstep), update docs,
commit together.
