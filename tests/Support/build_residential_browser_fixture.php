<?php

// CLI-only disposable browser fixture, never a seed against a configured application database.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (PHP_SAPI !== 'cli' || ! $app->environment('testing') || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
    throw new LogicException('Use APP_ENV=testing and SQLite :memory: only.');
}
$target = $argv[1] ?? '';
if (! str_starts_with($target, '/private/tmp/aura-residential-qa.') && ! str_starts_with($target, '/tmp/aura-residential-qa.')) {
    throw new LogicException('Use a new mktemp directory named aura-residential-qa.XXXXXX.');
}
if (file_exists($target) || ! is_dir(dirname($target))) {
    throw new LogicException('Target database must not exist.');
}
config(['filesystems.disks.residential.root' => dirname($target).'/media']);
Tests\Support\ResidentialSchema::create();
Illuminate\Support\Facades\Schema::table('users', function (Illuminate\Database\Schema\Blueprint $table) {
    $table->string('password')->nullable();
    $table->string('email')->nullable();
    $table->unsignedBigInteger('branch_group_id')->nullable();
});
$withOrdinaryListings = ($argv[2] ?? '') === '--with-ordinary-listings';
if ($withOrdinaryListings) {
    Tests\Support\ResidentialOrdinaryListings::createSchema();
} else {
    Illuminate\Support\Facades\Schema::create('properties', function (Illuminate\Database\Schema\Blueprint $table) {
        $table->id();
        $table->string('moderation_status')->default('approved');
        $table->timestamps();
    });
}
foreach ([
    '2025_12_09_222014_add_business_owner_and_developer_fields_to_properties_table.php',
    '2026_03_07_100000_create_clients_table.php',
    '2025_06_23_015158_create_personal_access_tokens_table.php',
    '2026_07_29_000003_create_reference_catalog_merge_audits_table.php',
    '2025_06_24_151549_create_favorites_table.php', '2026_08_28_150000_expand_favorites_for_residential_objects.php',
    '2025_06_23_004505_create_reviews_table.php', '2026_03_03_150000_prepare_reviews_table.php', '2026_08_28_160000_add_residential_review_moderation.php',
    '2026_08_28_170000_create_payment_programs.php',
    '2026_08_28_180000_create_residential_building_content.php',
    '2026_08_28_190000_create_residential_import_batches.php',
    '2026_03_07_120000_create_leads_table.php', '2026_08_28_120000_create_lead_intakes_table.php',
    '2026_03_07_121000_create_crm_deal_pipelines_table.php',
    '2025_06_23_004510_create_notifications_table.php', '2026_04_04_180000_expand_notifications_table.php',
] as $file) {
    (require database_path('migrations/'.$file))->up();
}

