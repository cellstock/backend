<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ImportRefurbedCatalog;
use App\Models\RefurbedCatalogImport;
use App\Models\RefurbedCatalogItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RefurbedCatalogController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'items_count' => RefurbedCatalogItem::query()->count(),
                'categories' => RefurbedCatalogItem::query()
                    ->whereNotNull('main_category')
                    ->where('main_category', '!=', '')
                    ->distinct()
                    ->orderBy('main_category')
                    ->pluck('main_category'),
                'latest_import' => $this->presentImport(
                    RefurbedCatalogImport::query()->latest('id')->first()
                ),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:xlsx,csv,txt',
                'max:'.config('services.refurbed.catalog_max_upload_kb'),
            ],
        ]);

        $file = $data['file'];
        $path = $file->store('refurbed-catalog-imports');

        $import = $this->createImport($request, $file->getClientOriginalName(), $path);

        return response()->json([
            'success' => true,
            'message' => 'The Refurbed catalog was uploaded and queued for import.',
            'data' => ['import' => $this->presentImport($import)],
        ], 202);
    }

    public function storeChunk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'upload_id' => ['required', 'uuid'],
            'chunk_index' => ['required', 'integer', 'min:0'],
            'total_chunks' => ['required', 'integer', 'min:1', 'max:1000'],
            'file_name' => ['required', 'string', 'max:255'],
            'chunk' => ['required', 'file', 'max:1536'],
        ]);
        $extension = strtolower(pathinfo($data['file_name'], PATHINFO_EXTENSION));

        abort_unless(in_array($extension, ['xlsx', 'csv'], true), 422, 'Upload an XLSX or CSV catalog file.');
        abort_if($data['chunk_index'] >= $data['total_chunks'], 422, 'Invalid upload chunk.');

        $chunkDirectory = 'refurbed-catalog-chunks/'
            .$request->user()->id.'/'.$data['upload_id'];
        $data['chunk']->storeAs($chunkDirectory, $data['chunk_index'].'.part');

        if ($data['total_chunks'] > $data['chunk_index'] + 1) {
            return response()->json([
                'success' => true,
                'message' => 'Catalog chunk uploaded.',
                'data' => ['complete' => false],
            ], 202);
        }

        for ($index = 0; $index < $data['total_chunks']; $index++) {
            abort_unless(
                Storage::disk('local')->exists($chunkDirectory.'/'.$index.'.part'),
                422,
                'A catalog upload chunk is missing.'
            );
        }

        $storedPath = 'refurbed-catalog-imports/'.Str::uuid().'.'.$extension;
        Storage::disk('local')->makeDirectory('refurbed-catalog-imports');
        $output = fopen(Storage::disk('local')->path($storedPath), 'wb');

        abort_unless(is_resource($output), 500, 'Unable to assemble the catalog upload.');

        try {
            for ($index = 0; $index < $data['total_chunks']; $index++) {
                $input = fopen(
                    Storage::disk('local')->path($chunkDirectory.'/'.$index.'.part'),
                    'rb'
                );

                abort_unless(is_resource($input), 500, 'Unable to read a catalog upload chunk.');
                stream_copy_to_stream($input, $output);
                fclose($input);
            }
        } finally {
            fclose($output);
            Storage::disk('local')->deleteDirectory($chunkDirectory);
        }

        $import = $this->createImport($request, $data['file_name'], $storedPath);

        return response()->json([
            'success' => true,
            'message' => 'The Refurbed catalog was uploaded and queued for import.',
            'data' => [
                'complete' => true,
                'import' => $this->presentImport($import),
            ],
        ], 202);
    }

    public function show(Request $request, RefurbedCatalogImport $catalogImport): JsonResponse
    {
        abort_unless(
            $request->user()->role?->slug === 'super_admin'
            || $catalogImport->user_id === $request->user()->id,
            403
        );

        return response()->json([
            'success' => true,
            'data' => ['import' => $this->presentImport($catalogImport->refresh())],
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $search = trim($data['search'] ?? '');
        $limit = $data['limit'] ?? 24;

        $paginator = RefurbedCatalogItem::query()
            ->when($data['category'] ?? null, fn ($query, string $category) => $query->where('main_category', $category))
            ->when($search !== '', fn ($query) => $query->where(function ($searchQuery) use ($search): void {
                $searchQuery->when(
                    preg_match('/^\d+$/', $search) === 1,
                    fn ($nested) => $nested->where('instance_id', 'like', $search.'%'),
                    fn ($nested) => $nested->where(function ($textQuery) use ($search): void {
                        $textQuery
                            ->whereFullText(['name', 'name_de', 'main_category'], $search)
                            ->orWhere('name', 'like', $search.'%')
                            ->orWhere('name_de', 'like', $search.'%');
                    })
                );
            }))
            ->when($search !== '', fn ($query) => $query->orderByRaw(
                'CASE WHEN instance_id = ? THEN 0 ELSE 1 END',
                [$search]
            ))
            ->orderBy('instance_id')
            ->paginate($limit, [
                'instance_id',
                'name',
                'name_de',
                'attributes',
                'attributes_de',
                'main_category',
            ], 'page', $data['page'] ?? 1);

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $paginator->items(),
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'total' => $paginator->total(),
                ],
            ],
        ]);
    }

    private function presentImport(?RefurbedCatalogImport $import): ?array
    {
        if (! $import) {
            return null;
        }

        return [
            'id' => $import->id,
            'file_name' => $import->original_name,
            'status' => $import->status,
            'total_rows' => (int) ($import->total_rows ?? 0),
            'imported_rows' => (int) ($import->imported_rows ?? 0),
            'failed_rows' => (int) ($import->failed_rows ?? 0),
            'error_message' => $import->error_message,
            'started_at' => $import->started_at?->toISOString(),
            'completed_at' => $import->completed_at?->toISOString(),
            'created_at' => $import->created_at?->toISOString(),
        ];
    }

    private function createImport(
        Request $request,
        string $originalName,
        string $storedPath
    ): RefurbedCatalogImport {
        try {
            app(\App\Services\Inventory\CatalogUploadValidator::class)->validate(Storage::disk('local')->path($storedPath));
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($storedPath);
            throw $exception;
        }
        $import = RefurbedCatalogImport::query()->create([
            'user_id' => $request->user()->id,
            'original_name' => $originalName,
            'stored_path' => $storedPath,
            'status' => 'queued',
        ]);

        ImportRefurbedCatalog::dispatch($import->id)->afterCommit();

        return $import;
    }
}
