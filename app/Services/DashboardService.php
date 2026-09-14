<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class DashboardService
{
    /**
     * @var OverviewDashboardService
     */
    protected $overview;

    /**
     * @var FuelDashboardService
     */
    protected $fuel;

    /**
     * @var BreakdownDashboardService
     */
    protected $breakdown;

    /**
     * @var DelayDashboardService
     */
    protected $delay;

    /**
     * @var DispatchDashboardService
     */
    protected $dispatch;

    /**
     * Constructor injecting module services.
     */
    public function __construct(
        OverviewDashboardService $overview,
        FuelDashboardService $fuel,
        BreakdownDashboardService $breakdown,
        DelayDashboardService $delay,
        DispatchDashboardService $dispatch
    ) {
        $this->overview = $overview;
        $this->fuel = $fuel;
        $this->breakdown = $breakdown;
        $this->delay = $delay;
        $this->dispatch = $dispatch;
    }

    /**
     * Retrieve the unified dashboard summary with dynamic caching.
     *
     * @param array $filters
     * @return array
     */
    public function getSummary(array $filters): array
    {
        $siteId = $filters['mine_site_id'];
        $blockId = $filters['block_id'] ?? 'all';
        $shiftId = $filters['shift_id'] ?? 'all';
        $from = $filters['from'];
        $to = $filters['to'];

        // Determine cache TTL (120 seconds for today/future, 3600 seconds for past ranges)
        $today = Carbon::today()->toDateString();
        $isTodayOrFuture = ($to >= $today);
        $ttl = $isTodayOrFuture ? 120 : 3600;

        // Bypass cache in local/testing environments or if nocache parameter is passed
        $bypassCache = app()->environment('local', 'testing') || request()->has('nocache') || request()->has('refresh');

        if ($bypassCache) {
            return [
                'overview'  => $this->overview->build($filters),
                'fuel'      => $this->fuel->build($filters),
                'breakdown' => $this->breakdown->build($filters),
                'delay'     => $this->delay->build($filters),
                'dispatch'  => $this->dispatch->build($filters),
            ];
        }

        // Build Cache Key
        $cacheKey = "dashboard_summary_{$siteId}_{$blockId}_{$shiftId}_{$from}_{$to}";

        return Cache::remember($cacheKey, $ttl, function () use ($filters) {
            return [
                'overview'  => $this->overview->build($filters),
                'fuel'      => $this->fuel->build($filters),
                'breakdown' => $this->breakdown->build($filters),
                'delay'     => $this->delay->build($filters),
                'dispatch'  => $this->dispatch->build($filters),
            ];
        });
    }
}
