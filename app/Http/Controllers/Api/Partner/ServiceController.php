<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Api\Partner\Concerns\ResolvesSalon;
use App\Http\Controllers\Controller;
use App\Models\Salon;
use App\Models\SalonCategory;
use App\Models\SalonService;
use App\Models\ServiceCategory;
use App\Models\ServiceName;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class ServiceController extends Controller
{
    use ResolvesSalon;

    #[OA\Get(
        path: '/api/partner/salons/{salon}/service-options',
        operationId: 'partnerServiceOptions',
        tags: ['Partner Services'],
        summary: 'Dropdown data. Service names already added to this salon are left out (add "Others" in the app)',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'salon', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'service_id', in: 'query', description: 'Edit screen: keep this service\'s own name in the list', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [new OA\Response(response: 200, description: 'Options'), new OA\Response(response: 404, description: 'Salon not found')]
    )]
    public function options(Request $request, int $salon)
    {
        $salon = $this->ownedSalon($request, $salon);

        $request->validate(['service_id' => 'nullable|integer']);

        // A service name can be added only once per salon, so hide the ones already used.
        $used = $salon->services()
            ->whereNotNull('service_name_id')
            ->when($request->filled('service_id'), fn ($q) => $q->where('id', '!=', $request->service_id))
            ->pluck('service_name_id')
            ->all();

        $categories = ServiceCategory::where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get()
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])
            ->all();

        $serviceNames = ServiceName::where('is_active', true)
            ->whereNotIn('id', $used)
            ->orderBy('sort_order')->orderBy('name')
            ->get()
            ->map(fn ($n) => ['id' => $n->id, 'name' => $n->name])
            ->all();

        return response()->json([
            'status' => true,
            'data'   => [
                'categories'    => $categories,
                'my_categories' => $salon->categories()
                    ->whereNull('service_category_id')
                    ->orderBy('name')
                    ->get()
                    ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])
                    ->all(),
                'service_names' => $serviceNames,
                'durations'     => collect(SalonService::DURATIONS)
                    ->map(fn ($m) => ['value' => $m, 'label' => SalonService::durationLabel($m)])->all(),
                'for_whom'      => collect(SalonService::FOR_WHOM)
                    ->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values()->all(),
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/partner/salons/{salon}/services',
        operationId: 'partnerServiceList',
        tags: ['Partner Services'],
        summary: 'Services tab (search, category chip, status filter)',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'salon', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'category_id', in: 'query', description: 'Salon category id (from the categories API)', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['all', 'active', 'hidden'])),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', example: 20)),
        ],
        responses: [new OA\Response(response: 200, description: 'Paginated services'), new OA\Response(response: 404, description: 'Salon not found')]
    )]
    public function index(Request $request, int $salon)
    {
        $salon = $this->ownedSalon($request, $salon);

        $request->validate([
            'search'      => 'nullable|string|max:100',
            'category_id' => 'nullable|integer',
            'status'      => 'nullable|in:all,active,hidden',
            'per_page'    => 'nullable|integer|min:1|max:50',
        ]);

        $services = $salon->services()
            ->with(['category'])
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%' . addcslashes($request->search, '%_\\') . '%'))
            ->when($request->filled('category_id'), fn ($q) => $q->where('salon_category_id', $request->category_id))
            ->when($request->status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->status === 'hidden', fn ($q) => $q->where('is_active', false))
            ->latest('id')
            ->paginate($request->integer('per_page', 20));

        return response()->json([
            'status' => true,
            'data'   => $services->getCollection()->map->toApiArray()->all(),
            'meta'   => [
                'current_page' => $services->currentPage(),
                'last_page'    => $services->lastPage(),
                'total'        => $services->total(),
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/partner/salons/{salon}/services/{service}',
        operationId: 'partnerServiceShow',
        tags: ['Partner Services'],
        summary: 'Service details',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'salon', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'service', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [new OA\Response(response: 200, description: 'Service'), new OA\Response(response: 404, description: 'Not found')]
    )]
    public function show(Request $request, int $salon, int $service)
    {
        $service = $this->ownedSalon($request, $salon)->services()->with(['category'])->findOrFail($service);

        return response()->json(['status' => true, 'data' => $service->toApiArray()]);
    }

    #[OA\Post(
        path: '/api/partner/salons/{salon}/services',
        operationId: 'partnerServiceCreate',
        tags: ['Partner Services'],
        summary: 'Create service (category is created automatically)',
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'salon', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(properties: [
                new OA\Property(property: 'category_id', type: 'integer', description: 'Master category id (categories list)'),
                new OA\Property(property: 'salon_category_id', type: 'integer', description: 'One of this salon\'s own categories (my_categories list)'),
                new OA\Property(property: 'other_category_name', type: 'string', description: 'Only when category is "Others"'),
                new OA\Property(property: 'service_name_id', type: 'integer', description: 'Service name id. Leave empty when "Others"'),
                new OA\Property(property: 'other_service_name', type: 'string', description: 'Only when service is "Others"'),
                new OA\Property(property: 'duration_minutes', type: 'integer', example: 30),
                new OA\Property(property: 'for_whom', type: 'string', enum: ['men', 'women', 'kids', 'everyone']),
                new OA\Property(property: 'price', type: 'number', example: 299),
                new OA\Property(property: 'cover_image', type: 'string', format: 'binary'),
            ])
        )),
        responses: [
            new OA\Response(response: 201, description: 'Created'),
            new OA\Response(response: 422, description: 'Validation error / duplicate'),
        ]
    )]
    public function store(Request $request, int $salon)
    {
        $salon = $this->ownedSalon($request, $salon);
        $data  = $this->validated($request, false, $salon->id);

        $stored = null;

        try {
            $service = DB::transaction(function () use ($request, $salon, $data, &$stored) {
                $category = $this->resolveCategory($salon, $data);
                $name     = $this->resolveName($data);

                $this->ensureUnique($salon->id, $name, $data['service_name_id'] ?? null);

                $attrs = [
                    'salon_id'            => $salon->id,
                    'salon_category_id'   => $category->id,
                    'service_name_id'     => $data['service_name_id'] ?? null,
                    'name'                => $name,
                    'duration_minutes'    => $data['duration_minutes'],
                    'for_whom'            => $data['for_whom'],
                    'price'               => $data['price'],
                    'is_active'           => true,
                ];

                if ($request->hasFile('cover_image')) {
                    $attrs['cover_image'] = $stored = $request->file('cover_image')
                        ->store("salons/{$salon->id}/services", 'public');
                }

                return SalonService::create($attrs);
            });
        } catch (\Throwable $e) {
            if ($stored) {
                Storage::disk('public')->delete($stored);
            }

            // double tap on Create: the unique index caught the second request
            if ($e instanceof QueryException && (string) $e->getCode() === '23000') {
                throw ValidationException::withMessages([
                    'service_name_id' => 'This service is already added to your salon',
                ]);
            }

            throw $e;
        }

        return response()->json([
            'status'  => true,
            'message' => 'Service created',
            'data'    => $service->load(['category'])->toApiArray(),
        ], 201);
    }

    #[OA\Post(
        path: '/api/partner/salons/{salon}/services/{service}',
        operationId: 'partnerServiceUpdate',
        tags: ['Partner Services'],
        summary: 'Edit service (send only the fields that changed)',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'salon', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'service', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(properties: [
                new OA\Property(property: 'category_id', type: 'integer'),
                new OA\Property(property: 'salon_category_id', type: 'integer'),
                new OA\Property(property: 'other_category_name', type: 'string'),
                new OA\Property(property: 'service_name_id', type: 'integer'),
                new OA\Property(property: 'other_service_name', type: 'string'),
                new OA\Property(property: 'duration_minutes', type: 'integer', example: 30),
                new OA\Property(property: 'for_whom', type: 'string', enum: ['men', 'women', 'kids', 'everyone']),
                new OA\Property(property: 'price', type: 'number', example: 299),
                new OA\Property(property: 'cover_image', type: 'string', format: 'binary'),
            ])
        )),
        responses: [
            new OA\Response(response: 200, description: 'Changes saved'),
            new OA\Response(response: 422, description: 'Validation error / duplicate'),
        ]
    )]
    public function update(Request $request, int $salon, int $service)
    {
        $salon   = $this->ownedSalon($request, $salon);
        $service = $salon->services()->findOrFail($service);
        $data    = $this->validated($request, true, $salon->id);

        $oldUpload = $service->cover_image;
        $newUpload = null;

        try {
            DB::transaction(function () use ($request, $salon, $service, $data, &$newUpload) {
                $attrs = [];

                if (!empty($data['salon_category_id']) || !empty($data['category_id']) || !empty($data['other_category_name'])) {
                    $attrs['salon_category_id'] = $this->resolveCategory($salon, $data)->id;
                }

                if (!empty($data['service_name_id']) || !empty($data['other_service_name'])) {
                    $attrs['name']            = $this->resolveName($data);
                    $attrs['service_name_id'] = $data['service_name_id'] ?? null;
                }

                foreach (['duration_minutes', 'for_whom', 'price'] as $field) {
                    if (array_key_exists($field, $data)) {
                        $attrs[$field] = $data[$field];
                    }
                }

                if ($request->hasFile('cover_image')) {
                    $attrs['cover_image'] = $newUpload = $request->file('cover_image')
                        ->store("salons/{$salon->id}/services", 'public');
                }

                $this->ensureUnique(
                    $salon->id,
                    $attrs['name'] ?? $service->name,
                    array_key_exists('service_name_id', $attrs) ? $attrs['service_name_id'] : $service->service_name_id,
                    $service->id
                );

                $service->update($attrs);
            });
        } catch (\Throwable $e) {
            if ($newUpload) {
                Storage::disk('public')->delete($newUpload);
            }
            throw $e;
        }

        // the old uploaded file is no longer used
        if ($oldUpload && $service->cover_image !== $oldUpload) {
            Storage::disk('public')->delete($oldUpload);
        }

        return response()->json([
            'status'  => true,
            'message' => 'Changes saved',
            'data'    => $service->fresh(['category'])->toApiArray(),
        ]);
    }

    #[OA\Patch(
        path: '/api/partner/salons/{salon}/services/{service}/visibility',
        operationId: 'partnerServiceVisibility',
        tags: ['Partner Services'],
        summary: 'Hide / unhide a service',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'salon', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'service', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['is_active'],
            properties: [new OA\Property(property: 'is_active', type: 'boolean', example: false)]
        )),
        responses: [new OA\Response(response: 200, description: 'Updated')]
    )]
    public function visibility(Request $request, int $salon, int $service)
    {
        $service = $this->ownedSalon($request, $salon)->services()->findOrFail($service);
        $data    = $request->validate(['is_active' => 'required|boolean']);

        $service->update(['is_active' => (bool) $data['is_active']]);

        return response()->json([
            'status'  => true,
            'message' => $service->is_active
                ? 'Service is visible to customers again'
                : 'Service hidden successfully. You can view it anytime from filter.',
            'data'    => $service->load(['category'])->toApiArray(),
        ]);
    }

    #[OA\Delete(
        path: '/api/partner/salons/{salon}/services/{service}',
        operationId: 'partnerServiceDelete',
        tags: ['Partner Services'],
        summary: 'Delete service permanently',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'salon', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'service', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Deleted'),
            new OA\Response(response: 409, description: 'Has upcoming bookings'),
        ]
    )]
    public function destroy(Request $request, int $salon, int $service)
    {
        $service = $this->ownedSalon($request, $salon)->services()->findOrFail($service);

        if ($service->hasUpcomingBookings()) {
            return response()->json([
                'status'  => false,
                'code'    => 'HAS_UPCOMING_BOOKINGS',
                'message' => "Can't delete this service. It has upcoming bookings. You can delete it after completing all upcoming bookings.",
            ], 409);
        }

        $upload = $service->cover_image;
        $service->delete();

        if ($upload) {
            Storage::disk('public')->delete($upload);
        }

        return response()->json(['status' => true, 'message' => 'Service deleted']);
    }

    #[OA\Post(
        path: '/api/partner/salons/{salon}/services/{service}/move',
        operationId: 'partnerServiceMove',
        tags: ['Partner Services'],
        summary: 'Move service to another category of the same salon',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'salon', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'service', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['category_id'],
            properties: [new OA\Property(property: 'category_id', type: 'integer', description: 'Salon category id (from the categories API)')]
        )),
        responses: [new OA\Response(response: 200, description: 'Moved'), new OA\Response(response: 422, description: 'Same category / duplicate')]
    )]
    public function move(Request $request, int $salon, int $service)
    {
        $salon   = $this->ownedSalon($request, $salon);
        $service = $salon->services()->findOrFail($service);
        $data    = $request->validate(['category_id' => 'required|integer']);

        $target = $salon->categories()->findOrFail($data['category_id']);

        if ($target->id === $service->salon_category_id) {
            throw ValidationException::withMessages(['category_id' => 'Service is already in this category']);
        }

        $service->update(['salon_category_id' => $target->id]);

        return response()->json([
            'status'  => true,
            'message' => 'Service moved successfully',
            'data'    => $service->fresh(['category'])->toApiArray(),
        ]);
    }

    /* ------------------------------------------------------------------
     | Helpers
     * ------------------------------------------------------------------ */

    private function validated(Request $request, bool $update, int $salonId): array
    {
        $required = $update ? 'sometimes' : 'required';

        $validator = Validator::make($request->all(), [
            'salon_category_id'   => ['nullable', 'integer', Rule::exists('salon_categories', 'id')->where('salon_id', $salonId)],
            'category_id'         => 'nullable|integer|exists:service_categories,id',
            'other_category_name' => 'nullable|string|max:60',
            'service_name_id'     => 'nullable|integer|exists:service_names,id',
            'other_service_name'  => 'nullable|string|max:120',
            'duration_minutes'    => [$required, 'integer', Rule::in(SalonService::DURATIONS)],
            'for_whom'            => [$required, Rule::in(array_keys(SalonService::FOR_WHOM))],
            'price'               => [$required, 'numeric', 'min:0', 'max:99999999.99'],
            'cover_image'         => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $validator->after(function ($v) use ($request, $update) {
            if (!$update && !$request->filled('salon_category_id') && !$request->filled('category_id') && !$request->filled('other_category_name')) {
                $v->errors()->add('category_id', 'Select a category or enter a category name');
            }

            if (!$update && !$request->filled('service_name_id') && !$request->filled('other_service_name')) {
                $v->errors()->add('service_name_id', 'Select a service name or enter one');
            }
        });

        return $validator->validate();
    }

    /** Master category chosen: find or create this salon's own copy. "Others": find or create by the typed name. */
    private function resolveCategory(Salon $salon, array $data): SalonCategory
    {
        // one of this salon's own categories (e.g. created earlier with "Others")
        if (!empty($data['salon_category_id'])) {
            return $salon->categories()->findOrFail($data['salon_category_id']);
        }

        if (!empty($data['category_id'])) {
            $master = ServiceCategory::findOrFail($data['category_id']);

            return SalonCategory::firstOrCreate(
                ['salon_id' => $salon->id, 'name' => $master->name],
                ['service_category_id' => $master->id]
            );
        }

        return SalonCategory::firstOrCreate([
            'salon_id' => $salon->id,
            'name'     => trim($data['other_category_name']),
        ]);
    }

    private function resolveName(array $data): string
    {
        if (!empty($data['service_name_id'])) {
            return ServiceName::findOrFail($data['service_name_id'])->name;
        }

        return trim($data['other_service_name']);
    }

    /** One service name can be added only once per salon (by master id or by name, any category). */
    private function ensureUnique(int $salonId, string $name, ?int $serviceNameId, ?int $ignoreId = null): void
    {
        $exists = SalonService::where('salon_id', $salonId)
            ->where(function ($q) use ($name, $serviceNameId) {
                $q->where('name', $name);

                if ($serviceNameId) {
                    $q->orWhere('service_name_id', $serviceNameId);
                }
            })
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                ($serviceNameId ? 'service_name_id' : 'other_service_name') => 'This service is already added to your salon',
            ]);
        }
    }
}