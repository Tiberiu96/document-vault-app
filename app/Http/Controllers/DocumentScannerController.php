<?php

namespace App\Http\Controllers;

use App\Http\Requests\DocumentScanRequest;
use App\Http\Resources\DraftUploadResource;
use App\Models\Document;
use App\Services\Documents\DocumentLibrary;
use App\Services\Documents\DocumentProcessingService;
use App\Services\Documents\DraftSource;
use App\Services\Documents\ProcessingQuotaExceeded;
use App\Services\Documents\ScanPdf;
use App\Services\PlanLimitsService;
use App\Services\WebsiteContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DocumentScannerController extends Controller
{
    public function index(Request $request)
    {
        $workspace = $request->attributes->get('workspace');
        Gate::authorize('create', [Document::class, $workspace]);

        return view('documents.scanner', [
            ...app(WebsiteContext::class)->forPage($request, 'documents'), 'section' => 'scan', 'workspace' => $workspace,
            'workspaces' => $request->user()->workspaces()->get()->filter(fn ($w) => Gate::allows('view', $w)),
            'limits' => app(PlanLimitsService::class)->forWorkspace($workspace),
        ]);
    }

    public function store(DocumentScanRequest $request, ScanPdf $scanner, DocumentLibrary $library, DocumentProcessingService $processing)
    {
        $workspace = $request->attributes->get('workspace');
        Gate::authorize('create', [Document::class, $workspace]);
        $record = $scanner->withPdf($request->file('pages'), function ($file) use ($request, $workspace, $library, $processing) {
            $document = $library->upload($request->user(), $workspace, $file, ['title' => __('scanner.default_title').' '.now(config('vault.display_timezone'))->format('d.m.Y H:i'), 'analyze_with_ai' => $request->boolean('analyze_with_ai')], draft: true);
            try {
                $processing->request($request->user(), $document, 'ocr');
            } catch (ValidationException $exception) {
                $document->forceFill(['ocr_status' => $exception instanceof ProcessingQuotaExceeded ? 'quota_exceeded' : 'failed'])->save();
            }

            app(DraftSource::class)->releaseOrQueue($document->id, $document->workspace_id);
            if ($request->boolean('retain_file')) {
                return response(file_get_contents($file->getPathname()), 201, [
                    'Content-Type' => 'application/pdf', 'Cache-Control' => 'no-store, private',
                    'X-Vault-Upload' => base64_encode(json_encode((new DraftUploadResource($document))->resolve($request), JSON_THROW_ON_ERROR)),
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            }

            return $document;
        }, $workspace);

        if ($record instanceof Response) {
            return $record;
        }

        return response()->json(['redirect' => route($record->ai_requested ? 'documents.show' : 'documents.edit', ['document' => $record->id, ...($record->ai_requested ? ['review' => 1] : [])])], 201);
    }
}
