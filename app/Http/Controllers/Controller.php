<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * The page size a storefront listing asks for.
     *
     * `per_page` comes straight off the query string, and `paginate(0)` throws,
     * so the number is floored at one: a bad value in a URL is a 500 nobody
     * asked for. The default is the shop's own page size.
     */
    protected function perPage(Request $request): int
    {
        return max(1, (int) $request->integer('per_page', (int) config('chamma.catalog.per_page')));
    }
}
