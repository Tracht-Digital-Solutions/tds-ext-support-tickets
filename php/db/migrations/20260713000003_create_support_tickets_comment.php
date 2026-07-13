<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A comment on a ticket — the customer↔owner conversation (ported from
 * tds-customer-api). `author_type` is derived from the UserContext (`owner` when
 * admin, else `customer`), never trusted from the client. `is_internal` marks an
 * admin-only note never returned to a customer principal. `email_message_id`
 * backs IMAP reply threading.
 */
final class CreateSupportTicketsComment extends AbstractMigration
{
    public function change(): void
    {
        $this->table('ticket_comment', [
            'id' => true,
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
        ])
            ->addColumn('ticket_id', 'integer', ['signed' => false])
            ->addColumn('author_type', 'enum', ['values' => ['customer', 'owner']])
            ->addColumn('author_user_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('body', 'text')
            ->addColumn('is_internal', 'boolean', ['default' => false])
            ->addColumn('email_message_id', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('edited_at', 'datetime', ['null' => true])
            ->addIndex(['ticket_id', 'created_at'], ['name' => 'idx_ticket_comment_created'])
            ->addForeignKey('ticket_id', 'ticket', 'id', ['delete' => 'CASCADE'])
            ->create();
    }
}
