<?php

namespace App\Concerns;

use Illuminate\Database\Query\Builder;

/**
 * Reading a translatable taxonomy's `name` and `tagline` in one query.
 *
 * The model route for this is `HasTranslations::translated()`: eager-load every
 * translation row, then walk locale → default → fallback in PHP per entity. It is
 * correct and it is wasteful when the whole list is being read at once, because
 * it transfers every language for every row to answer one question per row.
 *
 * This does the same chain in the database. One left join per candidate locale
 * resolves it, and the joins come back in chain order so the PHP side is a single
 * left-to-right `pick()`.
 *
 * Two details that are easy to get wrong, and which `translated()` is explicit
 * about:
 *
 *  - **One alias per locale, not a `whereIn`.** A `whereIn` across the default and
 *    the fallback locale matches an entity translated into both, and the entity
 *    then appears twice in the result — a brand listed twice in the menu, or two
 *    tiles on the homepage. `(entity_id, locale)` is unique, so one join per
 *    locale keeps the result at one row per entity.
 *  - **An empty string counts as missing.** `translated()` skips a candidate that
 *    is null *or* '', so a pick that stopped at '' would render a blank label
 *    where the model showed the next language. This matters most where it is
 *    hardest to notice: a half-translated brand showing the wrong language, with
 *    no error anywhere.
 */
trait ReadsTranslatedTaxonomy
{
    /**
     * The requested locale, then the configured default, then the fallback.
     *
     * Deduplicated, because a locale that is also the default would otherwise be
     * joined twice and the same value selected twice.
     *
     * @return array<int, string>
     */
    protected function localeChain(string $locale): array
    {
        return array_values(array_unique(array_filter([
            $locale,
            (string) config('chamma.default_locale'),
            (string) config('chamma.fallback_locale'),
        ])));
    }

    /**
     * Short join aliases, in chain order, for the locale the request asked for
     * first and the fallbacks after it.
     *
     * @param  array<int, string>  $chain
     * @return array<int, string>
     */
    protected function translationAliases(array $chain): array
    {
        return array_map(fn (int $index) => 'tr'.$index, array_keys($chain));
    }

    /**
     * @param  array<int, string>  $chain
     * @param  array<int, string>  $aliases
     */
    protected function joinTranslationChain(
        Builder $query,
        string $parent,
        string $table,
        string $foreignKey,
        array $chain,
        array $aliases,
    ): Builder {
        foreach ($chain as $index => $candidate) {
            $alias = $aliases[$index];

            $query->leftJoin("{$table} as {$alias}", function ($join) use ($alias, $foreignKey, $parent, $candidate) {
                $join->on("{$alias}.{$foreignKey}", '=', "{$parent}.id")
                    ->where("{$alias}.locale", '=', $candidate);
            });
        }

        return $query;
    }

    /**
     * The selected values for the translation aliases, in chain order.
     *
     * @param  array<int, string>  $aliases
     * @return array<int, mixed>
     */
    protected function translationValues(object $row, array $aliases, string $column): array
    {
        return array_map(fn (string $alias) => $row->{$alias.'_'.$column}, $aliases);
    }

    /**
     * First value that is neither null nor an empty string.
     */
    protected function pick(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }
}
