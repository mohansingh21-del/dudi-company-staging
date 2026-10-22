<?php

namespace App\Enums;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Role slugs the code depends on, and the groups they fall into.
 *
 * Roles double as employee designations (employees.designation_id points at
 * roles), so one "kind" of person is often several roles — a driver may be
 * Driver, Driver HMV or Driver LMV. Check membership of a group here rather
 * than comparing against a single slug.
 */
class RoleSlug
{
    public const SUPER_ADMIN = 'super-admin';
    public const SUPERVISOR = 'supervisor';
    public const SITE_INCHARGE = 'site-incharge';
    public const DRIVER = 'driver';

    // Designations treated as a supervisor: same route access, and offered
    // by the shift-plan supervisor picker.
    public const SUPERVISORS = [
        'supervisor',
        'supervisor-camp-incharge',
        'diesel-supervisor',
    ];

    // Designations treated as a site incharge: same route access, and offered
    // by the shift-plan site incharge picker.
    public const SITE_INCHARGES = [
        'site-incharge',
        'shift-incharge',
        'production-incharge',
    ];

    public const DRIVERS = [
        'driver',
        'driver-hmv',
        'driver-lmv',
    ];

    /**
     * Build a role middleware string from slugs and/or slug groups, e.g.
     * RoleSlug::middleware(RoleSlug::SUPER_ADMIN, RoleSlug::SUPERVISORS).
     */
    public static function middleware(...$slugs): string
    {
        return 'role:' . implode(',', array_unique(Arr::flatten($slugs)));
    }

    public static function isDriver($slug): bool
    {
        return in_array($slug, self::DRIVERS, true);
    }

    /**
     * The slug group a picker value names, or null when it names no group.
     * Accepts a slug or a display name ("Site Incharge", "site_incharge").
     */
    public static function pickerGroup($value)
    {
        $groups = [
            self::SUPERVISOR => self::SUPERVISORS,
            self::SITE_INCHARGE => self::SITE_INCHARGES,
            self::DRIVER => self::DRIVERS,
        ];

        return $groups[Str::slug((string) $value)] ?? null;
    }
}
