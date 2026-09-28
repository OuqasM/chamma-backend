<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BrandResource;
use App\Models\Brand;
use App\Services\CatalogService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Request;

class BrandController extends Controller
{
    public function __construct(private readonly CatalogService $catalog) {}

    public function index(): AnonymousResourceCollection
    {
        return BrandResource::collection($this->catalog->brands(onlyWithProducts: true));
    }

    public function show(Request $request, string $slug): AnonymousResourceCollection
    {
        $brand = Brand::query()
            ->active()
            ->whereSlug($slug)
            ->with('translations')
            ->firstOrFail();

        $filters = ['brand' => $brand->slug, 'sort' => $request->query('sort', 'featured')];

        return ProductResourceCollection::make(
            $this->catalog->paginate($filters),
            $filters,
            ['brand' => new BrandResource($brand)],
        );
    }
}
