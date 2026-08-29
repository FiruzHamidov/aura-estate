<?php

namespace App\Services\Crm;

use App\Models\Lead;
use App\Services\NotificationService;
use App\Services\Residential\InventoryFilters;
use App\Support\ClientPhone;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** One transactional entry point for website forms and chat. No external delivery. */
class PublicLeadIntake
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PublicLeadContext $context,
        private readonly NotificationService $notifications,
    ) {}

    public function accept(array $input): array
    {
        $input['name'] = is_string($input['name'] ?? null) ? trim($input['name']) : ($input['name'] ?? null);
        $rawPhone = is_string($input['phone'] ?? null) ? trim($input['phone']) : '';
        $input['phone'] = preg_match('/^[+\d\s().-]+$/', $rawPhone)
            ? ClientPhone::normalize($rawPhone) : '';
        $data = Validator::make($input, [
            'service_type' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'phone' => ['required', 'regex:/^[1-9]\d{6,14}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'comment' => ['nullable', 'string', 'max:5000'],
            'source' => ['nullable', 'string', 'max:100'],
            'source_url' => ['nullable', 'url:http,https', 'max:1000'],
            'utm' => ['nullable', 'array', 'max:10'],
            'utm.*' => ['nullable', 'string', 'max:255'],
            'context' => ['nullable', 'array', 'max:30', function ($attribute, $value, $fail) {
                if (strlen(json_encode($value)) > 16000) {
                    $fail('Контекст заявки слишком большой.');
                }
            }],
            'context.building_id' => ['nullable', 'integer', 'min:1', 'required_with:context.unit_id'],
            'context.unit_id' => ['nullable', 'integer', 'min:1', 'required_with:context.expected_version'],
            'context.expected_version' => ['nullable', 'integer', 'min:1'],
            'context.property_id' => ['nullable', 'integer', 'min:1'],
            'context.payment_program_id' => ['nullable', 'integer', 'min:1', 'required_with:context.expected_program_version'],
            'context.expected_program_version' => ['nullable', 'integer', 'min:1', 'required_with:context.payment_program_id'],
            'context.filters' => ['nullable', 'array', 'max:30'],
            'idempotency_key' => ['nullable', 'string', 'min:16', 'max:128'],
            'intent' => ['nullable', Rule::in(['consultation', 'viewing', 'availability', 'availability_notification', 'similar_selection', 'payment_consultation'])],
            'consent' => ['sometimes', 'accepted'],
            'consent_version' => ['nullable', 'string', 'max:100', 'required_with:consent'],
        ])->validate();

        if (isset($data['context']['filters'])) {
            try {
                // Reuse the inventory contract; URL preferences cannot inject routing or lot data.
                $data['context']['filters'] = app(InventoryFilters::class)->validate(
                    Arr::only($data['context']['filters'], [...InventoryFilters::UNIT_FIELDS, 'include_reserved'])
                );
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages(collect($exception->errors())
                    ->mapWithKeys(fn ($messages, $field) => ['context.filters.'.$field => $messages])->all());
            }
        }

        $key = $data['idempotency_key'] ?? (string) Str::uuid();
        unset($data['idempotency_key']);
        $data['email'] = empty($data['email']) ? null : mb_strtolower(trim($data['email']));
        $data['source_url'] = empty($data['source_url']) ? null : preg_replace('/[?#].*$/', '', $data['source_url']);
        $fingerprint = hash('sha256', json_encode($this->canonicalize($data), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $keyHash = hash('sha256', $key);

        return DB::transaction(function () use ($data, $keyHash, $fingerprint) {
            // The unique index arbitrates concurrent retries; receipt and lead commit together.
            DB::table('lead_intakes')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'key_hash' => $keyHash,
                'payload_hash' => $fingerprint,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $receipt = DB::table('lead_intakes')->where('key_hash', $keyHash)->lockForUpdate()->first();
            abort_unless($receipt && hash_equals($receipt->payload_hash, $fingerprint), 409, 'Ключ отправки уже использован для другой заявки.');
            if ($receipt->lead_id !== null) {
                return $this->response($receipt->id, (int) $receipt->lead_id, true);
            }

            $resolved = $this->context->resolve($data['context'] ?? []);
            $lead = Lead::create([
                'full_name' => $data['name'],
                'phone' => '+'.$data['phone'],
                'phone_normalized' => $data['phone'],
                'email' => $data['email'],
                'note' => $data['comment'] ?? null,
                'source' => $data['source'] ?? 'aura-site-form',
                'status' => $resolved['responsible_agent_id'] ? Lead::STATUS_ASSIGNED : Lead::STATUS_NEW,
                'responsible_agent_id' => $resolved['responsible_agent_id'],
                'branch_id' => $resolved['branch_id'],
                'first_contact_due_at' => now()->addMinutes(Lead::DEFAULT_FIRST_CONTACT_SLA_MINUTES),
                'last_activity_at' => now(),
                'meta' => [
                    'service_type' => $data['service_type'],
                    'intent' => $data['intent'] ?? 'consultation',
                    'source_url' => $data['source_url'],
                    'utm' => $data['utm'] ?? [],
                    'context' => $resolved['context'],
                    'consent' => [
                        'accepted' => isset($data['consent']),
                        'version' => $data['consent_version'] ?? null,
                        'accepted_at' => isset($data['consent']) ? now()->toIso8601String() : null,
                    ],
                    'intake_id' => $receipt->id,
                ],
            ]);
            DB::table('lead_intakes')->where('id', $receipt->id)->update(['lead_id' => $lead->id, 'updated_at' => now()]);
            $this->audit->log($lead, null, 'created', [], ['source' => $lead->source, 'status' => $lead->status], 'Заявка принята во внутреннюю CRM Aura.');
            DB::afterCommit(function () use ($lead) {
                try {
                    $this->notifications->handlePublicLeadCreated($lead);
                } catch (\Throwable) {
                    Log::warning('Public lead notification failed.', ['lead_id' => $lead->id]);
                }
            });

            return $this->response($receipt->id, $lead->id, false);
        }, 3);
    }

    private function response(string $id, int $leadId, bool $replayed): array
    {
        return ['message' => 'Заявка принята в Aura.', 'request_id' => $id, 'lead_id' => $leadId, 'replayed' => $replayed];
    }

    private function canonicalize(array $data): array
    {
        if (! array_is_list($data)) {
            ksort($data);
        }

        return array_map(fn ($value) => is_array($value) ? $this->canonicalize($value) : $value, $data);
    }
}
