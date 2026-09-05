<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Services\PropertyModeration\PropertyPromotionService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('properties:expire-promotions', function (PropertyPromotionService $service) {
    $this->info('Expired promotions: '.$service->expireDue());
})->purpose('Expire VIP and urgent property promotions');
