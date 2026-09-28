<?php

namespace App\Concerns;

/**
 * A product, category or brand is called the same thing in every language: its
 * name is a label, not copy, so translating it just produces three names to keep
 * in sync for no gain. The admin therefore types it once.
 *
 * It is stored on the translation row for the configured fallback locale (English)
 * and `HasTranslations::name()` falls back to that row for every other locale, so
 * a newly created item reads correctly in all three languages.
 *
 * Names that were already translated are left alone: this concern never overwrites
 * a localized name that is already stored, so historic rows keep rendering the way
 * they always have. Only the fallback row is written from the single input.
 */
trait HasSingleName
{
    /**
     * The locale whose translation row carries the canonical name.
     */
    protected function nameLocale(): string
    {
        return (string) config('chamma.fallback_locale');
    }

    /**
     * The canonical name currently stored, read before any row is written.
     *
     * A translation row whose name still equals the canonical one was not really
     * translated — it was backfilled when the row was created — so it has to be
     * distinguishable from a genuine localized name when deciding what to keep.
     */
    protected function currentCanonicalName($model): ?string
    {
        return $model->translations()
            ->where('locale', $this->nameLocale())
            ->value('name');
    }

    /**
     * Resolve the name to store for one locale.
     *
     * The fallback locale always takes the submitted name. Any other locale keeps
     * whatever localized name it already has, and only falls back to the submitted
     * name when it has none (a brand-new row, where the column is NOT NULL) or when
     * it merely echoes the old canonical name (a backfilled placeholder, which must
     * not be left behind as a stale name after a rename).
     *
     * @param  \Illuminate\Database\Eloquent\Model|null  $existing
     * @param  string|null  $canonical  the canonical name before this update
     */
    protected function resolveName(string $locale, string $name, $existing = null, ?string $canonical = null): string
    {
        if ($locale === $this->nameLocale()) {
            return $name;
        }

        $localized = $existing?->name;

        if (! is_string($localized) || $localized === '') {
            return $name;
        }

        return $canonical !== null && $localized === $canonical ? $name : $localized;
    }

    /**
     * Write the single name to every locale, creating the rows that are missing.
     *
     * A new item has no copy in any language either, so its rows are created with
     * the name alone and whatever the caller must supply for a NOT NULL column
     * ($extra, either an array or a per-locale callable).
     */
    protected function syncCanonicalName($model, string $name, array|callable $extra = []): void
    {
        $canonical = $this->currentCanonicalName($model);

        foreach (array_keys(config('chamma.locales')) as $locale) {
            $existing = $model->translations()->where('locale', $locale)->first();

            if (! $existing) {
                $model->translations()->create([
                    'locale' => $locale,
                    'name' => $name,
                ] + (is_callable($extra) ? $extra($locale) : $extra));

                continue;
            }

            $resolved = $this->resolveName($locale, $name, $existing, $canonical);

            if ($resolved !== $existing->name) {
                $existing->name = $resolved;
                $existing->save();
            }
        }
    }

    /**
     * The copy is typed once as well, so it lands on the fallback row only.
     *
     * Locales that were translated before are left exactly as they are: this
     * writes the single-language copy without touching any other row.
     */
    protected function syncCanonicalCopy($model, array $copy): void
    {
        if ($copy === []) {
            return;
        }

        $row = $model->translations()->where('locale', $this->nameLocale())->first();

        $row?->update($copy);
    }
}
