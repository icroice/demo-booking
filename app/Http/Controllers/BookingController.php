<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Room;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class BookingController extends Controller
{
    public function index(Request $request): LengthAwarePaginator
    {
        return Booking::where('user_id', $request->user()->id)
            ->orderByRaw('starts_at DESC')
            ->paginate(20);
    }

    public function store(StoreBookingRequest $request): JsonResponse
    {
        // Даты нормализованы в UTC в StoreBookingRequest; сравнение строгое —
        // встречи «впритык» (оканчивается в 11:00, начинается в 11:00) конфликтом не считаются.
        $conflict = Booking::query()
            ->where('room_id', $request->room()->id)
            ->where('starts_at', '<', $request->endsAt())
            ->where('ends_at', '>', $request->startsAt())
            ->exists();

        if ($conflict) {
            return response()->json(['error' => 'Комната занята'], 409);
        }

        try {
            $booking = Booking::create([
                'room_id' => $request->room()->id,
                'user_id' => $request->user()->id,
                'title' => $request->validated('title'),
                'starts_at' => $request->startsAt(),
                'ends_at' => $request->endsAt(),
            ]);
        } catch (QueryException $e) {
            // 23P01 — exclusion_violation: слот заняли между проверкой и вставкой (гонка).
            if ($e->getCode() === '23P01') {
                return response()->json(['error' => 'Комната занята'], 409);
            }

            throw $e;
        }

        Log::channel('bookings')->info('Booking created', ['booking_id' => $booking->id]);

        return response()->json($booking, 201);
    }

    public function destroy(Request $request, Booking $booking): Response
    {
        abort_unless($booking->user_id === $request->user()->id, 403);

        $booking->delete();

        return response()->noContent();
    }

    public function today(Room $room): Collection
    {
        return $room->bookings()
            ->whereDate('starts_at', today())
            ->get()
            ->map(fn ($b) => [
                'time' => $b->starts_at.' – '.$b->ends_at,
                'author' => $b->user->name,
            ]);
    }
}
