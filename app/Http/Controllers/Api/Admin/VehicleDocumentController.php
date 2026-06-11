<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class VehicleDocumentController extends Controller
{
    public function syncDocuments(Request $request, $vehicle_id)
    {
        try {
            $vehicle = Vehicle::findOrFail($vehicle_id);

            $validator = Validator::make($request->all(), [
                'documents' => 'required|array|min:1',
                'documents.*.id' => 'nullable|integer|exists:vehicle_documents,id',
                'documents.*.title' => 'required|string|max:255',
                'documents.*.document' => 'nullable|file|mimes:pdf,jpeg,png,jpg|max:10240', // up to 10MB
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $inputDocs = $request->input('documents', []);
            $fileDocs = $request->file('documents', []);

            // Keep track of the document IDs that are sent to prevent deleting them
            $processedIds = [];

            foreach ($inputDocs as $index => $docData) {
                $docId = $docData['id'] ?? null;
                $title = $docData['title'];

                // Retrieve file if it exists at this index
                $file = isset($fileDocs[$index]['document']) ? $fileDocs[$index]['document'] : null;

                if ($docId) {
                    // Update existing document
                    $document = VehicleDocument::where('vehicle_id', $vehicle->id)->findOrFail($docId);
                    $document->title = $title;

                    if ($file) {
                        // Delete old file from storage if exists
                        $rawPath = $document->getRawOriginal('document_path');
                        if ($rawPath && Storage::disk('public')->exists($rawPath)) {
                            Storage::disk('public')->delete($rawPath);
                        }
                        // Store new file
                        $path = $file->store("vehicles/{$vehicle->id}/documents", 'public');
                        $document->document_path = $path;
                    }
                    $document->save();
                    $processedIds[] = $document->id;
                } else {
                    // Create new document
                    if ($file) {
                        $path = $file->store("vehicles/{$vehicle->id}/documents", 'public');
                        $document = VehicleDocument::create([
                            'vehicle_id' => $vehicle->id,
                            'title' => $title,
                            'document_path' => $path
                        ]);
                        $processedIds[] = $document->id;
                    } else {
                        return response()->json([
                            'status' => 400,
                            'message' => "New document at index {$index} requires a file upload."
                        ], 400);
                    }
                }
            }

            // Delete any existing documents that were not passed in the request list (deleted by user)
            $deletedDocs = VehicleDocument::where('vehicle_id', $vehicle->id)
                ->whereNotIn('id', $processedIds)
                ->get();

            foreach ($deletedDocs as $deletedDoc) {
                $rawPath = $deletedDoc->getRawOriginal('document_path');
                if ($rawPath && Storage::disk('public')->exists($rawPath)) {
                    Storage::disk('public')->delete($rawPath);
                }
                $deletedDoc->delete();
            }

            // Fetch the updated list of documents
            $documents = VehicleDocument::where('vehicle_id', $vehicle->id)->get();

            return response()->json([
                'status' => 200,
                'message' => 'Vehicle supplementary documents updated successfully',
                'data' => $documents
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to update vehicle documents',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function deleteDocument($id)
    {
        try {
            $document = VehicleDocument::findOrFail($id);
            $rawPath = $document->getRawOriginal('document_path');
            if ($rawPath && Storage::disk('public')->exists($rawPath)) {
                Storage::disk('public')->delete($rawPath);
            }
            $document->delete();
            return response()->json([
                'status' => 200,
                'message' => 'Vehicle document deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to delete vehicle document',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
