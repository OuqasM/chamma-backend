<?php

namespace App\Services;

/**
 * The store's public contact details and social handles.
 *
 * Lives here rather than inline in a controller because two payloads need it:
 * the `/store` bundle and the locale-scoped `/navigation` bootstrap the shell
 * uses. Duplicating the array meant a handle could be added to one and not the
 * other, and the footer's links would quietly go stale.
 *
 * Social handles are stored **bare** — `chamma_store_`, not a full URL. The
 * storefront turns them into links itself, so the values stay usable no matter
 * whether someone pastes a handle, an `@handle` or a whole profile URL into the
 * env file, and no tracking parameters get committed here.
 */
class StoreContactService
{
    /**
     * @return array{email: string, phone: string, whatsapp: string, instagram: string, facebook: string, tiktok: string, address: string|null}
     */
    public function contact(string $locale): array
    {
        return [
            'email' => (string) config('chamma.store.email'),
            'phone' => (string) config('chamma.store.phone'),
            'whatsapp' => (string) config('chamma.store.whatsapp'),
            'instagram' => (string) config('chamma.store.instagram'),
            'facebook' => (string) config('chamma.store.facebook'),
            'tiktok' => (string) config('chamma.store.tiktok'),
            'address' => config('chamma.store.address.'.$locale),
        ];
    }
}
