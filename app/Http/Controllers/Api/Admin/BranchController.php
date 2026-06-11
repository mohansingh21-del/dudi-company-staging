<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\BranchRequest;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Exception;
use App\Traits\ApiResponse;

class BranchController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        try {
            $limit = $request->input('limit', 10);
            $page = $request->input('page', 1);
            $search = $request->input('search', null);
            $isActive = $request->input('is_active', null);

            $query = Branch::with(['state', 'city']);

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('branches.name', 'like', '%' . $search . '%')
                        ->orWhereHas('state', function ($q) use ($search) {
                            $q->where('name', 'like', '%' . $search . '%');
                        })
                        ->orWhereHas('city', function ($q) use ($search) {
                            $q->where('name', 'like', '%' . $search . '%');
                        });
                });
            }

            if ($isActive !== null) {
                $query->where('is_active', $isActive);
            }

            if ($limit) {
                $branches = $query->orderBy('created_at', 'DESC')
                    ->paginate($limit, ['*'], 'page', $page);

                return response()->json([
                    'status' => 200,
                    'message' => 'Branches retrieved successfully',
                    'data' => BranchResource::collection($branches),
                    'pagination' => [
                        'total' => $branches->total(),
                        'current_page' => $branches->currentPage(),
                        'per_page' => $branches->perPage(),
                        'last_page' => $branches->lastPage(),
                        'from' => $branches->firstItem(),
                        'to' => $branches->lastItem(),
                        'next_page_url' => $branches->nextPageUrl(),
                        'previous_page_url' => $branches->previousPageUrl(),
                    ]
                ]);
            } else {
                $branches = $query->orderBy('created_at', 'DESC')
                    ->get();

                return response()->json([
                    'status' => 200,
                    'message' => 'Branches retrieved successfully',
                    'data' => BranchResource::collection($branches),
                    'pagination' => [
                        'total' => $branches->count(),
                        'current_page' => 1,
                        'per_page' => $branches->count(),
                        'last_page' => 1,
                        'from' => $branches->isEmpty() ? 0 : 1,
                        'to' => $branches->count(),
                        'next_page_url' => null,
                        'previous_page_url' => null,
                    ]
                ]);
            }

        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $id = $request->input('id');

            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:branches,name' . ($id ? ',' . $id : ''),
                'state_id' => 'required|exists:states,id',
                'city_id' => 'required|exists:cities,id',
            ]);

            $branch = $id ? Branch::findOrFail($id) : new Branch();

            $branch->name = $validated['name'];
            $branch->state_id = $validated['state_id'];
            $branch->city_id = $validated['city_id'];
            if (!$id) {
                $branch->is_active = 1;
            }
            $branch->save();

            $message = $id ? 'Branch updated successfully' : 'Branch created successfully';

            return $this->successResponse(new BranchResource($branch), $message, $id ? 200 : 201);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function show($id)
    {
        try {
            $branch = Branch::findOrFail($id);
            return $this->successResponse(new BranchResource($branch), 'Branch fetched successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Branch not found', 404);
        } catch (Exception $e) {
            return $this->errorResponse('Something went wrong. Please try again later.', 500);
        }
    }

    public function update(BranchRequest $request, $id)
    {
        try {
            $branch = Branch::findOrFail($id);
            $validated = $request->validated();
            $branch->update($validated);

            return $this->successResponse(new BranchResource($branch), 'Branch updated successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Branch not found', 404);
        } catch (Exception $e) {
            return $this->errorResponse('Something went wrong. Please try again later.', 500);
        }
    }

    public function destroy($id)
    {
        try {
            $branch = Branch::findOrFail($id);
            $branch->delete();
            return $this->successResponse([], 'Branch deleted successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Branch not found', 404);
        } catch (Exception $e) {
            return $this->errorResponse('Something went wrong. Please try again later.', 500);
        }
    }

    public function toggleStatus($id)
    {
        try {
            $branch = Branch::findOrFail($id);
            $branch->is_active = $branch->is_active ? 0 : 1;
            $branch->save();

            return $this->successResponse($branch, 'Branch status changed successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Branch not found', 404);
        } catch (Exception $e) {
            return $this->errorResponse('Something went wrong. Please try again later.', 500);
        }
    }
}
