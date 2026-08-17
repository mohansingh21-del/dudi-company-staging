<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SparePartInquiry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SparePartInquiryMatchingService
{
    /**
     * Match providers for the given spare part inquiry.
     *
     * @param  \App\Models\SparePartInquiry  $inquiry
     * @return \Illuminate\Support\Collection
     */
    public function match(SparePartInquiry $inquiry): Collection
    {
        $query = DB::table('service_provider_subscriptions')
            ->join('service_provider_profile', 'service_provider_profile.user_id', '=', 'service_provider_subscriptions.provider_id')
            ->whereIn('service_provider_subscriptions.status', ['active', 'renewed'])
            ->where('service_provider_subscriptions.current_period_end', '>=', now()->subDays(7));

        // Filter by part condition
        if ($inquiry->part_condition == 1) {
            $query->where('service_provider_profile.spare_parts_new', 1);
        } else {
            $query->where('service_provider_profile.spare_parts_old', 1);
        }

        // Filter by vehicle category
        if (!empty($inquiry->vehicle_category_id)) {
            $query->where(function ($query) use ($inquiry) {
                $query->whereJsonContains('service_provider_profile.vehicle_category_id', (int) $inquiry->vehicle_category_id)
                    ->orWhereJsonContains('service_provider_profile.vehicle_category_id', (string) $inquiry->vehicle_category_id);
            });
        }

        return $query->select([
            'service_provider_profile.user_id as provider_id',
            DB::raw('1 as notify_sms'),
            DB::raw('1 as notify_email'),
            DB::raw('1 as notify_push'),
        ])
            ->get();
    }

    /**
     * Get count of matched providers for category and condition.
     *
     * @param  int|null  $categoryId
     * @param  int|null  $partCondition
     * @return int
     */
    public function getMatchCount(?int $categoryId, ?int $partCondition): int
    {
        $query = DB::table('service_provider_subscriptions')
            ->join('service_provider_profile', 'service_provider_profile.user_id', '=', 'service_provider_subscriptions.provider_id')
            ->whereIn('service_provider_subscriptions.status', ['active', 'renewed'])
            ->where('service_provider_subscriptions.current_period_end', '>=', now()->subDays(7));

        if ($partCondition !== null) {
            if ($partCondition == 1) {
                $query->where('service_provider_profile.spare_parts_new', 1);
            } else {
                $query->where('service_provider_profile.spare_parts_old', 1);
            }
        }

        if ($categoryId !== null) {
            $query->where(function ($query) use ($categoryId) {
                $query->whereJsonContains('service_provider_profile.vehicle_category_id', (int) $categoryId)
                    ->orWhereJsonContains('service_provider_profile.vehicle_category_id', (string) $categoryId);
            });
        }

        return $query->count();
    }
}
