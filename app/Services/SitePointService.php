<?php

namespace App\Services;

use App\Models\SitePoint;
use Illuminate\Support\Facades\DB;

class SitePointService
{
    /**
     * Public listing — only active site points.
     * Filterable by site_id and type (both optional).
     *
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getPublicList(array $filters)
    {
        $query = SitePoint::query()
            ->active()
            ->with(['site']);

        if (!empty($filters['site_id'])) {
            $query->where('site_id', $filters['site_id']);
        }

        if (!empty($filters['type'])) {
            $query->ofType($filters['type']);
        }

        return $query->latest()->get();
    }

    /**
     * Admin listing — all records with filters, search, and pagination.
     *
     * @param  array  $filters
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getAdminList(array $filters)
    {
        $query = SitePoint::query()
            ->with(['site', 'creator']);

        // Filter by site_id
        if (!empty($filters['site_id'])) {
            $query->where('site_id', $filters['site_id']);
        }

        // Filter by type
        if (!empty($filters['type'])) {
            $query->ofType($filters['type']);
        }

        // Filter by is_active (allow explicit 0/1)
        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $query->where('is_active', $filters['is_active']);
        }

        // Search by name or code (LIKE)
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%");
            });
        }

        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 10;

        return $query->latest()->paginate($perPage);
    }

    /**
     * Find a single site point by ID or fail.
     *
     * @param  int  $id
     * @return \App\Models\SitePoint
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function getById($id)
    {
        return SitePoint::with(['site', 'creator'])->findOrFail($id);
    }

    /**
     * Create a new site point inside a DB transaction.
     *
     * @param  array  $data
     * @param  int    $userId
     * @return \App\Models\SitePoint
     */
    public function create(array $data, $userId)
    {
        return DB::transaction(function () use ($data, $userId) {
            $data['created_by'] = $userId;
            return SitePoint::create($data);
        });
    }

    /**
     * Update an existing site point.
     *
     * @param  int    $id
     * @param  array  $data
     * @return \App\Models\SitePoint
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function update($id, array $data)
    {
        $sitePoint = SitePoint::findOrFail($id);
        $sitePoint->update($data);

        return $sitePoint->fresh(['site', 'creator']);
    }

    /**
     * Toggle the is_active status of a site point.
     *
     * @param  int  $id
     * @return \App\Models\SitePoint
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function toggleStatus($id)
    {
        $sitePoint = SitePoint::findOrFail($id);
        $sitePoint->is_active = !$sitePoint->is_active;
        $sitePoint->save();

        return $sitePoint;
    }
}
