<?php

namespace App\Models;

use App\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Brand extends Model
{
    use HasFactory;
    use HasTranslations;

    protected string $translationForeignKey = 'brand_id';

    protected $fillable = [
        'name',
        'slug',
        'logo',
        'origin',
        'position',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'position' => 'integer',
    ];

    public function brandTranslations(): HasMany
    {
        return $this->translations();
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * The owner's order: edited positions first (position, then name for
     * legitimate ties), never-numbered brands — the column default, 0 — last in
     * creation order. Without that split the default jumps to the front of the
     * list, so a brand added after the shelf was numbered would steal slot one.
     */
    public function scopeOrdered($query)
    {
        return $query
            ->orderByRaw('position = 0')
            ->orderBy('position')
            ->orderByRaw('case when position = 0 then id else 0 end')
            ->orderBy('name')
            ->orderBy('id');
    }
}
