<?php

namespace App\Services\Attendance;

use App\Contracts\AttendanceDeviceProtocol;
use App\Jobs\ProcessAttendanceRawEvent;
use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceUser;
use App\Models\AttendanceEvent;
use App\Models\AttendanceIngestRequest;
use App\Models\AttendanceRawEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class AttendanceIngestionService
{
    public function __construct(
        private readonly AttendanceDeviceProtocol $protocol,
        private readonly AttendanceSummaryService $summaries,
        private readonly AttendanceParticipantService $participants,
    ) {}

    /** @return array{accepted:int,duplicates:int,unmapped:int,rejected:list<array<string,mixed>>} */
    public function ingest(AttendanceDevice $device, string $payload, array $requestMeta, ?string $sourceIp): array
    {
        $ingestRequest = AttendanceIngestRequest::query()->create([
            'device_id' => $device->id,
            'payload_hash' => hash('sha256', $payload),
            'raw_payload' => $payload,
            'request_meta' => $requestMeta,
            'source_ip' => $sourceIp,
            'received_at' => now(),
        ]);
        $parsed = $this->protocol->parse($payload, $device->timezone ?: config('attendance.timezone'));
        $result = ['accepted' => 0, 'duplicates' => 0, 'unmapped' => 0, 'rejected' => $parsed['rejected']];

        foreach ($parsed['events'] as $incoming) {
            $persisted = $this->persistEvent($device, $ingestRequest, $incoming, $requestMeta, $sourceIp);
            $result[$persisted['outcome']]++;
            if ($persisted['raw_event_id'] !== null) {
                try {
                    ProcessAttendanceRawEvent::dispatch($persisted['raw_event_id']);
                    AttendanceRawEvent::query()
                        ->whereKey($persisted['raw_event_id'])
                        ->whereIn('processing_status', ['pending', 'failed'])
                        ->update(['processing_status' => 'queued', 'processing_error' => null]);
                } catch (\Throwable $exception) {
                    Log::warning('Attendance event was saved but could not be queued.', [
                        'raw_event_id' => $persisted['raw_event_id'],
                        'device_id' => $device->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }

        $ingestRequest->forceFill([
            'processing_status' => $result['rejected'] === [] ? 'accepted' : 'partially_rejected',
            'accepted_count' => $result['accepted'],
            'duplicate_count' => $result['duplicates'],
            'unmapped_count' => $result['unmapped'],
            'rejected_rows' => $result['rejected'],
        ])->save();

        $deviceState = [
            'last_seen_at' => now(),
            'offline_notified_at' => null,
            'last_ip' => $sourceIp,
            'last_error' => $result['rejected'] === [] ? null : json_encode($result['rejected'], JSON_UNESCAPED_UNICODE),
        ];
        $latest = collect($parsed['events'])->max(fn (array $event) => $event['occurred_at_local']->utc()->getTimestamp());
        if (is_int($latest)) {
            $eventAt = CarbonImmutable::createFromTimestampUTC($latest);
            $drift = $eventAt->getTimestamp() - now()->getTimestamp();
            $measurementWindow = max(1, (int) config('attendance.clock_drift_measurement_window_seconds', 1800));
            if ($device->last_event_at === null || $eventAt->greaterThan($device->last_event_at)) {
                $deviceState['last_event_at'] = $eventAt;
            }
            if (abs($drift) <= $measurementWindow || $drift > 0) {
                $deviceState['clock_drift_seconds'] = $drift;
                if (abs($drift) > (int) config('attendance.clock_drift_warning_seconds', 300)) {
                    $deviceState['last_error'] = 'DEVICE_CLOCK_DRIFT: '.$drift.' seconds';
                }
            }
        }
        $device->forceFill($deviceState)->save();

        return $result;
    }

    public function touch(AttendanceDevice $device, ?string $sourceIp, ?string $error = null): void
    {
        $state = ['last_seen_at' => now(), 'offline_notified_at' => null, 'last_ip' => $sourceIp];
        if ($error !== null) {
            $state['last_error'] = $error;
        }
        $device->forceFill($state)->save();
    }

    public function reprocess(AttendanceRawEvent $raw): string
    {
        return DB::transaction(function () use ($raw) {
            $lockedRaw = AttendanceRawEvent::query()->lockForUpdate()->find($raw->id);
            if ($lockedRaw === null || $lockedRaw->event()->exists()) {
                return 'already_processed';
            }

            $mapping = AttendanceDeviceUser::query()
                ->where('device_id', $lockedRaw->device_id)
                ->where('device_user_id', $lockedRaw->device_user_id)
                ->where('is_active', true)
                ->first();
            if ($mapping === null || ! $this->participants->isEligible($mapping->user)) {
                $lockedRaw->forceFill(['processing_status' => 'unmapped'])->save();

                return 'unmapped';
            }

            $user = User::query()->lockForUpdate()->findOrFail($mapping->user_id);
            $mapping->setRelation('user', $user);
            $this->createNormalizedEvent($lockedRaw, $mapping);

            return 'processed';
        });
    }

    /** @return array{outcome:string,raw_event_id:?int} */
    private function persistEvent(AttendanceDevice $device, AttendanceIngestRequest $ingestRequest, array $incoming, array $requestMeta, ?string $sourceIp): array
    {
        $local = $incoming['occurred_at_local'];
        $utc = $local->utc();
        $hash = hash('sha256', implode('|', [
            $device->serial_number,
            $incoming['device_user_id'],
            $local->format('Y-m-d H:i:s'),
            $incoming['attendance_status'] ?? '',
            $incoming['verify_mode'] ?? '',
            $incoming['work_code'] ?? '',
        ]));

        return DB::transaction(function () use ($device, $ingestRequest, $incoming, $requestMeta, $sourceIp, $local, $utc, $hash) {
            $mapping = AttendanceDeviceUser::query()
                ->with('user')
                ->where('device_id', $device->id)
                ->where('device_user_id', $incoming['device_user_id'])
                ->where('is_active', true)
                ->first();
            if ($mapping !== null && ! $this->participants->isEligible($mapping->user)) {
                $mapping = null;
            }
            $raw = AttendanceRawEvent::query()->firstOrCreate(
                ['event_hash' => $hash],
                [
                    'device_id' => $device->id,
                    'ingest_request_id' => $ingestRequest->id,
                    'device_user_id' => $incoming['device_user_id'],
                    'occurred_at_local' => $local->format('Y-m-d H:i:s'),
                    'occurred_at_utc' => $utc,
                    'attendance_status' => $incoming['attendance_status'],
                    'verify_mode' => $incoming['verify_mode'],
                    'work_code' => $incoming['work_code'],
                    'raw_payload' => $incoming['raw_line'],
                    'request_meta' => $requestMeta,
                    'source_ip' => $sourceIp,
                    'received_at' => now(),
                    'processing_status' => $mapping ? 'pending' : 'unmapped',
                ]
            );
            if (! $raw->wasRecentlyCreated) {
                return [
                    'outcome' => 'duplicates',
                    'raw_event_id' => in_array($raw->processing_status, ['pending', 'failed'], true) ? $raw->id : null,
                ];
            }

            if ($mapping === null) {
                return ['outcome' => 'unmapped', 'raw_event_id' => null];
            }

            return ['outcome' => 'accepted', 'raw_event_id' => $raw->id];
        });
    }

    private function createNormalizedEvent(AttendanceRawEvent $raw, AttendanceDeviceUser $mapping): AttendanceEvent
    {
        $user = $mapping->user;
        $eventType = (string) (config('attendance.status_map.'.(string) $raw->attendance_status) ?? 'punch');
        $verificationMethod = (string) (config('attendance.verification_map.'.(string) $raw->verify_mode) ?? 'unknown');
        $window = max(0, (int) config('attendance.duplicate_window_seconds', 10));
        $isDuplicate = AttendanceEvent::query()
            ->where('user_id', $user->id)
            ->whereBetween('occurred_at', [
                $raw->occurred_at_utc->copy()->subSeconds($window),
                $raw->occurred_at_utc->copy()->addSeconds($window),
            ])
            ->exists();
        $event = AttendanceEvent::query()->create([
            'raw_event_id' => $raw->id,
            'user_id' => $user->id,
            'device_id' => $raw->device_id,
            'branch_id' => $user->branch_id ?? $raw->device?->branch_id,
            'branch_group_id' => $user->branch_group_id ?? $raw->device?->branch_group_id,
            'device_user_id' => $raw->device_user_id,
            'event_type' => $eventType,
            'occurred_at' => $raw->occurred_at_utc,
            'verification_method' => $verificationMethod,
            'direction' => match ($eventType) {
                'check_in', 'break_in' => 'in',
                'check_out', 'break_out' => 'out',
                default => null,
            },
            'is_duplicate' => $isDuplicate,
            'meta' => ['attendance_status' => $raw->attendance_status, 'verify_mode' => $raw->verify_mode],
        ]);
        $raw->forceFill(['processing_status' => 'processed', 'processing_error' => null])->save();
        $workDate = CarbonImmutable::parse($event->occurred_at)
            ->setTimezone($this->summaries->timezoneFor($user))
            ->toDateString();
        $this->summaries->recompute($user, $workDate);

        return $event;
    }
}
