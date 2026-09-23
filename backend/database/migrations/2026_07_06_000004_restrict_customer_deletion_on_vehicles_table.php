<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** 此段說明相鄰程式碼的用途與預期行為。 */
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
                // 此段說明相鄰程式碼的用途與預期行為。
                $table->dropForeign($existing['name'] ?? [$column]);
            }

            $foreign = $table->foreign($column)->references('id')->on('customers');

            $onDelete === 'restrict' ? $foreign->restrictOnDelete() : $foreign->nullOnDelete();
        });
    }
};
