<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\Artwork\ArtworkGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UploadController extends Controller
{
    public function __construct(private readonly ArtworkGenerator $artwork) {}

    /**
     * Admin image upload, stored straight in the document root.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,svg', 'max:4096'],
        ]);

        $file = $request->file('image');
        $name = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
            .'-'.substr(sha1((string) $file->getSize().$file->getClientOriginalName()), 0, 8)
            .'.'.strtolower($file->getClientOriginalExtension());

        $path = $file->storeAs('uploads', $name, ['disk' => config('chamma.disk')]);

        return response()->json([
            'path' => $path,
            'url' => asset_path($path),
        ], 201);
    }

    /**
     * Renders a matching bottle shot for a product that has no photography yet,
     * so an admin can never create a product with an empty card.
     */
    public function artwork(Request $request): JsonResponse
    {
        $data = $request->validate([
            'shape' => ['required', 'string', 'in:flacon,jar,mist,tube,dropper,carton'],
            'tone' => ['required', 'string', 'in:blush,rose,amber,espresso,ivory,plum,jade,noir'],
            'size' => ['nullable', 'string', 'max:60'],
            'label' => ['nullable', 'string', 'max:60'],
        ]);

        $name = Str::random(12);
        $path = 'products/'.$name.'-0.svg';

        $svg = $this->artwork->product([
            'shape' => $data['shape'],
            'tone' => $data['tone'],
            'size' => $data['size'] ?? '',
            'label' => $data['label'] ?? '',
        ]);

        Storage::disk(config('chamma.disk'))->put($path, $svg);

        return response()->json([
            'path' => $path,
            'url' => asset_path($path),
        ], 201);
    }
}
