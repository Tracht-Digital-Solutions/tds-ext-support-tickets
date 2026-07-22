<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Admin-configurable ticket status registry (ported from tds-customer-api).
 * Edited at runtime: label, chip colour tone, order, whether the customer sees
 * the real label (`visible_to_customer`) or a neutral fallback, whether it
 * closes the ticket (`is_terminal`), and which status new tickets start in
 * (`is_default`). Seeds the five defaults so a fresh install has a workflow.
 *
 * Class name is module-prefixed (SupportTickets*): the base's in-process
 * auto-migrator loads every module's migrations into ONE process, so a reused
 * class name is a fatal redeclaration.
 */
final class CreateSupportTicketsStatus extends AbstractMigration
{
    public function up(): void
    {
        $this->table('ticket_status', [
            'id' => true,
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
        ])
            ->addColumn('name', 'string', ['limit' => 80])
            ->addColumn('color', 'string', ['limit' => 20, 'default' => 'neutral'])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('visible_to_customer', 'boolean', ['default' => true])
            ->addColumn('is_terminal', 'boolean', ['default' => false])
            ->addColumn('is_default', 'boolean', ['default' => false])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['sort_order'], ['name' => 'idx_ticket_status_sort'])
            ->create();

        $this->table('ticket_status')->insert([
            ['name' => 'Offen',            'color' => 'warning', 'sort_order' => 10, 'visible_to_customer' => 1, 'is_terminal' => 0, 'is_default' => 1],
            ['name' => 'In Bearbeitung',   'color' => 'info',    'sort_order' => 20, 'visible_to_customer' => 1, 'is_terminal' => 0, 'is_default' => 0],
            ['name' => 'Warten auf Kunde', 'color' => 'warning', 'sort_order' => 30, 'visible_to_customer' => 1, 'is_terminal' => 0, 'is_default' => 0],
            ['name' => 'Intern prüfen',    'color' => 'neutral', 'sort_order' => 40, 'visible_to_customer' => 0, 'is_terminal' => 0, 'is_default' => 0],
            ['name' => 'Gelöst',           'color' => 'success', 'sort_order' => 50, 'visible_to_customer' => 1, 'is_terminal' => 1, 'is_default' => 0],
        ])->save();
    }

    public function down(): void
    {
        $this->table('ticket_status')->drop()->save();
    }
}
