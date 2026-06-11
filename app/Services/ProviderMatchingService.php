<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ServiceRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProviderMatchingService
{
    /**
     * Match providers for the given service request.
     *
     * @param  \App\Models\ServiceRequest  $serviceRequest
     * @return \Illuminate\Support\Collection
     */
    public function match(ServiceRequest $serviceRequest): Collection
    {
        $query = DB::table('service_provider_subscriptions')
            ->join('service_provider_profile', 'service_provider_profile.user_id', '=', 'service_provider_subscriptions.provider_id')
            ->whereIn('service_provider_subscriptions.status', ['active', 'renewed'])
            ->where('service_provider_subscriptions.current_period_end', '>=', now()->subDays(7))
            ->where('service_provider_profile.is_service_available', 1);

        if (!empty($serviceRequest->vehicle_category_id)) {
            $query->where(function ($query) use ($serviceRequest) {
                $query->whereJsonContains('service_provider_profile.vehicle_category_id', (int) $serviceRequest->vehicle_category_id)
                    ->orWhereJsonContains('service_provider_profile.vehicle_category_id', (string) $serviceRequest->vehicle_category_id);
            });
        }

        if (!empty($serviceRequest->service_type_id)) {
            $serviceTypeIds = is_array($serviceRequest->service_type_id)
                ? $serviceRequest->service_type_id
                : json_decode((string) $serviceRequest->service_type_id, true) ?? [];

            $serviceTypeIds = array_filter(array_map('intval', $serviceTypeIds));

            // if (!empty($serviceTypeIds)) {
            //     $query->where(function ($query) use ($serviceTypeIds) {
            //         foreach ($serviceTypeIds as $id) {
            //             $query->orWhereJsonContains('service_provider_profile.service_type_id', $id)
            //                   ->orWhereJsonContains('service_provider_profile.service_type_id', (string) $id);
            //         }
            //     });
            // }
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
     * Get count of matched providers for a category and service type.
     *
     * @param  int|null  $categoryId
     * @param  mixed  $serviceTypeId
     * @return int
     */
    public function getMatchCount(?int $categoryId, $serviceTypeId = null): int
    {
        $query = DB::table('service_provider_subscriptions')
            ->join('service_provider_profile', 'service_provider_profile.user_id', '=', 'service_provider_subscriptions.provider_id')
            ->whereIn('service_provider_subscriptions.status', ['active', 'renewed'])
            ->where('service_provider_subscriptions.current_period_end', '>=', now()->subDays(7))
            ->where('service_provider_profile.is_service_available', 1);

        if ($categoryId !== null) {
            $query->where(function ($query) use ($categoryId) {
                $query->whereJsonContains('service_provider_profile.vehicle_category_id', (int) $categoryId)
                    ->orWhereJsonContains('service_provider_profile.vehicle_category_id', (string) $categoryId);
            });
        }

        // if (!empty($serviceTypeId)) {
        //     $serviceTypeIds = [];
        //     if (is_array($serviceTypeId)) {
        //         $serviceTypeIds = $serviceTypeId;
        //     } elseif (is_string($serviceTypeId)) {
        //         $decoded = json_decode($serviceTypeId, true);
        //         if (is_array($decoded)) {
        //             $serviceTypeIds = $decoded;
        //         } else {
        //             $serviceTypeIds = array_filter(array_map('trim', explode(',', $serviceTypeId)));
        //         }
        //     } else {
        //         $serviceTypeIds = [$serviceTypeId];
        //     }

        //     $serviceTypeIds = array_filter(array_map('intval', $serviceTypeIds));

        //     if (!empty($serviceTypeIds)) {
        //         $query->where(function ($query) use ($serviceTypeIds) {
        //             foreach ($serviceTypeIds as $id) {
        //                 $query->orWhereJsonContains('service_provider_profile.service_type_id', $id)
        //                     ->orWhereJsonContains('service_provider_profile.service_type_id', (string) $id);
        //             }
        //         });
        //     }
        // }

        return $query->count();
    }
}
