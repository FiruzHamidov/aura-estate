<?php

namespace App\Http\Controllers;

use App\Services\Bitrix24Client;
use Illuminate\Http\Request;

class LeadRequestController extends Controller
{
    public function store(Request $request, Bitrix24Client $b24)
    {
        $data = $request->validate([
            'service_type' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'comment' => ['nullable', 'string', 'max:5000'],
            'source' => ['nullable', 'string', 'max:255'],
            'source_url' => ['nullable', 'string', 'max:1000'],
            'utm' => ['nullable', 'array'],
            'context' => ['nullable', 'array'],
        ]);

        if (empty(config('services.bitrix24.base'))) {
            return response()->json([
                'message' => 'Bitrix24 не настроен',
            ], 503);
        }

        $serviceType = trim((string) $data['service_type']);
        $phone = $this->normalizePhone($data['phone']);

        $comments = [];
        if (!empty($data['comment'])) {
            $comments[] = 'Комментарий: ' . $data['comment'];
        }
        if (!empty($data['source_url'])) {
            $comments[] = 'Страница: ' . $data['source_url'];
        }
        if (!empty($data['utm'])) {
            $comments[] = 'UTM: ' . json_encode($data['utm'], JSON_UNESCAPED_UNICODE);
        }
        if (!empty($data['context'])) {
            $comments[] = 'Контекст формы: ' . json_encode($data['context'], JSON_UNESCAPED_UNICODE);
        }

        $leadFields = [
            'TITLE' => sprintf('Заявка с сайта: %s', $serviceType),
            'NAME' => $data['name'],
            'PHONE' => [
                ['VALUE' => $phone, 'VALUE_TYPE' => 'WORK'],
            ],
            'SOURCE_ID' => 'WEB',
            'SOURCE_DESCRIPTION' => $data['source'] ?? 'aura-site-form',
            'COMMENTS' => implode("\n", $comments),
        ];

        if (!empty($data['email'])) {
            $leadFields['EMAIL'] = [
                ['VALUE' => $data['email'], 'VALUE_TYPE' => 'WORK'],
            ];
        }

        $result = $b24->leadAdd($leadFields);

        if (!empty($result['error'])) {
            return response()->json([
                'message' => 'Не удалось отправить лид в Bitrix24',
                'bitrix' => $result,
            ], 502);
        }

        return response()->json([
            'message' => 'Лид отправлен в Bitrix24',
            'lead_id' => $result['result'] ?? null,
        ], 201);
    }

    private function normalizePhone(string $phone): string
    {
        return trim((string) preg_replace('/\s+/', '', $phone));
    }
}
