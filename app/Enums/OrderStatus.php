<?php

namespace App\Enums;

enum OrderStatus: string
{
    case New = 'new';
    case Confirmed = 'confirmed';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    /**
     * Defaults to the current app locale (the admin panel's language).
     * Pass the order's stored locale explicitly to render the label the way
     * the customer saw it.
     */
    public function label(?string $locale = null): string
    {
        return __("api.order_status.{$this->value}", locale: $locale);
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'amber',
            self::Confirmed => 'sky',
            self::Delivered => 'emerald',
            self::Cancelled => 'rose',
        };
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Delivered, self::Cancelled], true);
    }

    public function next(): ?self
    {
        return match ($this) {
            self::New => self::Confirmed,
            self::Confirmed => self::Delivered,
            self::Delivered, self::Cancelled => null,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
