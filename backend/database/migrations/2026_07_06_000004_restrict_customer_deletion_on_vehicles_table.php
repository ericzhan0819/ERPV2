<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Supersedes the nullOnDelete() FKs added in
     * 2026_07_06_000003_add_customer_ids_to_vehicles_table: a customer with linked
     * vehicles must never be deletable, even silently. nullOnDelete would instead let
     * a customer delete succeed and quietly clear the vehicle's link, losing the
     * relationship. This is a forward-only migration (not an edit to the earlier one)
     * so it actually reruns the constraint change on any database that already
     * applied 2026_07_06_000003 before this fix existed.
     *
     * Each column's drop+recreate is driven by the FK's *current* on_delete rule
     * (read back from the database, not assumed) and skipped entirely once it
     * already matches the target. MySQL/MariaDB run each Schema::table() command as
     * its own auto-committing ALTER TABLE statement — there is no single-statement
     * atomicity to rely on — so if this migration is interrupted partway (one column
     * changed, the other not yet), simply re-running `migrate` finds each column
     * already in (or not yet in) the desired state and finishes the remaining work
     * instead of failing on an FK that was already dropped/added by the previous
     * attempt.
     */
    public function up(): void
    {
        $this->setOnDelete('seller_customer_id', 'restrict');
        $this->setOnDelete('buyer_customer_id', 'restrict');
    }

    public function down(): void
    {
        $this->setOnDelete('seller_customer_id', 'set null');
        $this->setOnDelete('buyer_customer_id', 'set null');
    }

    private function setOnDelete(string $column, string $onDelete): void
    {
        $existing = collect(Schema::getForeignKeys('vehicles'))
            ->first(fn (array $foreignKey) => $foreignKey['columns'] === [$column] && $foreignKey['foreign_table'] === 'customers');

        if ($existing && $existing['on_delete'] === $onDelete) {
            return;
        }

        Schema::table('vehicles', function (Blueprint $table) use ($column, $onDelete, $existing) {
            if ($existing) {
                // Drop by the constraint's actual discovered name, not Laravel's
                // conventional "{table}_{column}_foreign" derived from the column —
                // a database whose FK was created under a different name (a legacy
                // migration, a manual DBA change) would otherwise make dropForeign()
                // target a constraint that doesn't exist and abort the migration.
                // SQLite foreign keys are always unnamed (getForeignKeys() reports
                // 'name' => null there), so fall back to the column-array form,
                // which SQLite's grammar handles via a full table rebuild anyway.
                $table->dropForeign($existing['name'] ?? [$column]);
            }

            $foreign = $table->foreign($column)->references('id')->on('customers');

            $onDelete === 'restrict' ? $foreign->restrictOnDelete() : $foreign->nullOnDelete();
        });
    }
};
