<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use App\Services\Bitrix24Client;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    private function bookingApiTimezone(): string
    {
        return (string) config('app.timezone', 'Asia/Dushanbe');
    }

    private function parseInputDateTimeToUtc(string $value): Carbon
    {
        $hasOffset = (bool) preg_match('/(Z|[+\-]\d{2}:\d{2})$/', $value);

        $date = $hasOffset
            ? Carbon::parse($value)
            : Carbon::parse($value, $this->bookingApiTimezone());

        return $date->setTimezone('UTC');
    }

    private function normalizeFilterBoundaryToUtc(?string $value, string $boundary): ?string
    {
        if (empty($value)) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $value .= $boundary === 'start' ? ' 00:00:00' : ' 23:59:59';
        }

        try {
            return $this->parseInputDateTimeToUtc($value)->toDateTimeString();
        } catch (\Throwable $e) {
            \Log::warning('Failed to parse booking date filter', [
                'value' => $value,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function formatUtcToApiIso8601(mixed $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        return Carbon::parse($value, 'UTC')
            ->setTimezone($this->bookingApiTimezone())
            ->toIso8601String();
    }

    private function transformBookingForResponse(Booking $booking): Booking
    {
        $booking->start_time = $this->formatUtcToApiIso8601($booking->start_time);
        $booking->end_time = $this->formatUtcToApiIso8601($booking->end_time);

        return $booking;
    }

    private function applyBranchAccessForAgents(Request $request, $query, string $agentColumn = 'agent_id'): void
    {
        $authUser = $request->user();
        $authUser?->loadMissing('role');
        $roleSlug = $authUser->role->slug ?? null;

        // Явный фильтр по филиалу (для админских отчётов)
        if ($request->filled('branch_id')) {
            $branchIds = array_values(array_filter(array_map('trim', explode(',', (string)$request->input('branch_id'))), fn($v) => $v !== ''));
            if (!empty($branchIds)) {
                $query->whereIn($agentColumn, User::query()->whereIn('branch_id', $branchIds)->select('id'));
            }
        }

        // Принудительное ограничение для РОП: только агенты своего филиала
        if ($roleSlug === 'rop') {
            if (empty($authUser->branch_id)) {
                $query->whereRaw('1 = 0');
                return;
            }

            $query->whereIn($agentColumn, User::query()->where('branch_id', $authUser->branch_id)->select('id'));
        }
    }

    public function index(Request $request)
    {
        $q = Booking::query()->with(['property', 'agent', 'client']);

        // from / to: convert to UTC for DB comparison
        $fromRaw = $request->input('from') ?? $request->input('date_from');
        $toRaw   = $request->input('to')   ?? $request->input('date_to');

        $fromDb = $this->normalizeFilterBoundaryToUtc($fromRaw, 'start');
        $toDb   = $this->normalizeFilterBoundaryToUtc($toRaw, 'end');

        if ($fromDb) {
            $q->where('start_time', '>=', $fromDb);
        }
        if ($toDb) {
            $q->where('start_time', '<=', $toDb);
        }

        if ($request->filled('agent_id')) {
            $q->where('agent_id', $request->integer('agent_id'));
        }
        if ($request->filled('property_id')) {
            $q->where('property_id', $request->integer('property_id'));
        }

        $bookings = $q->orderBy('start_time')->get();
        $bookings->transform(fn (Booking $booking) => $this->transformBookingForResponse($booking));

        return $bookings;
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'property_id' => 'required|exists:properties,id',
            'agent_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(function ($q) {
                    $q->where('status', 'active')
                        ->whereIn('role_id', Role::query()->where('slug', 'agent')->select('id'));
                }),
            ],
            'client_id' => 'nullable|exists:users,id',
            'start_time' => 'required|date',
            'end_time' => 'required|date',
            'note' => 'nullable|string',
            'client_name' => 'required|string',
            'client_phone' => 'required|string',
            'deal_id' => 'nullable|integer',
            'contact_id' => 'nullable|integer',
            'place' => 'nullable|string',
            'sync_to_b24' => 'sometimes|boolean',
        ]);

        $startCarbon = $this->parseInputDateTimeToUtc($validated['start_time']);
        $endCarbon   = $this->parseInputDateTimeToUtc($validated['end_time']);

        if ($endCarbon->lessThanOrEqualTo($startCarbon)) {
            throw ValidationException::withMessages([
                'end_time' => ['The end time must be a date after start time.'],
            ]);
        }

        // Сохраняем в формате, который БД корректно принимает
        $validated['start_time'] = $startCarbon->toDateTimeString(); // "Y-m-d H:i:s" (UTC)
        $validated['end_time']   = $endCarbon->toDateTimeString();

        $booking = Booking::create($validated);

        // Ensure relations are available for subject/description
        $booking->load(['property', 'agent', 'client']);
        $this->transformBookingForResponse($booking);

        $bitrixResult = null;
        if ($request->boolean('sync_to_b24')) {
            try {
                /** @var Bitrix24Client $b24 */
                $b24 = app(Bitrix24Client::class); // throws if not bound

                $subject = 'Показ объекта';
                if ($booking->relationLoaded('property') && $booking->property) {
                    $subject .= ': ' . $booking->property->title;
                }

                $description = collect([
                    'Объект ID: ' . $booking->property_id,
                    $booking->note ? 'Заметка: ' . $booking->note : null,
                    $booking->client_name ? 'Клиент: ' . $booking->client_name : null,
                    $booking->client_phone ? 'Телефон: ' . $booking->client_phone : null,
                    ($validated['place'] ?? null) ? 'Место: ' . $validated['place'] : null,
                ])->filter()->implode("\n");

                // Bitrix обычно ожидает строки ISO; используем UTC ISO строки
                $fields = [
                    'TYPE_ID' => 2,
                    'SUBJECT' => $subject,
                    'DESCRIPTION' => $description,
                    'START_TIME' => $startCarbon->toIso8601String(),
                    'END_TIME' => $endCarbon->toIso8601String(),
                    'OWNER_TYPE_ID' => 2, // Deal
                    'OWNER_ID' => Arr::get($validated, 'deal_id'),
                    'COMMUNICATIONS' => array_values(array_filter([
                        Arr::get($validated, 'contact_id') ? ['ENTITY_ID' => (int) $validated['contact_id'], 'ENTITY_TYPE_ID' => 3] : null, // 3 = Contact
                    ])),
                    'LOCATION' => $validated['place'] ?? null,
                    'RESPONSIBLE_ID' => $validated['agent_id'] ?? null,
                ];

                // Remove nulls and empty strings
                $fields = array_filter($fields, fn($v) => $v !== null && $v !== '');

                $bitrixResult = $b24->activityAdd(['fields' => $fields]);
            } catch (\Throwable $e) {
                $bitrixResult = ['error' => $e->getMessage()];
            }
        }

        return response()->json([
            'booking' => $booking,
            'bitrix' => $bitrixResult,
        ], 201);
    }

    public function show($id)
    {
        $booking = Booking::with(['property', 'agent', 'client'])->findOrFail($id);
        $this->transformBookingForResponse($booking);

        return response()->json($booking);
    }

    public function update(Request $request, $id)
    {
        $booking = Booking::findOrFail($id);

        $validated = $request->validate([
            'start_time' => 'sometimes|date',
            'end_time' => 'sometimes|date',
            'note' => 'nullable|string',
            'agent_id' => [
                'sometimes',
                'integer',
                Rule::exists('users', 'id')->where(function ($q) {
                    $q->where('status', 'active')
                        ->whereIn('role_id', Role::query()->where('slug', 'agent')->select('id'));
                }),
            ],
            'client_name' => 'sometimes|string',
            'client_phone' => 'sometimes|string',
        ]);

        $authUser = $request->user();
        $userRole = $authUser->role->slug ?? null;

        // permission: only admin or the booking's agent can update
        if (!($authUser && $userRole === 'admin') && $booking->agent_id !== ($authUser->id ?? null)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $startCarbon = isset($validated['start_time'])
            ? $this->parseInputDateTimeToUtc($validated['start_time'])
            : Carbon::parse($booking->start_time, 'UTC');

        $endCarbon = isset($validated['end_time'])
            ? $this->parseInputDateTimeToUtc($validated['end_time'])
            : Carbon::parse($booking->end_time, 'UTC');

        if ($endCarbon->lessThanOrEqualTo($startCarbon)) {
            throw ValidationException::withMessages([
                'end_time' => ['The end time must be a date after start time.'],
            ]);
        }

        if (isset($validated['start_time'])) {
            $booking->start_time = $startCarbon->toDateTimeString();
        }
        if (isset($validated['end_time'])) {
            $booking->end_time = $endCarbon->toDateTimeString();
        }

        if (array_key_exists('note', $validated)) $booking->note = $validated['note'];
        if (array_key_exists('client_name', $validated)) $booking->client_name = $validated['client_name'];
        if (array_key_exists('client_phone', $validated)) $booking->client_phone = $validated['client_phone'];
        if (array_key_exists('agent_id', $validated) && $userRole === 'admin') $booking->agent_id = $validated['agent_id'];

        $booking->save();

        $booking->load(['property', 'agent', 'client']);
        $this->transformBookingForResponse($booking);

        return response()->json($booking);
    }

    public function agentsReport(Request $request)
    {
        try {
            $fromRaw = $request->input('from') ?? $request->input('date_from');
            $toRaw   = $request->input('to')   ?? $request->input('date_to');

            $from = $this->normalizeFilterBoundaryToUtc($fromRaw, 'start');
            $to   = $this->normalizeFilterBoundaryToUtc($toRaw, 'end');

            $q = Booking::query();

            if ($from) {
                $q->where('start_time', '>=', $from);
            }

            if ($to) {
                $q->where('start_time', '<=', $to);
            }

            if ($request->filled('agent_id')) {
                $q->where('agent_id', $request->integer('agent_id'));
            }

            if ($request->filled('property_id')) {
                $q->where('property_id', $request->integer('property_id'));
            }

            $this->applyBranchAccessForAgents($request, $q, 'agent_id');

            // DB driver
            $driver = DB::getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);

            $minutesExpr = in_array($driver, ['pgsql', 'postgres', 'postgresql'], true)
                ? "SUM(FLOOR(EXTRACT(EPOCH FROM (COALESCE(end_time, start_time) - start_time)) / 60)) as total_minutes"
                : "SUM(TIMESTAMPDIFF(MINUTE, start_time, COALESCE(end_time, start_time))) as total_minutes";

            $rows = $q->select([
                'agent_id',
                DB::raw('COUNT(*) as shows_count'),
                DB::raw($minutesExpr),
                DB::raw('COUNT(DISTINCT client_id) as unique_clients'),
                DB::raw('COUNT(DISTINCT property_id) as unique_properties'),
                DB::raw('MIN(start_time) as first_show'),
                DB::raw('MAX(start_time) as last_show'),
            ])
                ->groupBy('agent_id')
                ->orderByDesc('shows_count')
                ->get();

            $users = User::whereIn('id', $rows->pluck('agent_id'))
                ->get(['id', 'name'])
                ->keyBy('id');

            return response()->json(
                $rows->map(fn ($r) => [
                    'agent_id' => (int)$r->agent_id,
                    'agent_name' => $users[$r->agent_id]->name ?? '—',
                    'shows_count' => (int)$r->shows_count,
                    'total_minutes' => (int)($r->total_minutes ?? 0),
                    'unique_clients' => (int)$r->unique_clients,
                    'unique_properties' => (int)$r->unique_properties,
                    'first_show' => $this->formatUtcToApiIso8601($r->first_show),
                    'last_show' => $this->formatUtcToApiIso8601($r->last_show),
                ])
            );
        } catch (\Throwable $e) {
            \Log::error('agentsReport error', ['e' => $e]);
            return response()->json(['error' => 'Internal server error'], 500);
        }
    }
}
