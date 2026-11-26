<?php

namespace App\Http\Controllers;

use App\Models\DocumentType;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class DocumentTypeController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['property_type_id' => ['sometimes', 'integer', 'exists:property_types,id']]);
        $query = DocumentType::query()->orderBy('name');
        if (Schema::hasTable('document_type_property_type')) {
            $query->with('propertyTypes:id');
            if (isset($data['property_type_id'])) {
                $query->whereHas('propertyTypes', fn ($types) => $types->where('property_types.id', $data['property_type_id']));
            }
        }

        return response()->json($query->get()->map(fn ($document) => $this->serialize($document)));
    }

    public function show(DocumentType $documentType)
    {
        return response()->json($this->serialize($documentType));
    }

    public function store(Request $request)
    {
        $data = $this->validateDocumentType($request);

        $document = DB::transaction(function () use ($data) {
            $document = DocumentType::query()->create(Arr::only($data, ['name', 'slug']));
            if (Schema::hasTable('document_type_property_type')) {
                $document->propertyTypes()->sync($data['property_type_ids'] ?? []);
            }
            return $document;
        });

        return response()->json($this->serialize($document), 201);
    }

    public function update(Request $request, DocumentType $documentType)
    {
        $data = $this->validateDocumentType($request, $documentType);
        DB::transaction(function () use ($documentType, $data) {
            $documentType->update(Arr::only($data, ['name', 'slug']));
            if (array_key_exists('property_type_ids', $data)) {
                $documentType->propertyTypes()->sync($data['property_type_ids']);
            }
        });

        return response()->json($this->serialize($documentType));
    }

    public function destroy(DocumentType $documentType)
    {
        $documentType->delete();

        return response()->noContent();
    }

    private function validateDocumentType(Request $request, ?DocumentType $documentType = null): array
    {
        return $request->validate([
            'slug' => [
                $documentType ? 'sometimes' : 'required',
                'string',
                'max:255',
                Rule::unique('document_types', 'slug')->ignore($documentType?->id),
            ],
            'name' => [$documentType ? 'sometimes' : 'required', 'string', 'max:255'],
            'property_type_ids' => ['sometimes', 'array'],
            'property_type_ids.*' => ['integer', 'distinct', 'exists:property_types,id'],
        ], [], ['property_type_ids' => 'Виды недвижимости', 'property_type_ids.*' => 'Вид недвижимости']);
    }

    private function serialize(DocumentType $document): array
    {
        $data = $document->attributesToArray();
        // null is the compatibility contract before the relation migration is installed.
        $data['property_type_ids'] = Schema::hasTable('document_type_property_type')
            ? $document->loadMissing('propertyTypes:id')->propertyTypes->modelKeys() : null;

        return $data;
    }
}
