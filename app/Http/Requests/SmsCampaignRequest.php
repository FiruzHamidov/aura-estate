<?php

namespace App\Http\Requests;

use App\Models\Client;
use App\Models\Lead;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SmsCampaignRequest extends FormRequest
{
    public function rules(): array
    {
        $base = $this->input('segment.base');
        $optional = match ($base) {
            'clients' => ',contact_kind,business,source_id,status',
            'leads' => ',status,source',
            default => '',
        };

        return [
            'request_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string', 'max:1000'],
            'scheduled_at' => ['nullable', 'date_format:Y-m-d\\TH:i:s.v\\Z'],
            'segment' => ['required', 'array:base,branch_id,from,to'.$optional],
            'segment.base' => ['required', Rule::in(['clients', 'leads', 'accounts'])],
            'segment.branch_id' => ['sometimes', 'integer', 'min:1', 'exists:branches,id'],
            'segment.from' => ['sometimes', 'date_format:Y-m-d'],
            'segment.to' => ['sometimes', 'date_format:Y-m-d', ...($this->filled('segment.from') ? ['after_or_equal:segment.from'] : [])],
            'segment.contact_kind' => ['sometimes', Rule::in(Client::contactKinds())],
            'segment.business' => ['sometimes', 'accepted'],
            'segment.source_id' => ['sometimes', 'integer', 'exists:client_sources,id'],
            'segment.status' => ['sometimes', Rule::in($base === 'leads' ? Lead::statuses() : ['active', 'inactive'])],
            'segment.source' => ['sometimes', 'required', 'string', 'max:255'],
            'preview_token' => [$this->routeIs('sms-campaigns.store') ? 'required' : 'sometimes', 'string', 'size:64'],
        ];
    }
}
