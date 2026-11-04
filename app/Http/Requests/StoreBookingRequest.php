<?php

namespace App\Http\Requests;

use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreBookingRequest extends FormRequest
{
    private ?Room $cachedRoom = null;

    private const MIN_MINUTES = 15;

    private const MAX_MINUTES = 240;

    private const STEP_MINUTES = 15;

    private const WORK_DAY_START_HOUR = 9;

    private const WORK_DAY_END_HOUR = 20;

    public function authorize(): bool
    {
        // Маршрут уже закрыт auth:sanctum; отдельной авторизации нет.
        return true;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'room_id' => 'комната',
            'title' => 'название',
            'starts_at' => 'начало брони',
            'ends_at' => 'конец брони',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => 'Поле «:attribute» обязательно.',
            'integer' => 'Поле «:attribute» должно быть целым числом.',
            'exists' => 'Указанная «:attribute» не существует.',
            'string' => 'Поле «:attribute» должно быть строкой.',
            'max.string' => 'Поле «:attribute» не должно быть длиннее :max символов.',
            'date' => 'Поле «:attribute» должно быть датой в формате ISO 8601.',
            'after' => 'Поле «:attribute» должно быть позже начала брони.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            // Если базовые правила не прошли, даты могут не парситься — бизнес-проверки пропускаем.
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $this->validateDuration($v);
            $this->validateStep($v);
            $this->validateWorkingHours($v);
        });
    }

    /** Момент начала брони, нормализованный в UTC. */
    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->validated('starts_at'))->utc();
    }

    /** Момент конца брони, нормализованный в UTC. */
    public function endsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->validated('ends_at'))->utc();
    }

    /** Комната брони вместе с офисом (таймзона офиса нужна для проверки рабочих часов). */
    public function room(): Room
    {
        if ($this->cachedRoom instanceof Room) {
            return $this->cachedRoom;
        }

        $room = Room::query()->with('office')->find($this->validated('room_id'));

        assert($room instanceof Room);

        return $this->cachedRoom = $room;
    }

    private function validateDuration(Validator $v): void
    {
        $minutes = $this->startsAt()->diffInMinutes($this->endsAt());

        if ($minutes < self::MIN_MINUTES || $minutes > self::MAX_MINUTES) {
            $v->errors()->add(
                'ends_at',
                sprintf('Длительность брони должна быть от %d минут до %d часов.', self::MIN_MINUTES, self::MAX_MINUTES / 60),
            );
        }
    }

    private function validateStep(Validator $v): void
    {
        foreach ([$this->startsAtLocal(), $this->endsAtLocal()] as $moment) {
            if ($moment->minute % self::STEP_MINUTES !== 0 || $moment->second !== 0) {
                $v->errors()->add('starts_at', sprintf('Время начала и конца должны быть кратны %d минутам.', self::STEP_MINUTES));

                return;
            }
        }
    }

    private function validateWorkingHours(Validator $v): void
    {
        $start = $this->startsAtLocal();
        $end = $this->endsAtLocal();

        $dayStart = $start->startOfDay()->addHours(self::WORK_DAY_START_HOUR);
        $dayEnd = $start->startOfDay()->addHours(self::WORK_DAY_END_HOUR);

        if ($start < $dayStart || $end > $dayEnd) {
            $v->errors()->add(
                'starts_at',
                sprintf('Бронь должна целиком попадать в рабочее время %02d:00–%02d:00 по местному времени офиса.', self::WORK_DAY_START_HOUR, self::WORK_DAY_END_HOUR),
            );
        }
    }

    private function startsAtLocal(): CarbonImmutable
    {
        return $this->startsAt()->timezone($this->officeTimezone());
    }

    private function endsAtLocal(): CarbonImmutable
    {
        return $this->endsAt()->timezone($this->officeTimezone());
    }

    private function officeTimezone(): string
    {
        return $this->room()->office->timezone;
    }
}
