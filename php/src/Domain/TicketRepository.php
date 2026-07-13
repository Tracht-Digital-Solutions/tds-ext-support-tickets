<?php
declare(strict_types=1);

namespace Tds\Ext\SupportTickets\Domain;

use PDO;

/**
 * Ticket data access (ported from tds-customer-api's TicketRepository, trimmed
 * to the checkpoint-1 surface: statuses, customer + admin list/get/create,
 * comments, open counts). Uses the core's shared PDO. Customer scoping is by
 * `customer_id` (= the JWT active company); a null customer_id ticket is
 * admin-only. Status visibility to customers is resolved here so a
 * non-`visible_to_customer` status shows a neutral fallback in the portal.
 */
final class TicketRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function statuses(): array
    {
        return $this->pdo->query(
            'SELECT id, name, color, sort_order, visible_to_customer, is_terminal, is_default
             FROM ticket_status ORDER BY sort_order, id'
        )->fetchAll();
    }

    public function defaultStatusId(): int
    {
        $id = $this->pdo->query(
            'SELECT id FROM ticket_status ORDER BY is_default DESC, sort_order, id LIMIT 1'
        )->fetchColumn();
        if ($id === false) {
            throw new \RuntimeException('No ticket_status configured');
        }
        return (int) $id;
    }

    /**
     * Tickets for a customer (portal view). status label is masked to a neutral
     * fallback when the status is not visible_to_customer.
     *
     * @return list<array<string,mixed>>
     */
    public function listForCustomer(int $customerId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.id, t.subject, t.priority, t.type, t.updated_at, t.customer_action_required,
                    s.is_terminal,
                    CASE WHEN s.visible_to_customer = 1 THEN s.name ELSE :fallback END AS status_name,
                    CASE WHEN s.visible_to_customer = 1 THEN s.color ELSE :fallbackColor END AS status_color
             FROM ticket t JOIN ticket_status s ON s.id = t.status_id
             WHERE t.customer_id = :cid
             ORDER BY t.updated_at DESC'
        );
        $stmt->execute([':cid' => $customerId, ':fallback' => 'In Bearbeitung', ':fallbackColor' => 'info']);
        return $stmt->fetchAll();
    }

    public function openCountForCustomer(int $customerId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ticket t JOIN ticket_status s ON s.id = t.status_id
             WHERE t.customer_id = :cid AND s.is_terminal = 0'
        );
        $stmt->execute([':cid' => $customerId]);
        return (int) $stmt->fetchColumn();
    }

    public function openCountAll(): int
    {
        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ticket t JOIN ticket_status s ON s.id = t.status_id WHERE s.is_terminal = 0'
        )->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    public function adminList(): array
    {
        return $this->pdo->query(
            'SELECT t.id, t.customer_id, t.subject, t.priority, t.type, t.source, t.assignee_user_id,
                    t.customer_action_required, t.from_name, t.from_email, t.updated_at,
                    s.name AS status_name, s.color AS status_color, s.is_terminal
             FROM ticket t JOIN ticket_status s ON s.id = t.status_id
             ORDER BY t.updated_at DESC'
        )->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*, s.name AS status_name, s.color AS status_color,
                    s.visible_to_customer, s.is_terminal
             FROM ticket t JOIN ticket_status s ON s.id = t.status_id WHERE t.id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function comments(int $ticketId, bool $includeInternal): array
    {
        $sql = 'SELECT id, author_type, author_user_id, body, is_internal, created_at, edited_at
                FROM ticket_comment WHERE ticket_id = :tid';
        if (!$includeInternal) {
            $sql .= ' AND is_internal = 0';
        }
        $sql .= ' ORDER BY created_at, id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':tid' => $ticketId]);
        return $stmt->fetchAll();
    }

    /**
     * Open a portal ticket for a customer. Starts in the default status,
     * created_by_type=customer.
     */
    public function createForCustomer(
        int $customerId,
        ?int $userId,
        string $subject,
        string $description,
        string $type,
        string $priority,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ticket (customer_id, status_id, subject, description, priority, type,
                                 created_by_type, created_by_user_id, source)
             VALUES (:cid, :sid, :subject, :description, :priority, :type, \'customer\', :uid, \'portal\')'
        );
        $stmt->execute([
            ':cid' => $customerId,
            ':sid' => $this->defaultStatusId(),
            ':subject' => $subject,
            ':description' => $description,
            ':priority' => $priority,
            ':type' => $type,
            ':uid' => $userId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function addComment(
        int $ticketId,
        string $authorType,
        ?int $userId,
        string $body,
        bool $isInternal,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ticket_comment (ticket_id, author_type, author_user_id, body, is_internal)
             VALUES (:tid, :atype, :uid, :body, :internal)'
        );
        $stmt->execute([
            ':tid' => $ticketId,
            ':atype' => $authorType,
            ':uid' => $userId,
            ':body' => $body,
            ':internal' => $isInternal ? 1 : 0,
        ]);
        $this->touch($ticketId);
        return (int) $this->pdo->lastInsertId();
    }

    /** Clear the "waiting for customer" prompt (a customer reply resolves it). */
    public function clearCustomerAction(int $ticketId): void
    {
        $this->pdo->prepare('UPDATE ticket SET customer_action_required = 0 WHERE id = :id')
            ->execute([':id' => $ticketId]);
    }

    /**
     * Admin triage update. Only the provided fields change.
     *
     * @param array<string,mixed> $fields status_id/assignee_user_id/priority/type/
     *                                     customer_action_required/customer_action_note
     */
    public function updateAdmin(int $id, array $fields): void
    {
        $allowed = ['status_id', 'assignee_user_id', 'priority', 'type', 'customer_action_required', 'customer_action_note'];
        $sets = [];
        $params = [':id' => $id];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $fields)) {
                $sets[] = "{$col} = :{$col}";
                $params[":{$col}"] = $fields[$col];
            }
        }
        if ($sets === []) {
            return;
        }
        $this->pdo->prepare('UPDATE ticket SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
    }

    private function touch(int $ticketId): void
    {
        $this->pdo->prepare('UPDATE ticket SET updated_at = NOW() WHERE id = :id')
            ->execute([':id' => $ticketId]);
    }
}
