<?php

namespace App\Support;

use App\Models\Property;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PropertyDocumentTypes
{
    public const DEFAULTS = [
        'sale_contract' => 'ДДУ',
        'technical_passport' => 'Техпаспорт',
        'certificate_other' => 'Сертификат',
    ];

    /** Initial configuration only; subsequent choices follow the editable catalog links. */
    public static function defaultSlugs(object $type): ?array
    {
        $slug = mb_strtolower(trim((string) ($type->slug ?? '')));
        $name = mb_strtolower(trim((string) $type->name));
        if (in_array($slug, ['apartment', 'apartments', 'flat', 'secondary', 'new-buildings'], true)
            || in_array($name, ['квартира', 'квартиры', 'вторична', 'вторичное жилье', 'вторичное жильё', 'новостройки'], true)) {
            return ['sale_contract', 'technical_passport'];
        }
        if (in_array($slug, ['house', 'houses', 'cottage', 'land', 'land_plot', 'land-plots', 'plot'], true)
            || in_array($name, ['дом', 'дома', 'дача', 'участок', 'участки', 'земельный участок', 'земельные участки'], true)) {
            return ['sale_contract', 'certificate_other'];
        }

        return null;
    }

    public static function validateWrite(array $data, ?Property $property = null): void
    {
        if (! Schema::hasTable('document_type_property_type')) return;

        $typeId = (int) ($data['type_id'] ?? $property?->type_id);
        $documentId = array_key_exists('document_type_id', $data)
            ? (int) $data['document_type_id'] : (int) $property?->document_type_id;
        if (! $documentId) return;
        // A catalog change must not prevent unrelated edits to an existing listing.
        if ($property && $typeId === (int) $property->type_id && $documentId === (int) $property->document_type_id) return;

        if (! DB::table('document_type_property_type')->where('property_type_id', $typeId)
            ->where('document_type_id', $documentId)->exists()) {
            throw ValidationException::withMessages([
                'document_type_id' => 'Этот документ недоступен для выбранного вида недвижимости. Выберите подходящий тип документа.',
            ]);
        }
    }
}
