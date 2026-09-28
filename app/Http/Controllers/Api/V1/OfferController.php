<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Services\CatalogService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OfferController extends Controller
{
    public function __construct(private readonly CatalogService $catalog) {}

    public function __invoke(): AnonymousResourceCollection
    {
        $filters = ['on_sale' => true, 'sort' => 'featured'];

        return ProductResourceCollection::make(
            $this->catalog->paginate($filters),
            $filters,
        );
    }
}
