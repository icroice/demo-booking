<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Защита от двойного бронирования одного слота при гонке одновременных запросов:
 * exclusion-ограничение PostgreSQL не даёт вставить пересекающиеся интервалы
 * одной комнаты даже если прикладная проверка конфликта прошла у двух запросов
 * одновременно. Границы tsrange по умолчанию «[)» — встречи «впритык» допустимы.
 *
 * Индекс (room_id, starts_at) обслуживает запрос проверки конфликта в store().
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::table('bookings', function (Blueprint $table): void {
            $table->index(['room_id', 'starts_at']);
        });

        DB::statement(
            'ALTER TABLE bookings ADD CONSTRAINT bookings_room_no_overlap '
            .'EXCLUDE USING gist (room_id WITH =, tsrange(starts_at, ends_at) WITH &&)',
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_room_no_overlap');

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex(['room_id', 'starts_at']);
        });
    }
};
