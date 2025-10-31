<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Http\Request;
use App\Services\Bitrix24Client;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        $q = Booking::query()->with(['property', 'agent', 'client']);

        $inputTz = 'Asia/Dushanbe';
        $dbTz = 'UTC';

        // Helper: convert input date/time (string) in inputTz to DB timezone string
        $toDbTz = function (?string $value, string $defaultBoundary = null) use ($inputTz, $dbTz) {
            if (empty($value)) {
                return null;
            }

            // If user passed only date (YYYY-MM-DD) and defaultBoundary specified ('start'|'end'),
            // append time part to cover day bounds.
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && $defaultBoundary) {
                $value .= $defaultBoundary === 'start' ? ' 00:00:00' : ' 23:59:59';
            }

            // Carbon::parse is flexible: will accept ISO with TZ if provided, else treat as local ($inputTz)
            try {
                $c = Carbon::parse($value, $inputTz)->setTimezone($dbTz);
                return $c->toDateTimeString();
            } catch (\Throwable $e) {
                // if parse fails, return null so filter won't be applied
                \Log::warning('Failed to parse date filter: ' . $value . ' — ' . $e->getMessage());
                return null;
            }
        };

        // from / to: convert to UTC for DB comparison
        $fromRaw = $request->input('from');
        $toRaw = $request->input('to');

        $fromDb = $toDbTz($fromRaw, $fromRaw && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromRaw) ? 'start' : 'start');
        $toDb   = $toDbTz($toRaw,   $toRaw   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toRaw)   ? 'end'   : 'end');

        if ($fromDb) {
            $q->where('start_time', '>=', $fromDb);
        }
        if ($toDb) {
            $q->where('end_time', '<=', $toDb);
        }

        if ($request->filled('agent_id')) {
            $q->where('agent_id', $request->integer('agent_id'));
        }
        if ($request->filled('property_id')) {
            $q->where('property_id', $request->integer('property_id'));
        }

        $bookings = $q->orderBy('start_time')->get();

        // Convert times to output timezone for response
        $outputTz = $inputTz; // Asia/Dushanbe
        $bookings->transform(function ($b) use ($outputTz) {
            // protect against nulls or string values
            if ($b->start_time) {
                $b->start_time = Carbon::parse($b->start_time, 'UTC')->setTimezone($outputTz)->toDateTimeString();
            }
            if ($b->end_time) {
                $b->end_time = Carbon::parse($b->end_time, 'UTC')->setTimezone($outputTz)->toDateTimeString();
            }
            return $b;
        });

        return $bookings;
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'property_id' => 'required|exists:properties,id',
            'agent_id' => 'required|exists:users,id',
            'client_id' => 'nullable|exists:users,id',
            'start_time' => 'required|date',
            'end_time' => 'required|date|after:start_time',
            'note' => 'nullable|string',
            'client_name' => 'nullable|string',
            'client_phone' => 'nullable|string',
            'deal_id' => 'nullable|integer',
            'contact_id' => 'nullable|integer',
            'place' => 'nullable|string',
            'sync_to_b24' => 'sometimes|boolean',
        ]);

        // ---------- timezone handling ----------
        // Предполагаем что пользователь вводит время в Asia/Dushanbe (+05:00).
        // Конвертируем в UTC перед сохранением в БД.
        $inputTz = 'Asia/Dushanbe'; // можно вынести в конфиг, если нужно

        $startCarbon = Carbon::parse($validated['start_time'], $inputTz)->setTimezone('UTC');
        $endCarbon   = Carbon::parse($validated['end_time'], $inputTz)->setTimezone('UTC');

        // Сохраняем в формате, который БД корректно принимает
        $validated['start_time'] = $startCarbon->toDateTimeString(); // "Y-m-d H:i:s" (UTC)
        $validated['end_time']   = $endCarbon->toDateTimeString();

        $booking = Booking::create($validated);

        // Ensure relations are available for subject/description
        $booking->load(['property', 'agent', 'client']);

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

        // Если в модели даты хранятся как строки, будем парсить их в Carbon перед сетом временной зоны.
        $outputTz = 'Asia/Dushanbe';

        if ($booking->start_time) {
            // использование Carbon::parse защищает от случаев, когда start_time — строка
            $booking->start_time = Carbon::parse($booking->start_time, 'UTC')
                ->setTimezone($outputTz)
                ->toDateTimeString(); // или ->toIso8601String()
        }
        if ($booking->end_time) {
            $booking->end_time = Carbon::parse($booking->end_time, 'UTC')
                ->setTimezone($outputTz)
                ->toDateTimeString();
        }

        return response()->json($booking);
    }

    public function agentsReport(Request $request)
    {
        try {
            $from = $request->input('from'); // expected YYYY-MM-DD or ISO
            $to   = $request->input('to');   // expected YYYY-MM-DD or ISO

            $q = Booking::query();

            // date filters (apply on start_time)
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

            // detect DB driver to pick correct minutes expression
            $driver = null;
            try {
                $driver = DB::getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);
            } catch (\Throwable $e) {
                \Log::warning('agentsReport: could not detect DB driver: ' . $e->getMessage());
            }

            if (in_array($driver, ['pgsql', 'postgres', 'postgresql'], true)) {
                // Postgres: use EXTRACT(EPOCH FROM interval)/60 and floor to minutes
                $minutesExpr = "SUM(FLOOR(EXTRACT(EPOCH FROM (COALESCE(end_time, start_time) - start_time)) / 60)) as total_minutes";
            } else {
                // MySQL / MariaDB
                $minutesExpr = "SUM(TIMESTAMPDIFF(MINUTE, start_time, COALESCE(end_time, start_time))) as total_minutes";
            }

            // Aggregation
            $rows = (clone $q)
                ->select([
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

            // attach agent names (preserve order)
            $agentIds = $rows->pluck('agent_id')->filter()->unique()->values()->all();

            $users = User::whereIn('id', $agentIds)->get(['id', 'name'])->keyBy('id');

            $result = $rows->map(function ($r) use ($users) {
                return [
                    'agent_id' => (int)$r->agent_id,
                    'agent_name' => $users[$r->agent_id]->name ?? '—',
                    'shows_count' => (int)$r->shows_count,
                    'total_minutes' => isset($r->total_minutes) ? (int)$r->total_minutes : 0,
                    'unique_clients' => (int)$r->unique_clients,
                    'unique_properties' => (int)$r->unique_properties,
                    'first_show' => $r->first_show ? (string)$r->first_show : null,
                    'last_show' => $r->last_show ? (string)$r->last_show : null,
                ];
            });

            return response()->json($result);
        } catch (\Throwable $e) {
            \Log::error('agentsReport error: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(['error' => 'Internal server error'], 500);
        }
    }
}
