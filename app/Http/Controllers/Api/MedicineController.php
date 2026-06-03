<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MedicineRequest;
use App\Http\Resources\MedicineResource;
use App\Models\Medicine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

class MedicineController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeRead($request);
        $query = $this->filteredQuery($request);
        $perPage = min(max($request->integer('per_page', 50), 10), 200);

        return MedicineResource::collection($query->latest('updated_at')->paginate($perPage));
    }

    public function search(Request $request)
    {
        $this->authorizeRead($request);
        $cacheKey = 'medicines:search:' . md5(json_encode($request->query()));
        $perPage = min(max($request->integer('per_page', 25), 10), 100);

        $records = Cache::remember($cacheKey, 60, fn () => $this->filteredQuery($request)->limit($perPage)->get());

        return MedicineResource::collection($records);
    }

    public function show(Request $request, string $medicine)
    {
        $this->authorizeRead($request);
        $record = Medicine::where('uuid', $medicine)->orWhere('id', $medicine)->firstOrFail();

        return new MedicineResource($record);
    }

    public function store(MedicineRequest $request)
    {
        $this->authorizeMutate($request);
        $payload = $request->payload();
        $payload['uuid'] ??= (string) Str::uuid();
        $record = Medicine::create($payload);
        $this->audit($request, 'create', $record, $payload);
        $this->flushMedicineCache();

        return response(new MedicineResource($record), 201);
    }

    public function update(MedicineRequest $request, string $medicine)
    {
        $this->authorizeMutate($request);
        $record = Medicine::where('uuid', $medicine)->orWhere('id', $medicine)->firstOrFail();
        $payload = $request->payload();
        $record->update($payload);
        $this->audit($request, 'update', $record, $payload);
        $this->flushMedicineCache();

        return new MedicineResource($record->fresh());
    }

    public function destroy(Request $request, string $medicine)
    {
        $this->authorizeDelete($request);
        $record = Medicine::where('uuid', $medicine)->orWhere('id', $medicine)->firstOrFail();
        $payload = $record->toArray();
        $request->boolean('force') ? $record->forceDelete() : $record->delete();
        $this->audit($request, $request->boolean('force') ? 'permanent_delete' : 'soft_delete', $record, $payload);
        $this->flushMedicineCache();

        return response()->noContent();
    }

    public function import(Request $request)
    {
        $this->authorizeMutate($request);
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:51200'],
            'mapping' => ['nullable', 'array'],
            'rollback_on_failure' => ['nullable', 'boolean'],
        ]);

        $rows = $this->readImportRows($request->file('file')->getRealPath(), $request->file('file')->getClientOriginalExtension());
        $mapping = $data['mapping'] ?? [];
        $errors = [];
        $created = 0;
        $updated = 0;
        $duplicates = 0;

        $runner = function () use ($rows, $mapping, &$errors, &$created, &$updated, &$duplicates) {
            foreach (array_chunk($rows, 500) as $chunkIndex => $chunk) {
                foreach ($chunk as $rowIndex => $row) {
                    $line = ($chunkIndex * 500) + $rowIndex + 2;
                    $payload = $this->mapImportRow($row, $mapping);
                    $validator = validator($payload, $this->importRules());

                    if ($validator->fails()) {
                        $errors[] = ['row' => $line, 'errors' => $validator->errors()->all(), 'data' => $payload];
                        continue;
                    }

                    $lookup = Medicine::query()
                        ->when($payload['barcode'] ?? null, fn ($query, $barcode) => $query->orWhere('barcode', $barcode))
                        ->when($payload['registration_no'] ?? null, fn ($query, $registration) => $query->orWhere('registration_no', $registration))
                        ->orWhere(function ($query) use ($payload) {
                            $query->where('brand_name', $payload['brand_name'])
                                ->where('generic_name', $payload['generic_name'] ?? null)
                                ->where('strength', $payload['strength'] ?? null);
                        })
                        ->first();

                    $payload['uuid'] ??= $lookup?->uuid ?: (string) Str::uuid();
                    $payload['status'] ??= 'Active';
                    $payload['batch_tracking'] = filter_var($payload['batch_tracking'] ?? true, FILTER_VALIDATE_BOOLEAN);
                    $payload['expiry_tracking'] = filter_var($payload['expiry_tracking'] ?? true, FILTER_VALIDATE_BOOLEAN);

                    if ($lookup) {
                        $duplicates++;
                        $lookup->update($payload);
                        $updated++;
                    } else {
                        Medicine::create($payload);
                        $created++;
                    }
                }
            }
        };

        if ($request->boolean('rollback_on_failure', true)) {
            DB::beginTransaction();
            try {
                $runner();
            } catch (\Throwable $error) {
                DB::rollBack();
                throw $error;
            }
            if ($errors) {
                DB::rollBack();
                return response([
                    'created' => 0,
                    'updated' => 0,
                    'duplicates' => 0,
                    'errors' => $errors,
                    'total_rows' => count($rows),
                    'message' => 'Import validation failed. Fix reported rows and retry.',
                ], 422);
            }
            DB::commit();
        } else {
            $runner();
        }

        $this->flushMedicineCache();

        return [
            'created' => $created,
            'updated' => $updated,
            'duplicates' => $duplicates,
            'errors' => $errors,
            'total_rows' => count($rows),
        ];
    }

    public function categories(Request $request)
    {
        $this->authorizeRead($request);

        return Medicine::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category');
    }

    public function manufacturers(Request $request)
    {
        $this->authorizeRead($request);

        return Medicine::query()->whereNotNull('manufacturer')->distinct()->orderBy('manufacturer')->pluck('manufacturer');
    }

    private function filteredQuery(Request $request)
    {
        $query = Medicine::query();
        $search = trim((string) $request->query('q', $request->query('search', '')));

        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
            $query->where(function ($scope) use ($search, $like) {
                $scope->where('brand_name', 'like', $like)
                    ->orWhere('generic_name', 'like', $like)
                    ->orWhere('barcode', $search)
                    ->orWhere('manufacturer', 'like', $like)
                    ->orWhere('composition', 'like', $like)
                    ->orWhere('registration_no', $search);
            });
        }

        foreach (['brand_name', 'generic_name', 'barcode', 'manufacturer', 'category', 'dosage_form', 'status'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->query($field));
            }
        }

        if ($request->filled('composition')) {
            $query->where('composition', 'like', '%' . $request->query('composition') . '%');
        }

        return $query;
    }

    private function readImportRows(string $path, string $extension): array
    {
        if (in_array(strtolower($extension), ['xlsx', 'xls'], true)) {
            $sheet = IOFactory::load($path)->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, false);
        } else {
            $handle = fopen($path, 'r');
            $rows = [];
            while (($row = fgetcsv($handle)) !== false) {
                $rows[] = $row;
            }
            fclose($handle);
        }

        if (! $rows) {
            return [];
        }

        $headers = array_map(fn ($value) => Str::snake(trim((string) $value)), array_shift($rows));

        return collect($rows)
            ->filter(fn ($row) => collect($row)->filter(fn ($value) => trim((string) $value) !== '')->isNotEmpty())
            ->map(fn ($row) => array_combine($headers, array_pad($row, count($headers), null)))
            ->values()
            ->all();
    }

    private function mapImportRow(array $row, array $mapping): array
    {
        $payload = [];
        $aliases = [
            'brand_name' => ['brand_name', 'brand', 'medicine_name', 'name'],
            'generic_name' => ['generic_name', 'generic'],
            'composition' => ['composition', 'formula'],
            'strength' => ['strength', 'potency'],
            'dosage_form' => ['dosage_form', 'form'],
            'therapeutic_class' => ['therapeutic_class', 'class'],
            'registration_no' => ['registration_no', 'registration', 'drap_no'],
            'pack_size' => ['pack_size', 'pack'],
        ];

        foreach ($this->medicineFields() as $field) {
            $source = $mapping[$field] ?? null;
            $keys = $source ? [$source] : ($aliases[$field] ?? [$field]);
            foreach ($keys as $key) {
                $key = Str::snake($key);
                if (array_key_exists($key, $row)) {
                    $value = is_string($row[$key]) ? trim($row[$key]) : $row[$key];
                    $payload[$field] = $value === '' ? null : $value;
                    break;
                }
            }
        }

        return $payload;
    }

    private function medicineFields(): array
    {
        return [
            'uuid', 'brand_name', 'generic_name', 'composition', 'strength', 'dosage_form',
            'therapeutic_class', 'manufacturer', 'distributor', 'registration_no', 'barcode',
            'pack_size', 'category', 'purchase_price', 'sale_price', 'mrp', 'tax_percentage',
            'reorder_level', 'batch_tracking', 'expiry_tracking', 'status',
        ];
    }

    private function importRules(): array
    {
        return [
            'uuid' => ['nullable', 'uuid'],
            'brand_name' => ['required', 'string', 'max:255'],
            'generic_name' => ['nullable', 'string', 'max:255'],
            'composition' => ['nullable', 'string'],
            'strength' => ['nullable', 'string', 'max:120'],
            'dosage_form' => ['nullable', 'string', 'max:120'],
            'therapeutic_class' => ['nullable', 'string', 'max:180'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'distributor' => ['nullable', 'string', 'max:255'],
            'registration_no' => ['nullable', 'string', 'max:180'],
            'barcode' => ['nullable', 'string', 'max:180'],
            'pack_size' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:180'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'mrp' => ['nullable', 'numeric', 'min:0'],
            'tax_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'reorder_level' => ['nullable', 'integer', 'min:0'],
            'batch_tracking' => ['nullable'],
            'expiry_tracking' => ['nullable'],
            'status' => ['nullable', 'string'],
        ];
    }

    private function authorizeRead(Request $request): void
    {
        abort_unless((new \App\Policies\MedicinePolicy())->viewAny($request->user()), 403);
    }

    private function authorizeMutate(Request $request): void
    {
        abort_unless((new \App\Policies\MedicinePolicy())->mutate($request->user()), 403);
    }

    private function authorizeDelete(Request $request): void
    {
        abort_unless((new \App\Policies\MedicinePolicy())->delete($request->user()), 403);
    }

    private function audit(Request $request, string $action, Medicine $medicine, array $payload): void
    {
        \App\Models\AuditLog::create([
            'uuid' => (string) Str::uuid(),
            'user_name' => optional($request->user())->name ?: 'API User',
            'action' => $action,
            'entity' => 'medicines',
            'entity_uuid' => $medicine->uuid,
            'details' => "{$action} medicine {$medicine->brand_name}",
            'metadata' => $payload,
        ]);
    }

    private function flushMedicineCache(): void
    {
        Cache::flush();
    }
}
