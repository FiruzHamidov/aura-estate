<?php
namespace App\Services;
use Illuminate\Support\Facades\Http;

class Bitrix24Client {
    public function __construct(private ?string $base = null){
        $this->base = $this->base ?? rtrim(config('services.bitrix24.base', env('BITRIX24_BASE')), '/');
    }
    private function call(string $method, array $payload = []): array {
        $url = rtrim($this->base, '/')."/{$method}.json";

        // DEBUG: лог исходящего запроса
        \Log::info('B24 CALL', [
            'url'     => $url,
            'payload' => $payload,
        ]);

        $r = Http::timeout(20)->asForm()->post($url, $payload);

        // DEBUG: лог ответа (код + тело)
        \Log::info('B24 RESP', [
            'url'    => $url,
            'status' => $r->status(),
            'body'   => $r->json() ?? $r->body(),
        ]);

        if ($r->failed()) {
            return [
                'error' => $r->json('error') ?? 'HTTP_'.$r->status(),
                'error_description' => $r->json('error_description') ?? $r->body(),
                'status' => $r->status(),
            ];
        }
        return $r->json();
    }
    public function leadAdd(array $fields){ return $this->call('crm.lead.add', ['fields'=>$fields]); }
    public function dealAdd(array $fields){ return $this->call('crm.deal.add', ['fields'=>$fields]); }
    public function dealUpdate(int $id, array $fields){ return $this->call('crm.deal.update', ['id'=>$id,'fields'=>$fields]); }
    public function activityAdd(array $fields){ return $this->call('crm.activity.add', ['fields'=>$fields]); }
    public function timelineCommentAdd(array $fields){ return $this->call('crm.timeline.comment.add', ['fields'=>$fields]); }
    public function itemAdd(int $entityTypeId, array $fields){ return $this->call('crm.item.add', ['entityTypeId'=>$entityTypeId,'fields'=>$fields]); }
    public function itemUpdate(int $entityTypeId, int $id, array $fields){ return $this->call('crm.item.update', ['entityTypeId'=>$entityTypeId,'id'=>$id,'fields'=>$fields]); }
    public function itemList(int $entityTypeId, array $filter = [], array $select = ['id']){
        return $this->call('crm.item.list', [
            'entityTypeId' => $entityTypeId,
            'filter' => $filter,
            'select' => $select,
            'order' => ['id' => 'asc'],
            'start' => -1,
        ]);
    }
    public function raw(string $method, array $payload = []): array {
        return $this->call($method, $payload);
    }
    public function dealGet(int $id): array {
        return $this->call('crm.deal.get', ['id' => $id]);
    }
    public function request(string $method, array $payload = []): array {
        return $this->call($method, $payload); // алиас, чтобы старый код не ломать
    }
}
