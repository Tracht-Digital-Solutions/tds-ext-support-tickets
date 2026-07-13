# tds-ext-support-tickets

The **support-ticket system** as a panel extension, ported from
`tds-customer-api`. Customers open + follow tickets in the portal; admins triage
them. Built on the panel platform's core services:

- **Auth** — RBAC (`tickets:read`/`tickets:write`) + company scoping from the
  core `UserContext` (the extension never verifies tokens).
- **Email** — notifications through the **core `Mailer`** (no per-extension SMTP).
- **Data** — the extension's own `ticket` / `ticket_status` / `ticket_comment`
  tables via the core's shared `PDO`.

## Surface (checkpoint-1)

- **Customer:** `GET /tickets`, `POST /tickets`, `GET /tickets/{id}`,
  `POST /tickets/{id}/comments`, `GET /tickets/summary` (the "Offene Tickets"
  widget). Scoped to the JWT's active company; statuses flagged
  `visible_to_customer=0` show a neutral fallback label.
- **Admin:** `GET /admin/tickets`, `GET /admin/tickets/{id}` (incl. internal
  notes), `POST /admin/tickets/{id}/comments` (with `is_internal`),
  `PATCH /admin/tickets/{id}` (status/assignee/priority/type/customer-action),
  `GET /admin/ticket-statuses`.
- **Frontend:** nav "Tickets" → `/tickets`, the ticket list island, the open-count
  dashboard widget, DE/EN i18n.

## Still to port (later checkpoints)

Status-registry CRUD + colour tones editor, attachments, the full board UI
(detail view + comment thread + new-ticket form), richer notifications + a
customer directory (customer-email recipients), and the **IMAP + contact-form
ingest** channels.

## Develop

```bash
npm install        # pulls tds-panel-contract from GitHub Packages (needs NPM_TOKEN)
npm run build && npm run type-check
composer install   # resolves tds-panel-contract from its public VCS repo
composer test      # phpunit — route/RBAC coverage; DB-backed tests skip without TDS_TEST_DB_DSN
```

## Enable it

Host `astro.config.mjs`: add the manifest to `panelHost({ extensions: [...] })`.
Base API: add `new SupportTicketsModule()` to `Modules::enabled()`. Set
`TICKET_ADMIN_EMAIL` (+ the core `MAIL_DSN`) for admin notifications.
