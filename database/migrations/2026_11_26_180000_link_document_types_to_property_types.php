<?php

use App\Support\PropertyDocumentTypes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('document_type_property_type', function (Blueprint $table) {
            $table->foreignId('document_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_type_id')->constrained()->cascadeOnDelete();
            $table->primary(['document_type_id', 'property_type_id'], 'document_property_type_primary');
        });

        $types = DB::table('property_types')->get();
        if ($types->isEmpty()) return;

        foreach (PropertyDocumentTypes::DEFAULTS as $slug => $name) {
            $existing = DB::table('document_types')->where('slug', $slug)->first();
            if ($existing) {
                if ($slug === 'sale_contract') DB::table('document_types')->where('id', $existing->id)->update(['name' => $name]);
            } else {
                DB::table('document_types')->insert(['slug' => $slug, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $documents = DB::table('document_types')->get();
        foreach ($types as $type) {
            $allowed = PropertyDocumentTypes::defaultSlugs($type);
            foreach ($documents as $document) {
                if ($allowed === null || in_array($document->slug, $allowed, true)) {
                    DB::table('document_type_property_type')->insert([
                        'document_type_id' => $document->id, 'property_type_id' => $type->id,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_type_property_type');
    }
};
