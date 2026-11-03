<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CatalogService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Request;

class OfferController extends Controller
{
    public function __construct(private readonly CatalogService $catalog) {}

    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $filters = ['on_sale' => true, 'sort' => 'featured'];

        return ProductResourceCollection::make(
            $this->catalog->paginate($filters, $this->perPage($request)),
            $filters,
        );
    }
}
