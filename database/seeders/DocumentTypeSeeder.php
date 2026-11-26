<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use App\Models\PropertyType;
use App\Support\PropertyDocumentTypes;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class DocumentTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PropertyDocumentTypes::DEFAULTS as $slug => $name) {
            $document = DocumentType::query()->firstOrCreate(['slug' => $slug], ['name' => $name]);
            if ($document->wasRecentlyCreated && Schema::hasTable('document_type_property_type')) {
                $ids = PropertyType::all()->filter(function ($type) use ($slug) {
                    $allowed = PropertyDocumentTypes::defaultSlugs($type);
                    return $allowed === null || in_array($slug, $allowed, true);
                })->modelKeys();
                $document->propertyTypes()->sync($ids);
            }
        }
    }
}
