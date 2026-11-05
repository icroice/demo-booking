<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingDestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_can_cancel_own_booking(): void
    {
        $anna = User::factory()->create();
        Sanctum::actingAs($anna);

        $booking = Booking::factory()->for($anna)->create([
            'starts_at' => CarbonImmutable::parse('2026-11-06T07:00:00+00:00'),
            'ends_at' => CarbonImmutable::parse('2026-11-06T08:00:00+00:00'),
        ]);

        $this->deleteJson("/api/bookings/{$booking->id}")->assertNoContent();

        $this->assertDatabaseMissing('bookings', ['id' => $booking->id]);
    }

    public function test_stranger_cannot_cancel_another_users_booking(): void
    {
        $anna = User::factory()->create();
        $boris = User::factory()->create();
        Sanctum::actingAs($boris);

        $booking = Booking::factory()->for($anna)->create();

        $this->deleteJson("/api/bookings/{$booking->id}")->assertForbidden();

        $this->assertDatabaseHas('bookings', ['id' => $booking->id]);
    }

    public function test_cancel_missing_booking_returns_404(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson('/api/bookings/999')->assertNotFound();
    }

    public function test_requires_authentication(): void
    {
        $booking = Booking::factory()->create();

        $this->deleteJson("/api/bookings/{$booking->id}")->assertUnauthorized();
    }
}
