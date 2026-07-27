<?php

namespace App\Http\Controllers;

use App\Models\DocumentType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DocumentTypeController extends Controller
{
    public function index()
    {
        return response()->json(DocumentType::query()->orderBy('name')->get());
    }

    public function show(DocumentType $documentType)
    {
        return response()->json($documentType);
    }

    public function store(Request $request)
    {
        $data = $this->validateDocumentType($request);

        return response()->json(DocumentType::query()->create($data), 201);
    }

    public function update(Request $request, DocumentType $documentType)
    {
        $documentType->update($this->validateDocumentType($request, $documentType));

        return response()->json($documentType);
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
        ]);
    }
}
