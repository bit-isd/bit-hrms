<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Staff;
use App\Models\StaffConfidentialDocument;
use App\Models\StaffPerformanceRating;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class StaffConfidentialController extends Controller
{
    public function index(Request $request, Staff $staff): JsonResponse
    {
        $this->authorizeConfidential($request, $staff);
        return response()->json([
            'success' => true,
            'data' => [
                'ratings' => $staff->performanceRatings()->get(),
                'documents' => $staff->confidentialDocuments()->get()->map(fn($doc) => $doc->makeHidden(['storage_path'])),
            ],
        ]);
    }

    public function storeRating(Request $request, Staff $staff): JsonResponse
    {
        $this->authorizeConfidential($request, $staff);
        $data = $request->validate([
            'rating_year' => 'required|integer|min:2000|max:' . now()->year,
            'rating' => 'required|numeric|min:1|max:5',
            'comments' => 'nullable|string|max:5000',
        ]);
        $existing = StaffPerformanceRating::where('staff_id', $staff->id)->where('rating_year', $data['rating_year'])->first();
        $rating = StaffPerformanceRating::updateOrCreate(
            ['staff_id' => $staff->id, 'rating_year' => $data['rating_year']],
            $data + ['entered_by' => $request->user()->id]
        );
        AuditLogService::log(
            $existing ? 'PERFORMANCE_RATING_UPDATED' : 'PERFORMANCE_RATING_RECORDED',
            'CONFIDENTIAL',
            StaffPerformanceRating::class,
            $rating->id,
            $existing ? ['rating_year' => $existing->rating_year, 'rating' => $existing->rating] : null,
            [
                'staff_id' => $staff->id,
                'rating_year' => $rating->rating_year,
                'rating' => $rating->rating,
            ]
        );
        return response()->json(['success' => true, 'data' => $rating], 201);
    }

    public function destroyRating(Request $request, Staff $staff, StaffPerformanceRating $rating): JsonResponse
    {
        $this->authorizeConfidential($request, $staff);
        abort_unless($rating->staff_id === $staff->id, 404);
        $previous = $rating->only(['staff_id', 'rating_year', 'rating', 'comments']);
        $rating->delete();
        AuditLogService::log('PERFORMANCE_RATING_DELETED', 'CONFIDENTIAL', StaffPerformanceRating::class, $rating->id, $previous, null);
        return response()->json(['success' => true]);
    }

    public function storeDocument(Request $request, Staff $staff): JsonResponse
    {
        $this->authorizeConfidential($request, $staff);
        $data = $request->validate([
            'title' => 'required|string|max:190',
            'category' => 'required|string|max:80',
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240',
        ]);
        $file = $request->file('file');
        $path = $this->privateDisk()->putFile("confidential/staff/{$staff->id}", $file);
        $document = StaffConfidentialDocument::create([
            'staff_id' => $staff->id,
            'title' => $data['title'],
            'category' => $data['category'],
            'storage_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'uploaded_by' => $request->user()->id,
        ]);
        AuditLogService::log('CONFIDENTIAL_DOCUMENT_UPLOADED', 'CONFIDENTIAL', StaffConfidentialDocument::class, $document->id, null, [
            'staff_id' => $staff->id,
            'title' => $document->title,
            'category' => $document->category,
            'file_name' => $document->file_name,
        ]);
        return response()->json(['success' => true, 'data' => $document->makeHidden(['storage_path'])], 201);
    }

    public function downloadDocument(Request $request, Staff $staff, StaffConfidentialDocument $document)
    {
        $this->authorizeConfidential($request, $staff);
        abort_unless($document->staff_id === $staff->id, 404);
        abort_unless($this->privateDisk()->exists($document->storage_path), 404);
        AuditLogService::log('CONFIDENTIAL_DOCUMENT_ACCESSED', 'CONFIDENTIAL', StaffConfidentialDocument::class, $document->id, null, ['staff_id' => $staff->id]);
        $disk = $this->privateDisk();
        return response()->streamDownload(function () use ($disk, $document) {
            $stream = $disk->readStream($document->storage_path);
            if ($stream !== false) {
                fpassthru($stream);
                fclose($stream);
            }
        }, $document->file_name, ['Content-Type' => $document->mime_type]);
    }

    public function destroyDocument(Request $request, Staff $staff, StaffConfidentialDocument $document): JsonResponse
    {
        $this->authorizeConfidential($request, $staff);
        abort_unless($document->staff_id === $staff->id, 404);
        $previous = $document->only(['title', 'category', 'file_name']);
        $this->privateDisk()->delete($document->storage_path);
        $document->delete();
        AuditLogService::log('CONFIDENTIAL_DOCUMENT_DELETED', 'CONFIDENTIAL', StaffConfidentialDocument::class, $document->id, $previous, null);
        return response()->json(['success' => true]);
    }

    private function authorizeConfidential(Request $request, Staff $staff): void
    {
        abort_unless(in_array($request->user()?->role?->code, ['SUPER_ADMIN', 'HR_ADMIN'], true), 403, 'Confidential personnel records are restricted to HR administrators.');
        $validated = $request->validate(['password' => 'required|string|max:255']);
        if (!Hash::check($validated['password'], $request->user()->password)) {
            abort(422, 'The administrator password is incorrect.');
        }
        AuditLogService::log('CONFIDENTIAL_FOLDER_ACCESSED', 'CONFIDENTIAL', Staff::class, $staff->id, null, ['staff_number' => $staff->staff_number]);
    }

    private function privateDisk()
    {
        return Storage::build(['driver' => 'local', 'root' => storage_path('app/private')]);
    }
}
