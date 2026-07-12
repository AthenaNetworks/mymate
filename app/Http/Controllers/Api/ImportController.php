<?php

namespace App\Http\Controllers\Api;

use App\Enums\ImportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreImportRequest;
use App\Http\Resources\ImportRunResource;
use App\Jobs\RunDudeImportJob;
use App\Models\ImportRun;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * MikroTik "The Dude" import (FR-Dude). Upload a dude.db, choose fresh|upsert + whether
 * to bring chart history, and a background job extracts (Python) and upserts it.
 * Polling `show` drives the progress UI.
 */
class ImportController extends Controller
{
    /** Recent import runs (newest first). */
    public function index(): AnonymousResourceCollection
    {
        return ImportRunResource::collection(ImportRun::query()->latest()->limit(25)->get());
    }

    public function show(ImportRun $import): ImportRunResource
    {
        return new ImportRunResource($import);
    }

    /**
     * Request cancellation. If the run hasn't started, cancel immediately; otherwise
     * flag it and the job stops at its next safe checkpoint (config rolls back; any
     * history already written stays as valid partial data). No-op once terminal.
     */
    public function cancel(ImportRun $import): ImportRunResource
    {
        if (! $import->status->isTerminal()) {
            $import->forceFill(['cancel_requested' => true])->save();

            if ($import->status === ImportStatus::Pending) {
                $import->forceFill(['status' => ImportStatus::Cancelled, 'finished_at' => now()])->save();
            }
        }

        return new ImportRunResource($import->refresh());
    }

    /** Accept the upload, persist it, and dispatch the import job. */
    public function store(StoreImportRequest $request): ImportRunResource
    {
        $file = $request->file('database');

        // Sniff the SQLite magic header so a wrong file fails fast (not mid-job).
        $handle = fopen($file->getRealPath(), 'r');
        $magic = $handle ? (string) fread($handle, 16) : '';
        if ($handle) {
            fclose($handle);
        }
        abort_unless(str_starts_with($magic, 'SQLite format 3'), Response::HTTP_UNPROCESSABLE_ENTITY,
            'That file is not a SQLite database (a dude.db export is expected).');

        // Each run gets its own dir under storage/app/imports/<uuid>/.
        $dir = 'imports/'.Str::uuid()->toString();
        $file->storeAs($dir, 'dude.db');

        $run = ImportRun::create([
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $dir.'/dude.db',
            'mode' => $request->validated('mode'),
            'include_history' => $request->boolean('include_history', true),
            'status' => ImportStatus::Pending,
            'user_id' => $request->user()?->id,
        ]);

        RunDudeImportJob::dispatch($run->id)->onQueue('import');

        return new ImportRunResource($run);
    }
}
