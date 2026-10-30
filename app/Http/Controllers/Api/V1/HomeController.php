<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\HomeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * Everything the homepage needs in a single request: one round trip keeps the
 * first paint fast.
 *
 * The payload itself is assembled and cached by `HomeService`; see that class
 * for what is cached and which writes drop it.
 */
class HomeController extends Controller
{
    public function __construct(private readonly HomeService $home) {}

    public function __invoke(Request $request): JsonResponse
    {
        return response()->json($this->home->payload(App::getLocale()));
    }
}
