<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Key/value store for ticket-system settings — the email-notification toggles
 * the admin controls. Generic table (no bespoke columns) so future settings need
 * no migration; values are 'true'/'false' strings. Seeds the toggles OFF
 * (email is opt-in and also no-ops when the core Mailer is unconfigured).
 *
 * String PK is explicit NOT NULL: MySQL 8 rejects a nullable PRIMARY KEY (1171);
 * prod is MySQL 8, stricter than dev/CI MariaDB. Module-prefixed class name.
 */
final class CreateSupportTicketsSetting extends AbstractMigration
{
    public function up(): void
    {
        $this->table('ticket_setting', [
            'id' => false,
            'primary_key' => 'setting_key',
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
        ])
            ->addColumn('setting_key', 'string', ['limit' => 60, 'null' => false])
            ->addColumn('setting_value', 'string', ['limit' => 255])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->create();

        $this->table('ticket_setting')->insert([
            ['setting_key' => 'notify_admin_on_new',      'setting_value' => 'false'],
            ['setting_key' => 'notify_customer_on_status', 'setting_value' => 'false'],
            ['setting_key' => 'notify_customer_on_reply',  'setting_value' => 'false'],
        ])->save();
    }

    public function down(): void
    {
        $this->table('ticket_setting')->drop()->save();
    }
}
