<?php
declare(strict_types=1);

namespace Tds\Ext\SupportTickets;

use Tds\Ext\SupportTickets\Domain\TicketSettings;
use Tds\Frontend\Contract\Email;
use Tds\Frontend\Contract\Mailer;

/**
 * Ticket notifications through the CORE {@see Mailer}, each gated by an admin
 * toggle in {@see TicketSettings} (and no-op when the core mailer is
 * unconfigured or a recipient is missing). Three events, matching the toggle
 * registry:
 *   - new ticket      → admin      (notify_admin_on_new)
 *   - owner reply     → customer   (notify_customer_on_reply)
 *   - status change   → customer   (notify_customer_on_status)
 *
 * The admin recipient is `TICKET_ADMIN_EMAIL`; the customer recipient is the
 * ticket's `from_email` (set for contact/email tickets). Portal customers
 * without a stored email get no mail until the customer-directory port lands.
 */
final class Notifier
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly TicketSettings $settings,
        private readonly string $adminEmail,
    ) {
    }

    public function onNewTicket(int $id, string $subject): void
    {
        if (!$this->settings->enabled('notify_admin_on_new') || $this->adminEmail === '') {
            return;
        }
        $this->send(
            $this->adminEmail,
            "Neues Support-Ticket #{$id}: {$subject}",
            "<p>Ein neues Support-Ticket wurde erstellt.</p><p><strong>#{$id}</strong> — "
                . htmlspecialchars($subject) . '</p>',
        );
    }

    public function onOwnerReply(int $id, string $subject, ?string $customerEmail): void
    {
        if (!$this->settings->enabled('notify_customer_on_reply')) {
            return;
        }
        $this->toCustomer(
            $customerEmail,
            "Antwort zu Ihrem Ticket #{$id}",
            "<p>Es gibt eine neue Antwort zu Ihrem Ticket <strong>#{$id}</strong> ("
                . htmlspecialchars($subject) . ').</p>',
        );
    }

    public function onStatusChange(int $id, string $subject, ?string $customerEmail): void
    {
        if (!$this->settings->enabled('notify_customer_on_status')) {
            return;
        }
        $this->toCustomer(
            $customerEmail,
            "Statusänderung zu Ihrem Ticket #{$id}",
            "<p>Der Status Ihres Tickets <strong>#{$id}</strong> (" . htmlspecialchars($subject)
                . ') wurde aktualisiert.</p>',
        );
    }

    private function toCustomer(?string $email, string $subject, string $html): void
    {
        if ($email !== null && $email !== '') {
            $this->send($email, $subject, $html);
        }
    }

    private function send(string $to, string $subject, string $html): void
    {
        if (!$this->mailer->isConfigured()) {
            return;
        }
        $this->mailer->send(new Email($to, '', $subject, $html));
    }
}
