<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Гонка одновременных запросов: прикладная проверка в store() не атомарна,
 * поэтому пересечения одной комнаты запрещены на уровне БД — exclusion-ограничение
 * bookings_room_no_overlap. Эти тесты вставляют брони в обход контроллера.
 */
class BookingOverlapConstraintTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_rejects_overlapping_intervals_for_same_room(): void
    {
        $room = Room::factory()->for(Office::factory())->create();

        Booking::factory()->for($room)->create([
            'starts_at' => '2026-11-06 07:00:00',
            'ends_at' => '2026-11-06 08:00:00',
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('bookings_room_no_overlap');

        Booking::factory()->for($room)->create([
            'starts_at' => '2026-11-06 07:30:00',
            'ends_at' => '2026-11-06 08:30:00',
        ]);
    }

    public function test_database_allows_adjacent_intervals_and_other_rooms(): void
    {
        $room = Room::factory()->for(Office::factory())->create();
        $otherRoom = Room::factory()->for(Office::factory())->create();

        Booking::factory()->for($room)->create([
            'starts_at' => '2026-11-06 07:00:00',
            'ends_at' => '2026-11-06 08:00:00',
        ]);

        // «Впритык» — не пересечение (границы tsrange «[)»).
        Booking::factory()->for($room)->create([
            'starts_at' => '2026-11-06 08:00:00',
            'ends_at' => '2026-11-06 09:00:00',
        ]);

        // Пересечение в другой комнате — допустимо.
        Booking::factory()->for($otherRoom)->create([
            'starts_at' => '2026-11-06 07:30:00',
            'ends_at' => '2026-11-06 08:30:00',
        ]);

        $this->assertSame(3, Booking::count());
    }

    public function test_request_returns_409_when_slot_taken_between_check_and_insert(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $room = Room::factory()->for(Office::factory())->create();

        // Гонка: конкурентная бронь попадает в БД между проверкой exists() в store()
        // и вставкой. Хук внедряет её ровно в момент первого insert в bookings.
        $injected = false;
        DB::beforeExecuting(function (string $query) use (&$injected, $room): void {
            if (! $injected && str_contains($query, 'insert into "bookings"')) {
                $injected = true;

                Booking::factory()->for($room)->create([
                    'starts_at' => '2026-11-06 07:00:00',
                    'ends_at' => '2026-11-06 08:00:00',
                ]);
            }
        });

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-06T10:00:00+03:00',
            'ends_at' => '2026-11-06T11:00:00+03:00',
        ])->assertStatus(409)
            ->assertJsonPath('error', 'Комната занята');

        // Подсчитать брони после нельзя: нарушение ограничения абортирует транзакцию
        // RefreshDatabase (25P02) — сам 409 и есть проверка, что вставка не прошла.
    }
}
