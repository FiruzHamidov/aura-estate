<?php

namespace App\Http\Requests;

use App\Services\Crm\ClientListFilters;
use App\Models\Lead;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SmsCampaignRequest extends FormRequest
{
    public function rules(): array
    {
        $base = $this->input('segment.base');
        $filters = match ($base) {
            'clients' => ClientListFilters::rules() + ['business' => ['sometimes', 'accepted']],
            'leads' => [
                'status' => ['sometimes', Rule::in(Lead::statuses())],
                'source' => ['sometimes', 'required', 'string', 'max:255'],
            ],
            'test' => [
                'phones' => ['required', 'array', 'list', 'min:1', 'max:10'],
                'phones.*' => ['required', 'string', 'max:50'],
            ],
            default => [],
        };
        if ($base !== 'test') {
            $filters += [
                'branch_id' => ['sometimes', 'integer', 'min:1', 'exists:branches,id'],
                'from' => ['sometimes', 'date_format:Y-m-d'],
                'to' => ['sometimes', 'date_format:Y-m-d', ...($this->filled('segment.from') ? ['after_or_equal:segment.from'] : [])],
            ];
        }
        $keys = ['base'];
        $rules = [];
        foreach ($filters as $key => $rule) {
            $rules['segment.'.$key] = $rule;
            if (!str_contains($key, '.')) {
                $keys[] = $key;
            }
        }

        $rules['segment'] = ['required', 'array:'.implode(',', $keys)];
        $rules['segment.base'] = ['required', Rule::in(['clients', 'leads', 'accounts', 'test'])];
        if ($this->routeIs('sms-campaigns.audience')) {
            return $rules;
        }

        return $rules + [
            'request_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string', 'max:1000'],
            'delivery_report' => ['sometimes', 'boolean'],
            'scheduled_at' => [Rule::prohibitedIf($base === 'test'), 'nullable', 'date_format:Y-m-d\\TH:i:s.v\\Z'],
            'preview_token' => [$this->routeIs('sms-campaigns.store') ? 'required' : 'sometimes', 'string', 'size:64'],
        ];
    }
}