$role = App\Models\Role::create(['name' => 'Admin', 'slug' => 'admin']);
$branchId = Illuminate\Support\Facades\DB::table('branches')->insertGetId(['name' => 'QA филиал A']);
$qaPasswordHash = Illuminate\Support\Facades\Hash::make('Local-QA-only-2026');
$actor = App\Models\User::create(['name' => 'QA консультант Aura', 'phone' => '000000001', 'password' => $qaPasswordHash, 'role_id' => $role->id, 'branch_id' => $branchId]);
foreach (['client', 'agent', 'mop', 'rop', 'branch_director', 'hr', 'accountant', 'superadmin', 'owner'] as $index => $slug) {
    $testRole = App\Models\Role::create(['name' => 'QA '.$slug, 'slug' => $slug]);
    App\Models\User::create(['name' => 'QA '.$slug, 'phone' => str_pad((string) ($index + 2), 9, '0', STR_PAD_LEFT), 'password' => $qaPasswordHash, 'role_id' => $testRole->id, 'branch_id' => $slug === 'client' ? null : $branchId]);
}
$location = App\Models\Location::create(['city' => 'Душанбе', 'district' => 'Тестовый район']);
$building = App\Models\NewBuilding::create(['title' => 'Тестовый ЖК — только локальная проверка', 'description' => 'Изолированные тестовые данные. Не являются реальным предложением.', 'created_by' => $actor->id, 'responsible_agent_id' => $actor->id, 'location_id' => $location->id, 'address' => 'Тестовый адрес', 'publication_status' => 'published', 'moderation_status' => 'approved', 'data_verified_at' => now(), 'completion_precision' => 'quarter', 'completion_year' => 2028, 'completion_quarter' => 2]);
$building->update(['branch_id' => $branchId, 'created_by' => 3]);
$block = $building->blocks()->create(['name' => 'Корпус A', 'floors_from' => 1, 'floors_to' => 5]);
$entrance = $building->entrances()->create(['block_id' => $block->id, 'name' => 'Подъезд 1', 'residential_floor_from' => 1, 'residential_floor_to' => 5, 'technical_floors' => []]);
$units = [];
foreach (range(1, 19) as $number) {
    $availability = $number <= 10 ? 'available' : ($number <= 12 ? 'reserved' : 'sold');
    $units[] = $building->units()->create(['block_id' => $block->id, 'entrance_id' => $entrance->id, 'name' => 'Квартира '.$number, 'number' => (string) $number, 'rooms' => $number % 3 + 1, 'bedrooms' => $number % 3 + 1, 'area' => 50 + $number, 'floor' => (int) ceil($number / 4), 'position_on_floor' => ($number - 1) % 4 + 1, 'total_price' => (50 + $number) * 10000, 'price_per_sqm' => 10000, 'publication_status' => $number > 15 ? 'draft' : 'published', 'availability_status' => $availability, 'moderation_status' => $number > 15 ? 'draft' : $availability, 'is_available' => $number <= 10]);
}
$imagePath = dirname($target).'/fixture.png';
$image = imagecreatetruecolor(1000, 600);
$white = imagecolorallocate($image, 248, 250, 252);
$blue = imagecolorallocate($image, 30, 64, 175);
$gray = imagecolorallocate($image, 148, 163, 184);
imagefill($image, 0, 0, $white);
imagerectangle($image, 100, 100, 900, 500, $blue);
imageline($image, 500, 100, 500, 500, $gray);
imagestring($image, 5, 140, 160, 'QA FLOOR PLAN - UNIT 1', $blue);
imagestring($image, 5, 540, 160, 'QA FLOOR PLAN - UNIT 2', $blue);
imagepng($image, $imagePath);
imagedestroy($image);
$file = fn () => new Illuminate\Http\UploadedFile($imagePath, 'fixture.png', 'image/png', null, true);
$writer = app(App\Services\Residential\PhotoWriter::class);
$writer->add($actor, $building, null, [$file()], ['kind' => 'photo', 'alt' => 'Локальный тестовый рисунок']);
$masterplan = $writer->add($actor, $building, null, [$file()], ['kind' => 'masterplan', 'alt' => 'Тестовый генплан'])[0];
$masterplan->update(['block_regions' => [['block_id' => $block->id, 'points' => [[10, 16], [90, 16], [90, 83], [10, 83]]]]]);
$writer->add($actor, $building, $units[0], [$file()], ['kind' => 'plan', 'alt' => 'Индивидуальный план квартиры 1']);
$plan = $building->floorPlans()->create(['block_id' => $block->id, 'entrance_id' => $entrance->id, 'floor_from' => 1, 'floor_to' => 1, 'unit_regions' => [['unit_id' => $units[0]->id, 'points' => [[10, 16], [50, 16], [50, 83], [10, 83]]]]]);
app(App\Services\Residential\PlanImages::class)->store($actor, $building, 'floor-plans', $plan->id, $file(), ['version' => 1]);
App\Models\NewBuilding::create(['title' => 'Тестовый ЖК без квартир', 'publication_status' => 'published', 'moderation_status' => 'approved']);
$terms = ['title' => 'QA рассрочка — не реальное предложение', 'type' => 'installment', 'scope' => 'all', 'calculation_method' => 'equal_installment', 'period_months' => 1, 'term_min_months' => 12, 'term_max_months' => 24, 'min_down_percent' => '20', 'annual_rate' => '0', 'upfront_fee_percent' => '0', 'upfront_fee_fixed' => '0', 'fees_verified' => true, 'conditions' => 'Только локальная синтетическая проверка. Эти условия не предоставляются банком или застройщиком.', 'source_url' => 'https://example.com/qa-terms', 'confirmation_reference' => 'QA synthetic fixture', 'data_verified_at' => now()->toIso8601String(), 'valid_from' => now()->subDay()->toDateString(), 'valid_until' => now()->addMonth()->toDateString(), 'publication_status' => 'published'];
app(App\Services\Residential\PaymentPrograms::class)->save($actor, $building, $terms);
app(App\Services\Residential\PaymentPrograms::class)->save($actor, null, [...$terms, 'title' => 'QA ипотека — не реальное предложение', 'type' => 'mortgage', 'bank_name' => 'QA не банк', 'calculation_method' => 'annuity', 'annual_rate' => '12']);
// Exercise intake -> protected CRM detail before exporting; leave no lead or notification behind.
Illuminate\Support\Facades\Auth::setUser($actor);
Illuminate\Support\Facades\DB::beginTransaction();
try {
    $receipt = app(App\Services\Crm\PublicLeadIntake::class)->accept([
        'name' => 'QA CRM fixture check', 'phone' => '+992900000987',
        'service_type' => 'Жилые комплексы', 'comment' => "QA comment\nSecond line",
        'consent' => true, 'consent_version' => '2026-08-28',
        'context' => ['building_id' => $building->id],
    ]);
    $detail = app(App\Http\Controllers\LeadController::class)->show(
        Illuminate\Http\Request::create('/api/leads/'.$receipt['lead_id']),
        App\Models\Lead::findOrFail($receipt['lead_id'])
    )->getData(true);
    if (($detail['note'] ?? null) !== "QA comment\nSecond line") {
        throw new LogicException('CRM fixture cannot read the accepted lead comment.');
    }
} finally {
    Illuminate\Support\Facades\DB::rollBack();
}
if (Illuminate\Support\Facades\DB::table('leads')->exists() || Illuminate\Support\Facades\DB::table('lead_intakes')->exists()) {
    throw new LogicException('CRM fixture smoke test must not leave accepted leads behind.');
}
if ($withOrdinaryListings) {
    Tests\Support\ResidentialOrdinaryListings::seed(3, $location->id);
}
Illuminate\Support\Facades\DB::statement('VACUUM INTO ?', [$target]);
echo "Fixture database created. Building ID: {$building->id}; unit ID: {$units[0]->id}.\n";
