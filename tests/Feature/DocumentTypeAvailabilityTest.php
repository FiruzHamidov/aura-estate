<?php

namespace Tests\Feature;

use App\Models\DocumentType;
use App\Models\PropertyType;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DocumentTypeSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentTypeAvailabilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Schema::create('roles', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('slug'); $t->json('permissions')->nullable(); $t->timestamps(); });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('phone'); $t->unsignedBigInteger('role_id');
            $t->string('status')->default('active'); $t->string('auth_method')->default('password'); $t->timestamps();
        });
        foreach (['property_types', 'document_types'] as $table) {
            Schema::create($table, function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('slug')->unique(); $t->timestamps(); });
        }
    }

    private function migrateLinks(): void
    {
        (require database_path('migrations/2026_11_26_180000_link_document_types_to_property_types.php'))->up();
    }

    private function actor(string $slug = 'admin'): User
    {
        $role = Role::create(['name' => $slug, 'slug' => $slug]);
        $role->forceFill(['permissions' => $slug === 'admin' ? ['catalogs.entries.document-types.manage', 'catalogs.details.view'] : []])->save();
        return User::create(['name' => $slug, 'phone' => '90000000'.$role->id, 'role_id' => $role->id]);
    }

    public function test_migration_configures_two_choices_for_each_supported_residential_category(): void
    {
        $legacy = DocumentType::create(['name' => 'Свидетельство', 'slug' => 'certificate']);
        $types = collect(['apartment', 'secondary', 'new-buildings', 'houses', 'land-plots', 'commercial'])
            ->map(fn ($slug) => PropertyType::create(['name' => $slug, 'slug' => $slug]));
        $this->migrateLinks();
        foreach ($types as $type) {
            $response = $this->getJson('/api/document-types?property_type_id='.$type->id)->assertOk();
            $expected = $type->slug === 'commercial' ? ['certificate', 'certificate_other', 'sale_contract', 'technical_passport']
                : (in_array($type->slug, ['houses', 'land-plots']) ? ['certificate_other', 'sale_contract'] : ['sale_contract', 'technical_passport']);
            $this->assertEqualsCanonicalizing($expected, array_column($response->json(), 'slug'));
            foreach ($response->json() as $row) $this->assertContains($type->id, $row['property_type_ids']);
        }
        $this->assertDatabaseHas('document_types', ['id' => $legacy->id, 'name' => 'Свидетельство']);
        $this->assertDatabaseHas('document_types', ['slug' => 'sale_contract', 'name' => 'ДДУ']);
        $this->getJson('/api/document-types?property_type_id=99999')->assertUnprocessable();
    }

    public function test_admin_can_assign_clear_and_preserve_links_and_invalid_requests_are_atomic(): void
    {
        $apartment = PropertyType::create(['name' => 'Квартира', 'slug' => 'apartment']);
        $house = PropertyType::create(['name' => 'Дом', 'slug' => 'house']);
        $this->migrateLinks();
        $agent = $this->actor('agent');
        Sanctum::actingAs($this->actor());
        $created = $this->postJson('/api/document-types', ['name' => 'Новый', 'slug' => 'custom', 'property_type_ids' => [$apartment->id, $house->id]])
            ->assertCreated()->assertJsonCount(2, 'property_type_ids');
        $id = $created->json('id');
        $this->putJson('/api/document-types/'.$id, ['name' => 'Переименован'])->assertOk()->assertJsonCount(2, 'property_type_ids');
        foreach ([[$house->id, $house->id], [99999]] as $invalid) {
            $this->putJson('/api/document-types/'.$id, ['name' => 'Не сохранять', 'property_type_ids' => $invalid])->assertUnprocessable();
        }
        $this->assertDatabaseHas('document_types', ['id' => $id, 'name' => 'Переименован']);
        $this->getJson('/api/document-types/'.$id)->assertOk()->assertJsonCount(2, 'property_type_ids');
        $this->putJson('/api/document-types/'.$id, ['property_type_ids' => []])->assertOk()->assertJsonPath('property_type_ids', []);
        $this->assertDatabaseMissing('document_type_property_type', ['document_type_id' => $id]);
        $this->assertFalse($agent->hasPermission('catalogs.entries.document-types.manage'));
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($agent);
        $this->putJson('/api/document-types/'.$id, ['property_type_ids' => [$house->id]])->assertForbidden();
        $this->assertDatabaseMissing('document_type_property_type', ['document_type_id' => $id]);
    }

    public function test_fresh_seed_uses_three_documents_and_reruns_preserve_admin_configuration(): void
    {
        $this->migrateLinks();
        $apartment = PropertyType::create(['name' => 'Квартира', 'slug' => 'apartment']);
        PropertyType::create(['name' => 'Участок', 'slug' => 'land-plots']);
        $this->seed(DocumentTypeSeeder::class);
        $this->assertDatabaseCount('document_types', 3);
        $this->getJson('/api/document-types?property_type_id='.$apartment->id)->assertOk()->assertJsonCount(2);
        $document = DocumentType::where('slug', 'sale_contract')->firstOrFail();
        $document->update(['name' => 'Название администратора']);
        $document->propertyTypes()->sync([]);
        $this->seed(DocumentTypeSeeder::class);
        $this->assertSame('Название администратора', $document->fresh()->name);
        $this->assertDatabaseMissing('document_type_property_type', ['document_type_id' => $document->id]);
        (require database_path('migrations/2026_11_26_180000_link_document_types_to_property_types.php'))->down();
        $this->assertDatabaseCount('document_types', 3);
    }
}
