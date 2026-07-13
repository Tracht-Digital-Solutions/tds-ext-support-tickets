<?php
declare(strict_types=1);

namespace Tds\Ext\SupportTickets\Domain;

use PDO;

/**
 * Reads/writes the ticket_setting key/value table — the email-notification
 * toggles the admin controls. Unknown keys are ignored on write so the surface
 * stays closed. (Ported from tds-customer-api's TicketSettings.)
 */
final class TicketSettings
{
    public const KEYS = [
        'notify_admin_on_new',
        'notify_customer_on_status',
        'notify_customer_on_reply',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function enabled(string $key): bool
    {
        $stmt = $this->pdo->prepare('SELECT setting_value FROM ticket_setting WHERE setting_key = :k LIMIT 1');
        $stmt->execute([':k' => $key]);
        return $stmt->fetchColumn() === 'true';
    }

    /** @return array<string,bool> */
    public function all(): array
    {
        $out = array_fill_keys(self::KEYS, false);
        foreach ($this->pdo->query('SELECT setting_key, setting_value FROM ticket_setting')->fetchAll() as $row) {
            $key = (string) $row['setting_key'];
            if (in_array($key, self::KEYS, true)) {
                $out[$key] = $row['setting_value'] === 'true';
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $values */
    public function put(array $values): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ticket_setting (setting_key, setting_value) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = :v2'
        );
        foreach ($values as $key => $on) {
            if (!in_array($key, self::KEYS, true)) {
                continue;
            }
            $val = $on ? 'true' : 'false';
            $stmt->execute([':k' => $key, ':v' => $val, ':v2' => $val]);
        }
    }
}
