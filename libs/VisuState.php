<?php

declare(strict_types=1);

namespace ModuleVisu;

final class State
{
    private const STATES = [
        'ok',
        'normal',
        'active',
        'inactive',
        'info',
        'pending',
        'warning',
        'error',
        'offline',
        'stale',
        'degraded',
        'not_configured',
        'disabled',
        'unknown',
    ];

    public static function normalize(string $state): string
    {
        return in_array($state, self::STATES, true) ? $state : 'unknown';
    }

    public static function isProblem(string $state): bool
    {
        return in_array(self::normalize($state), ['warning', 'error', 'offline', 'stale', 'degraded', 'not_configured', 'unknown'], true);
    }

    public static function isAvailable(string $state): bool
    {
        return !in_array(self::normalize($state), ['offline', 'not_configured', 'disabled', 'unknown'], true);
    }

    public static function cssState(string $state): string
    {
        $state = self::normalize($state);

        return match ($state) {
            'ok'                => 'normal',
            'stale', 'degraded' => 'warning',
            'not_configured'    => 'disabled',
            default             => $state,
        };
    }
}
