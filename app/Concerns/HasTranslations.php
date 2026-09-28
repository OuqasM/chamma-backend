<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\App;

/**
 * Adds translatable attributes to a model backed by a *translations table.
 *
 * Two shapes are supported, picked automatically from the model:
 *  - a dedicated table (brand_translations, category_translations,
 *    product_translations) linked by a plain foreign key;
 *  - a polymorphic table (translations) linked by translatable_id/type.
 *
 * Adding a language later only means: insert a new row in config('chamma.locales')
 * and add a translation row — no schema or code change.
 */
trait HasTranslations
{
    public function translationModel(): string
    {
        return static::class.'Translation';
    }

    public function translations(): HasMany|MorphMany
    {
        if ($this->usesDedicatedTranslationTable()) {
            return $this->hasMany($this->translationModel());
        }

        return $this->morphMany($this->translationModel(), 'translatable');
    }

    /**
     * Dedicated tables use a conventional foreign key, so the relation must not
     * be polymorphic. Polimorphic support is kept for future shared tables.
     */
    protected function usesDedicatedTranslationTable(): bool
    {
        return property_exists($this, 'translationForeignKey');
    }

    public function translation(string $locale): ?object
    {
        return $this->translations->firstWhere('locale', $locale);
    }

    /**
     * Translated value with an explicit locale → fallback chain:
     * requested locale → default locale → fallback locale → first available.
     */
    public function translated(string $attribute, ?string $locale = null): mixed
    {
        $locale ??= App::getLocale();

        $candidates = array_values(array_unique(array_filter([
            $locale,
            config('chamma.default_locale'),
            config('chamma.fallback_locale'),
        ])));

        $translations = $this->translations;

        foreach ($candidates as $candidate) {
            $value = $translations
                ->firstWhere('locale', $candidate)
                ?->{$attribute} ?? null;

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return $translations->first()?->{$attribute};
    }

    public function name(?string $locale = null): string
    {
        return (string) $this->translated('name', $locale);
    }

    /**
     * The one canonical name, as the admin typed it: the row for the fallback
     * locale (English) holds it, and `name()` above falls back to that row for
     * every other locale. This is what an edit form should bind to, rather than
     * a locale-dependent `name()`.
     */
    public function singleName(): string
    {
        $canonical = $this->translations
            ->firstWhere('locale', config('chamma.fallback_locale'))?->name;

        return (string) ($canonical ?: $this->name());
    }

    public function description(?string $locale = null): ?string
    {
        return $this->translated('description', $locale);
    }

    public function slug(?string $locale = null): string
    {
        return (string) ($this->translated('slug', $locale) ?: $this->slug);
    }

    /**
     * Eager-load every translation, ordered so the current locale is first.
     *
     * Uses a CASE expression rather than MySQL's field() so the same query
     * runs on SQLite (tests) and Postgres.
     */
    public function scopeWithAllTranslations(Builder $query, ?string $locale = null): Builder
    {
        $locale ??= App::getLocale();

        $candidates = array_values(array_unique(array_filter([
            $locale,
            config('chamma.default_locale'),
            config('chamma.fallback_locale'),
        ])));

        $case = 'case locale';
        $bindings = [];

        foreach ($candidates as $index => $candidate) {
            $case .= ' when ? then '.$index;
            $bindings[] = $candidate;
        }

        $case .= ' else '.count($candidates).' end';

        return $query->with(['translations' => fn ($q) => $q->orderByRaw($case, $bindings)]);
    }

    /**
     * True when this model's translations table stores its own slug.
     *
     * Products do; brands and categories share the canonical slug on the
     * parent row instead, so their translations table has no slug column.
     */
    public function translationTableHasSlug(): bool
    {
        static $cache = [];

        $key = $this->translationModel();

        return $cache[$key] ??= Schema::hasColumn(
            (new $key)->getTable(),
            'slug',
        );
    }

    /**
     * Resolve a record from a URL slug: the localised slug first, then the
     * canonical slug so old/shared links never 404.
     *
     * The whole predicate is grouped so it composes safely with other
     * conditions instead of leaking a stray orWhere.
     */
    public function scopeWhereSlug(Builder $query, string $slug, ?string $locale = null): Builder
    {
        $locale ??= App::getLocale();

        $model = new static;

        return $query->where(function (Builder $q) use ($slug, $locale, $model) {
            // Only brands/categories-style models have per-locale slugs; for the
            // others the canonical slug is the only lookup key. The column is
            // qualified because an unqualified one silently binds to the outer
            // table inside the subquery.
            if ($model->translationTableHasSlug()) {
                $q->whereHas('translations', fn ($t) => $t
                    ->where('locale', $locale)
                    ->where($t->qualifyColumn('slug'), $slug)
                );
            }

            $q->orWhere($model->qualifyColumn('slug'), $slug);
        });
    }

    public static function findBySlug(string $slug, ?string $locale = null): ?static
    {
        return static::query()
            ->whereSlug($slug, $locale)
            ->withAllTranslations($locale)
            ->first();
    }
}
