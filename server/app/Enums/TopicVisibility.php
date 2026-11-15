<?php

namespace App\Enums;

enum TopicVisibility: string
{
    /** Anyone who knows the topic name can subscribe, no account required. */
    case Public = 'public';

    /** Only authenticated users with access to the topic can subscribe. */
    case Private = 'private';

    public function label(): string
    {
        return match ($this) {
            self::Public => __('Public'),
            self::Private => __('Private'),
        };
    }
}
