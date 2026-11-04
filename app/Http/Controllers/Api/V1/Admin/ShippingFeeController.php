<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\ShippingFeeService;
use Illuminate\Http\JsonResponse;

/**
 * What the shop charges for delivering to a city.
 *
 * The carrier's table says what the courier charges; the owner sometimes
 * charges something else, and the figure they put in the order form is kept
 * for that city.
 *
 * This is the way back: the owner prices a city for themselves, changes their
 * mind, and the carrier's price stands again. Without it, a city priced free
 * for one order would stay free for every order after it.
 */
class ShippingFeeController extends Controller
{
    /**
     * The cities the shop has priced for itself, and what it charges.
     */
    public function index(ShippingFeeService $fees): JsonResponse
    {
        return response()->json([
            'fees' => collect($fees->overrides())->map(fn ($fee) => (float) $fee)->all(),
        ]);
    }

    /**
     * Go back to the carrier's price for a city.
     */
    public function destroy(string $city, ShippingFeeService $fees): JsonResponse
    {
        $fees->forget($city);

        return response()->json(['fees' => $fees->overrides()]);
    }
}
