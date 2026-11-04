<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingStoreTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-11-06';

    public function test_creates_booking_and_normalizes_time_to_utc(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $room = $this->moscowRoom();

        $response = $this->postJson('/api/bookings', $this->payload($room, '10:00', '11:00'));

        $response->assertCreated()
            ->assertJsonPath('room_id', $room->id)
            ->assertJsonPath('user_id', $user->id)
            ->assertJsonPath('starts_at', '2026-11-06T07:00:00.000000Z');

        $this->assertDatabaseHas('bookings', [
            'room_id' => $room->id,
            'user_id' => $user->id,
            'starts_at' => '2026-11-06 07:00:00',
            'ends_at' => '2026-11-06 08:00:00',
        ]);
    }

    public function test_sets_author_from_token_ignoring_request_user_id(): void
    {
        $anna = User::factory()->create();
        $boris = User::factory()->create();
        Sanctum::actingAs($anna);

        $room = $this->moscowRoom();

        $this->postJson('/api/bookings', $this->payload($room, '10:00', '11:00', self::DATE, [
            'user_id' => $boris->id,
        ]))->assertCreated();

        $this->assertDatabaseHas('bookings', ['user_id' => $anna->id]);
        $this->assertDatabaseMissing('bookings', ['user_id' => $boris->id]);
    }

    public function test_rejects_overlapping_booking(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $room = $this->moscowRoom();
        $this->booking($room, '10:00', '11:00');

        $this->postJson('/api/bookings', $this->payload($room, '10:30', '11:30'))
            ->assertStatus(409)
            ->assertJsonPath('error', 'Комната занята');
    }

    public function test_allows_back_to_back_bookings(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $room = $this->moscowRoom();
        $this->booking($room, '10:00', '11:00');

        $this->postJson('/api/bookings', $this->payload($room, '11:00', '12:00'))->assertCreated();
        $this->postJson('/api/bookings', $this->payload($room, '09:00', '10:00'))->assertCreated();

        $this->assertSame(3, Booking::count());
    }

    public function test_rejects_booking_shorter_than_15_minutes(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', $this->payload($this->moscowRoom(), '10:00', '10:10'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at');
    }

    public function test_rejects_booking_longer_than_4_hours(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', $this->payload($this->moscowRoom(), '10:00', '15:00'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at');
    }

    public function test_allows_boundary_durations(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $room = $this->moscowRoom();

        $this->postJson('/api/bookings', $this->payload($room, '10:00', '10:15'))->assertCreated();
        $this->postJson('/api/bookings', $this->payload($room, '10:00', '14:00', '2026-11-07'))->assertCreated();
    }

    public function test_rejects_time_not_on_15_minute_step(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', $this->payload($this->moscowRoom(), '10:07', '11:07'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_rejects_booking_outside_working_hours(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $room = $this->moscowRoom();

        $this->postJson('/api/bookings', $this->payload($room, '08:00', '09:00'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');

        $this->postJson('/api/bookings', $this->payload($room, '19:00', '20:30'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_allows_booking_ending_exactly_at_20_00(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', $this->payload($this->moscowRoom(), '19:00', '20:00'))
            ->assertCreated();
    }

    public function test_working_hours_are_checked_in_office_timezone(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $room = $this->novosibirskRoom();

        // 09:00 по Новосибирску — это 02:00 UTC: наивная проверка по UTC отклонила бы бронь.
        $this->postJson('/api/bookings', $this->payload($room, '09:00', '10:00'))
            ->assertCreated()
            ->assertJsonPath('starts_at', '2026-11-06T02:00:00.000000Z');

        $this->postJson('/api/bookings', $this->payload($room, '08:00', '09:00'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_conflict_is_detected_across_different_offsets(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $room = $this->moscowRoom();

        // 07:00 UTC == 10:00 по Москве == 14:00 по Новосибирску — один и тот же момент.
        Booking::factory()->for($room)->create([
            'starts_at' => CarbonImmutable::parse('2026-11-06T07:00:00+00:00'),
            'ends_at' => CarbonImmutable::parse('2026-11-06T08:00:00+00:00'),
        ]);

        $this->postJson('/api/bookings', $this->payload($room, '10:00', '11:00'))
            ->assertStatus(409);

        $this->postJson('/api/bookings', $this->payload($room, '14:00', '15:00', '2026-11-06', [
            'starts_at' => '2026-11-06T14:00:00+07:00',
            'ends_at' => '2026-11-06T15:00:00+07:00',
        ]))->assertStatus(409);
    }

    public function test_validates_input_fields(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $room = $this->moscowRoom();

        $this->postJson('/api/bookings', [
            'room_id' => 999,
            'starts_at' => 'not-a-date',
            'ends_at' => '2026-11-06T11:00:00+03:00',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'room_id', 'starts_at']);
    }

    public function test_requires_authentication(): void
    {
        $this->postJson('/api/bookings', $this->payload($this->moscowRoom(), '10:00', '11:00'))
            ->assertUnauthorized();
    }

    private function moscowRoom(): Room
    {
        return Room::factory()->for(Office::factory())->create();
    }

    private function novosibirskRoom(): Room
    {
        return Room::factory()->for(Office::factory()->novosibirsk())->create();
    }

    /**
     * Тело запроса: время задаётся по местному времени офиса комнаты и уходит в ISO 8601.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Room $room, string $start, string $end, string $date = self::DATE, array $overrides = []): array
    {
        $timezone = $room->office->timezone;

        return array_merge([
            'room_id' => $room->id,
            'title' => 'Планёрка',
            'starts_at' => CarbonImmutable::parse("$date $start", $timezone)->toIso8601String(),
            'ends_at' => CarbonImmutable::parse("$date $end", $timezone)->toIso8601String(),
        ], $overrides);
    }

    private function booking(Room $room, string $start, string $end, string $date = self::DATE): Booking
    {
        $timezone = $room->office->timezone;

        return Booking::factory()->for($room)->create([
            'starts_at' => CarbonImmutable::parse("$date $start", $timezone)->utc(),
            'ends_at' => CarbonImmutable::parse("$date $end", $timezone)->utc(),
        ]);
    }
}
