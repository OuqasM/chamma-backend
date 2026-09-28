<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\CatalogService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function __construct(private readonly CatalogService $catalog) {}

    public function index(): AnonymousResourceCollection
    {
        return CategoryResource::collection($this->catalog->categories(onlyWithProducts: true));
    }

    public function show(Request $request, string $slug): AnonymousResourceCollection
    {
        $category = Category::query()
            ->active()
            ->whereSlug($slug)
            ->with('translations')
            ->firstOrFail();

        $filters = ['category' => $category->slug, 'sort' => $request->query('sort', 'featured')];

        return ProductResourceCollection::make(
            $this->catalog->paginate($filters),
            $filters,
            ['category' => new CategoryResource($category)],
        );
    }
}
