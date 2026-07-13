<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A support ticket (consolidated from tds-customer-api's ticket + the later
 * email/contact field migrations). Differences from customer-api, because this
 * extension owns its own DB:
 *
 *  - customer_id / project_id carry **no foreign key** — the customer + project
 *    entities live in another domain (auth/customer management), so these are
 *    loose unsigned refs. customer_id = the JWT's active company/tenant id, and
 *    is NULLABLE for contact-form tickets with no owning customer.
 *  - assignee/created_by reference tds-auth-api app_user.id (no FK, other DB).
 *  - status_id keeps its FK to the same-DB ticket_status registry (RESTRICT).
 *
 * `customer_action_required` + `_note` are the admin-set "waiting for customer"
 * prompt shown in the portal. `source`/`from_*`/`email_message_id` back the
 * IMAP + contact-form ingest channels. MySQL-8-safe (prod is MySQL 8).
 */
final class CreateSupportTicketsTicket extends AbstractMigration
{
    public function change(): void
    {
        $this->table('ticket', [
            'id' => true,
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
        ])
            ->addColumn('customer_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('project_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('status_id', 'integer', ['signed' => false])
            ->addColumn('subject', 'string', ['limit' => 200])
            ->addColumn('description', 'text')
            ->addColumn('priority', 'enum', [
                'values' => ['low', 'normal', 'high', 'urgent'],
                'default' => 'normal',
            ])
            ->addColumn('type', 'enum', [
                'values' => ['question', 'bug', 'feature', 'other', 'contact'],
                'default' => 'question',
            ])
            ->addColumn('assignee_user_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('created_by_type', 'enum', ['values' => ['customer', 'owner']])
            ->addColumn('created_by_user_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('source', 'enum', ['values' => ['portal', 'email', 'contact'], 'default' => 'portal'])
            ->addColumn('email_message_id', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('customer_action_required', 'boolean', ['default' => false])
            ->addColumn('customer_action_note', 'text', ['null' => true])
            ->addColumn('from_name', 'string', ['limit' => 200, 'null' => true])
            ->addColumn('from_email', 'string', ['limit' => 254, 'null' => true])
            ->addColumn('from_company', 'string', ['limit' => 200, 'null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addColumn('closed_at', 'datetime', ['null' => true])
            ->addIndex(['customer_id', 'updated_at'], ['name' => 'idx_ticket_customer_updated'])
            ->addIndex(['status_id'], ['name' => 'idx_ticket_status'])
            ->addIndex(['assignee_user_id'], ['name' => 'idx_ticket_assignee'])
            ->addIndex(['email_message_id'], ['name' => 'idx_ticket_email_message_id'])
            ->addIndex(['from_email'], ['name' => 'idx_ticket_from_email'])
            ->addForeignKey('status_id', 'ticket_status', 'id', ['delete' => 'RESTRICT'])
            ->create();
    }
}
