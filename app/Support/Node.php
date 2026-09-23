<?php

namespace App\Support;

/** Which node is this process? (`APP_NODE=local|cloud`, ADR-0013). */
final class Node
{
    public static function name(): string
    {
        return config('node.node');
    }

    public static function isLocal(): bool
    {
        return self::name() === 'local';
    }

    public static function isCloud(): bool
    {
        return self::name() === 'cloud';
    }

    public static function siteId(): ?string
    {
        $id = config('node.site_id');

        return $id ? Ids::normalize($id) : null;
    }
}
