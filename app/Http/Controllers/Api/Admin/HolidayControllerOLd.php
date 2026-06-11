<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Holiday;
use App\Models\WorkingDay;
use App\Traits\ApiResponse;
use App\Imports\HolidaysImport;
use Maatwebsite\Excel\Facades\Excel;
use Exception;
use Carbon\Carbon;

class HolidayController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        try {
            $year = $request->input('year', Carbon::now()->year);
            $month = $request->input('month', Carbon::now()->month);

            $holidays = Holiday::whereYear('date', $year)
                ->whereMonth('date', $month)
                ->orderBy('date', 'asc')
                ->get();

            return $this->successResponse($holidays, 'Holidays retrieved successfully');
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'id' => 'nullable|exists:holidays,id',
                'date' => 'required|date|unique:holidays,date,' . ($request->id ?? 'NULL'),
                'title' => 'nullable|string|max:255',
            ]);

            $holiday = Holiday::updateOrCreate(
                ['id' => $request->id],
                $validated
            );

            $message = $request->id ? 'Holiday updated successfully' : 'Holiday created successfully';
            return $this->successResponse($holiday, $message, $request->id ? 200 : 201);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function destroy($id)
    {
        try {
            $holiday = Holiday::findOrFail($id);
            $holiday->delete();
            return $this->successResponse(null, 'Holiday deleted successfully');
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function getWorkingDays()
    {
        try {
            $workingDays = WorkingDay::all();
            return $this->successResponse($workingDays, 'Working days retrieved successfully');
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function updateWorkingDays(Request $request)
    {
        try {
            $request->validate([
                'working_days' => 'required|array',
                'working_days.*.day' => 'required|string',
                'working_days.*.is_working' => 'required|boolean',
            ]);

            foreach ($request->working_days as $item) {
                WorkingDay::where('day', $item['day'])->update([
                    'is_working' => $item['is_working']
                ]);
            }

            return $this->successResponse(WorkingDay::all(), 'Working days updated successfully');
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function bulkUpload(Request $request)
    {
        try {
            $request->validate([
                'file' => 'required|mimes:xlsx,xls,csv|max:10240',
            ]);


            Excel::import(new HolidaysImport, $request->file('file'));

            return $this->successResponse(null, 'Holidays imported successfully');
        } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
            $failures = $e->failures();
            $errors = [];
            foreach ($failures as $failure) {
                $errors[] = [
                    'row' => $failure->row(),
                    'attribute' => $failure->attribute(),
                    'errors' => $failure->errors(),
                    'values' => $failure->values(),
                ];
            }
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed during import. Please check if your column headers match exactly.',
                'errors' => $errors,
                'detected_headers' => isset($failures[0]) ? array_keys($failures[0]->values()) : 'None'
            ], 422);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function downloadTemplate()
    {
        try {
            $headers = ['date', 'title', 'is_active'];
            $fileName = 'holiday_template.csv';

            $callback = function () use ($headers) {
                $file = fopen('php://output', 'w');
                fputcsv($file, $headers);
                // Example row
                fputcsv($file, ['2026-05-14', 'Sample Holiday', '1']);
                fclose($file);
            };

            return response()->stream($callback, 200, [
                "Content-type" => "text/csv",
                "Content-Disposition" => "attachment; filename=$fileName",
                "Pragma" => "no-cache",
                "Cache-Control" => "must-revalidate, post-check=0, pre-check=0",
                "Expires" => "0"
            ]);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
