<?php
declare(strict_types=1);

namespace Tds\Ext\SupportTickets;

use Tds\Panel\Contract\Email;
use Tds\Panel\Contract\Mailer;

/**
 * Ticket notifications, sent through the CORE {@see Mailer} (no per-extension
 * SMTP). No-ops when the core mailer is unconfigured or a recipient is missing,
 * so the feature degrades to in-app only. The admin recipient is config
 * (`TICKET_ADMIN_EMAIL`); the customer recipient comes from the ticket's
 * `from_email` when present (portal customers without a stored email get no mail
 * until the customer-directory port lands).
 */
final class Notifier
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly string $adminEmail,
    ) {
    }

    public function newCustomerTicket(int $id, string $subject): void
    {
        $this->toAdmin(
            "Neues Support-Ticket #{$id}: {$subject}",
            "<p>Ein neues Support-Ticket wurde erstellt.</p><p><strong>#{$id}</strong> — "
                . htmlspecialchars($subject) . '</p>',
        );
    }

    public function newCustomerComment(int $id, string $subject): void
    {
        $this->toAdmin(
            "Neue Antwort zu Ticket #{$id}",
            "<p>Der Kunde hat auf Ticket <strong>#{$id}</strong> (" . htmlspecialchars($subject)
                . ') geantwortet.</p>',
        );
    }

    public function newOwnerComment(int $id, string $subject, ?string $customerEmail): void
    {
        if ($customerEmail === null || $customerEmail === '') {
            return;
        }
        $this->send(
            $customerEmail,
            "Antwort zu Ihrem Ticket #{$id}",
            "<p>Es gibt eine neue Antwort zu Ihrem Ticket <strong>#{$id}</strong> ("
                . htmlspecialchars($subject) . ').</p>',
        );
    }

    private function toAdmin(string $subject, string $html): void
    {
        if ($this->adminEmail !== '') {
            $this->send($this->adminEmail, $subject, $html);
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
