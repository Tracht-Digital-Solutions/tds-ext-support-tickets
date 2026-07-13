import { defineExtension } from "@tracht-digital-solutions/tds-panel-contract";

/**
 * Support-tickets extension — the customer↔owner support system, ported from
 * tds-customer-api onto the panel platform. Customers open + follow tickets in
 * the portal; admins triage them. Data lives in this extension's own tables;
 * auth (RBAC + company scoping) comes from the core UserContext, email from the
 * core Mailer.
 *
 * `tickets:read`/`tickets:write` are the portal permissions; admins bypass.
 */
export default defineExtension({
  id: "support-tickets",
  name: "Support-Tickets",
  version: "0.1.0",
  permissions: [
    { id: "tickets:read", label: "Tickets ansehen", group: "support-tickets" },
    { id: "tickets:write", label: "Tickets erstellen & beantworten", group: "support-tickets" },
  ],
  nav: [
    {
      id: "tickets",
      label: "Tickets",
      href: "/tickets",
      icon: "life-buoy",
      group: "support",
      order: 10,
      permission: "tickets:read",
    },
  ],
  widgets: [
    {
      id: "tickets-open",
      title: "Offene Tickets",
      island: "@tracht-digital-solutions/tds-ext-support-tickets/widgets/Widget.astro",
      size: "sm",
      permission: "tickets:read",
      dataEndpoint: "/tickets/summary",
      order: 10,
    },
  ],
  settings: [
    {
      id: "support-tickets",
      label: "Support-Tickets",
      island: "@tracht-digital-solutions/tds-ext-support-tickets/islands/Settings.astro",
      order: 30,
    },
  ],
  routes: [
    {
      pattern: "/tickets",
      entrypoint: "@tracht-digital-solutions/tds-ext-support-tickets/pages/Index.astro",
      permission: "tickets:read",
    },
  ],
  i18n: {
    de: { "tickets.title": "Support-Tickets", "tickets.open": "Offene Tickets" },
    en: { "tickets.title": "Support tickets", "tickets.open": "Open tickets" },
  },
});
