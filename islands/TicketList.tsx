import { useEffect, useState } from "react";

interface TicketRow {
  id: number;
  subject: string;
  status_name: string;
  status_color: string;
  priority: string;
  updated_at: string;
  customer_action_required: number | boolean;
}

/**
 * Portal ticket list (checkpoint-1). Fetches `/tickets` and renders the rows.
 * The detail view + comment thread + new-ticket form land in the next frontend
 * checkpoint; this proves the extension's page + API wiring end-to-end.
 */
export default function TicketList() {
  const [tickets, setTickets] = useState<TicketRow[] | null>(null);

  useEffect(() => {
    let alive = true;
    fetch("/tickets", { credentials: "include" })
      .then((r) => (r.ok ? r.json() : { tickets: [] }))
      .then((d) => alive && setTickets(d.tickets ?? []))
      .catch(() => alive && setTickets([]));
    return () => {
      alive = false;
    };
  }, []);

  if (tickets === null) {
    return <p>Wird geladen …</p>;
  }
  if (tickets.length === 0) {
    return <p>Keine Tickets vorhanden.</p>;
  }
  return (
    <ul className="ticket-list">
      {tickets.map((t) => (
        <li key={t.id} className="ticket-list__row" data-priority={t.priority}>
          <span className="ticket-list__subject">{t.subject}</span>
          <span className={`chip chip--${t.status_color}`}>{t.status_name}</span>
          {t.customer_action_required ? (
            <span className="chip chip--warning">Aktion erforderlich</span>
          ) : null}
        </li>
      ))}
    </ul>
  );
}
