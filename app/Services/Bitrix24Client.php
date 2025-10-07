<?php
namespace App\Services;
use Illuminate\Support\Facades\Http;

class Bitrix24Client {
    public function __construct(private ?string $base = null){
        $this->base = $this->base ?? rtrim(config('services.bitrix24.base', env('BITRIX24_BASE')), '/');
    }
    private function call(string $method, array $payload = []): array {
        $r = Http::timeout(20)->asForm()->post("{$this->base}/{$method}.json", $payload);
        $r->throw(); return $r->json();
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
}
