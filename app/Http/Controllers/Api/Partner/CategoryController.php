<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Api\Partner\Concerns\ResolvesSalon;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

class CategoryController extends Controller
{
    use ResolvesSalon;

    #[OA\Get(
        path: '/api/partner/salons/{salon}/categories',
        operationId: 'partnerCategoryList',
        tags: ['Partner Services'],
        summary: 'Categories tab (with service count)',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'salon', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'with_services', in: 'query', description: '1 = include the services of each category', schema: new OA\Schema(type: 'integer', enum: [0, 1])),
        ],
        responses: [new OA\Response(response: 200, description: 'Categories'), new OA\Response(response: 404, description: 'Salon not found')]
    )]
    public function index(Request $request, int $salon)
    {
        $salon = $this->ownedSalon($request, $salon);

        $request->validate([
            'search'        => 'nullable|string|max:100',
            'with_services' => 'nullable|boolean',
        ]);

        $categories = $salon->categories()
            ->withCount('services')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%' . addcslashes($request->search, '%_\\') . '%'))
            ->when($request->boolean('with_services'), fn ($q) => $q->with([
                'services' => fn ($s) => $s->latest('id'),
                'services.category',
            ]))
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => true,
            'data'   => $categories->map(function ($c) use ($request) {
                $row = ['id' => $c->id, 'name' => $c->name, 'services_count' => $c->services_count];

                if ($request->boolean('with_services')) {
                    $row['services'] = $c->services->map->toApiArray()->all();
                }

                return $row;
            })->all(),
        ]);
    }

    #[OA\Delete(
        path: '/api/partner/salons/{salon}/categories/{category}',
        operationId: 'partnerCategoryDelete',
        tags: ['Partner Services'],
        summary: 'Delete category and ALL its services',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'salon', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'category', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Deleted'),
            new OA\Response(response: 409, description: 'A service has upcoming bookings'),
        ]
    )]
    public function destroy(Request $request, int $salon, int $category)
    {
        $category = $this->ownedSalon($request, $salon)->categories()->with('services')->findOrFail($category);

        if ($category->services->contains(fn ($s) => $s->hasUpcomingBookings())) {
            return response()->json([
                'status'  => false,
                'code'    => 'HAS_UPCOMING_BOOKINGS',
                'message' => "Can't delete this category. Some of its services have upcoming bookings.",
            ], 409);
        }

        $files = $category->services->pluck('cover_image')->filter()->all();

        DB::transaction(function () use ($category) {
            $category->services()->delete();
            $category->delete();
        });

        if ($files) {
            Storage::disk('public')->delete($files);
        }

        return response()->json(['status' => true, 'message' => 'Category deleted permanently']);
    }
}