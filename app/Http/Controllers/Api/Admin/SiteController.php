<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Concerns\GuardsMasterDeactivation;
use Illuminate\Http\Request;
use App\Models\Site;
use App\Http\Requests\StoreSiteRequest;
use App\Http\Requests\UpdateSiteRequest;
use App\Http\Resources\SiteResource;
use Illuminate\Support\Facades\Log;

class SiteController extends Controller
{
    use GuardsMasterDeactivation;

    public function index(Request $request)
    {
        try {

            $limit = $request->input('limit', 10);

            $departments = Site::query();

            // Search
            if ($request->filled('search')) {

                $search = $request->search;

                $departments->where(function ($query) use ($search) {
                    $query->where('site_name', 'LIKE', "%{$search}%")
                        ->orWhere('address', 'LIKE', "%{$search}%");
                });
            }

            $departments = $departments
                ->latest()
                ->paginate($limit);

            return response()->json([
                'status' => 200,
                'message' => 'Site list fetched successfully',
                'data' => SiteResource::collection($departments),
                'pagination' => [
                    'current_page' => $departments->currentPage(),
                    'last_page' => $departments->lastPage(),
                    'per_page' => $departments->perPage(),
                    'total' => $departments->total(),
                    'from' => $departments->firstItem(),
                    'to' => $departments->lastItem(),
                ]
            ]);
        } catch (\Throwable $th) {

            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ]);
        }
    }

    public function store(StoreSiteRequest $request)
    {
        try {

            Site::create([
                'site_name' => $request->name,
                'address' => $request->address,
                'is_active' => 1
            ]);

            return response()->json([
                'status' => 200,
                'message' => 'Site created'
            ]);
        } catch (\Throwable $th) {

            Log::error('Site create failed', ['error' => $th->getMessage()]);

            return response()->json([
                'status' => 500,
                'message' => 'Unable to create site. Please try again.'
            ], 500);
        }
    }

    public function show(int $id)
    {
        $dept = Site::find($id);

        if (!$dept) {
            return response()->json([
                'status' => 404,
                'message' => 'Site not found'
            ]);
        }

        return response()->json([
            'status' => 200,
            'data' => new SiteResource($dept)
        ]);
    }
    public function update(UpdateSiteRequest $request, int $id)
    {
        $dept = Site::find($id);

        if (!$dept) {
            return response()->json([
                'status' => 404,
                'message' => 'Site not found'
            ]);
        }

        try {

            $dept->update([
                'site_name' => $request->name,
                'address' => $request->address
            ]);

            return response()->json([
                'status' => 200,
                'message' => 'Site updated successfully'
            ]);
        } catch (\Throwable $th) {

            Log::error('Site update failed', ['id' => $id, 'error' => $th->getMessage()]);

            return response()->json([
                'status' => 500,
                'message' => 'Unable to update site. Please try again.'
            ], 500);
        }
    }

    public function destroy(int $id)
    {
        $dept = Site::find($id);

        if (!$dept) {
            return response()->json([
                'status' => 404,
                'message' => 'Site not found'
            ]);
        }

        $dept->delete();

        return response()->json([
            'status' => 200,
            'message' => 'Site deleted successfully'
        ]);
    }
    public function toggleStatus(Request $request, int $id)
    {
        $dept = Site::find($id);

        if (!$dept) {
            return response()->json([
                'status' => 404,
                'message' => 'Site not found'
            ]);
        }
        $request->validate([
            'status' => 'required|in:0,1'
        ]);


        if ($blocked = $this->blockDeactivation($dept, $request->status)) {
            return $blocked;
        }

        $dept->is_active = $request->status ? 1 : 0;
        $dept->save();


        return response()->json([
            'status' => 200,
            'message' => 'Site status updated successfully'
        ]);
    }

    public function getPublicSites()
    {
        try {
            $sites = Site::where('is_active', 1)->get();

            return response()->json([
                'status' => 200,
                'message' => 'Sites retrieved successfully',
                'data' => SiteResource::collection($sites)
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }
}
