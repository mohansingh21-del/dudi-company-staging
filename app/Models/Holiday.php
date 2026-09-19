<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    use HasFactory;

    /**
     * Shortest name a real holiday can have. Anything shorter is a placeholder
     * somebody typed to see whether the form saved.
     */
    const MIN_NAME_LENGTH = 3;

    /**
     * Names that mark a row as a test entry rather than production data.
     *
     * Matched case-insensitively against the name with any trailing digits and
     * punctuation stripped, so "test", "Test 2", "rest day" and "rest day1"
     * all resolve to the same needle. Used by the holidays:dedupe report and
     * to decide which of a duplicate pair is worth keeping - never to delete
     * anything on its own.
     */
    const TEST_NAME_PATTERNS = [
        'test',
        'testing',
        'demo',
        'dummy',
        'sample',
        'abc',
        'xyz',
        'asdf',
        'qwerty',
        'rest day',
        'restday',
        'check',
        'temp',
        'na',
        'n/a',
    ];

    protected $fillable = [
        'holiday_name',
        'holiday_date',
        'site_id',
        'holiday_type',
        'is_active',
    ];

    // is_active is deliberately NOT cast to boolean: HolidayResource returns it
    // straight through as `status`, and a cast would flip that from 1/0 to
    // true/false for every existing API consumer.
    protected $casts = [
        'holiday_date' => 'date',
    ];

    /**
     * Relationship: Holiday belongs to a Site
     */
    public function site()
    {
        return $this->belongsTo(Site::class);
    }

    public function getFormattedDateAttribute()
    {
        return optional($this->holiday_date)->format('d-M-Y');
    }

    /**
     * Only the rows that count: active ones. Every calculation already filters
     * this way, and the DB unique index only constrains active rows, so the
     * scope keeps the two definitions of "live" in one place.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Holidays that apply to a site: its own plus the establishment-wide ones.
     */
    public function scopeForSite($query, $siteId)
    {
        return $query->where(function ($q) use ($siteId) {
            $q->whereNull('site_id');

            if ($siteId !== null) {
                $q->orWhere('site_id', $siteId);
            }
        });
    }

    /**
     * Does this name look like somebody testing the form?
     *
     * Deliberately advisory. It drives a report and a tie-break, never a
     * delete - "Test Match Holiday" would be a false positive and a real
     * holiday should not disappear over a substring.
     */
    public static function isTestLikeName(?string $name): bool
    {
        $normalised = strtolower(trim((string) $name));
        // Collapse whitespace and drop a trailing counter: "rest day1",
        // "Test - 2" and "test" are one person doing the same thing.
        $normalised = preg_replace('/\s+/', ' ', $normalised);
        $normalised = preg_replace('/[\s\-_.#]*\d+$/', '', $normalised);
        $normalised = trim($normalised);

        if ($normalised === '') {
            return true;
        }

        if (mb_strlen($normalised) < self::MIN_NAME_LENGTH) {
            return true;
        }

        return in_array($normalised, self::TEST_NAME_PATTERNS, true);
    }
}
