<?php

namespace App\Services;

use App\Models\ServiceRecord;
use App\Models\ServiceChecklistDetail;
use App\Models\ServiceSparePart;
use App\Models\ServiceAttachment;
use App\Models\ServiceStatusHistory;
use App\Models\ServiceAuditLog;
use App\Models\Breakdown;
use App\Models\InventoryProduct;
use App\Models\Machine;
use App\Http\Resources\ServiceRecordHistoryResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class ServiceRecordService
{
    /**
     * @var InventoryStockService
     */
    protected $inventoryStockService;

    public function __construct(InventoryStockService $inventoryStockService)
    {
        $this->inventoryStockService = $inventoryStockService;
    }

    /**
     * Create a new Service Record and associated details.
     *
     * @param array $data
     * @param int $userId
     * @return ServiceRecord
     */
    public function create(array $data, $userId)
    {
        return DB::transaction(function () use ($data, $userId) {
            $isBreakdownService = isset($data['is_breakdown_service']) && filter_var($data['is_breakdown_service'], FILTER_VALIDATE_BOOLEAN);

            $machineId = null;
            $siteId = null;
            $breakdownId = null;
            $serviceType = 'general';

            if ($isBreakdownService) {
                $breakdownId = isset($data['breakdown_id']) ? (int) $data['breakdown_id'] : null;

                // Lock the ticket row so two concurrent requests cannot both pass
                // the duplicate check below and link the same breakdown.
                $breakdown = Breakdown::where('id', $breakdownId)->lockForUpdate()->first();

                if (!$breakdown) {
                    throw new HttpResponseException(response()->json([
                        'status' => 422,
                        'message' => 'Validation failed',
                        'errors' => [
                            'breakdown_id' => ['Selected breakdown ticket not found.']
                        ]
                    ], 422));
                }

                // Check active/open status (not closed)
                if (in_array(strtolower($breakdown->status), ['closed', 'resolved', 'cancelled'])) {
                    throw new HttpResponseException(response()->json([
                        'status' => 422,
                        'message' => 'Validation failed',
                        'errors' => [
                            'breakdown_id' => ['The selected breakdown ticket is already closed or cancelled and cannot be linked to a new service record.']
                        ]
                    ], 422));
                }

                // A breakdown may only be claimed by one live service record.
                // Cancelling (or deleting) that record releases the ticket again.
                $existingRecord = ServiceRecord::where('breakdown_id', $breakdownId)
                    ->where('status', '!=', 'cancelled')
                    ->first();

                if ($existingRecord) {
                    throw new HttpResponseException(response()->json([
                        'status' => 422,
                        'message' => 'Validation failed',
                        'errors' => [
                            'breakdown_id' => ["This breakdown ticket is already linked to service record {$existingRecord->ticket_number}."]
                        ]
                    ], 422));
                }

                // Server-side derivation of machine_id and site_id
                $machineId = $breakdown->equipment_name_id ? $breakdown->equipment_name_id : $breakdown->equipment_id;
                $siteId = $breakdown->mine_site_id ? $breakdown->mine_site_id : null;
                $serviceType = 'repair';
            } else {
                $breakdownId = null;
                $serviceType = 'general';
                $machineId = isset($data['machine_id']) ? (int) $data['machine_id'] : null;
                $siteId = isset($data['site_id']) ? (int) $data['site_id'] : null;
            }

            $ticketNumber = ServiceRecord::generateTicketNumber();
            $baseAmount = isset($data['base_service_amount']) ? (float) $data['base_service_amount'] : 0.00;

            // A complete downtime window is what marks the work as finished. The
            // client sends times only; the date comes from service_date.
            list($downtimeStart, $downtimeEnd, $downtimeMinutes) = $this->resolveDowntimeWindow(
                $data['service_date'],
                isset($data['downtime_start']) ? $data['downtime_start'] : null,
                isset($data['downtime_end']) ? $data['downtime_end'] : null
            );

            $status = $downtimeMinutes !== null ? 'completed' : 'pending';

            // Create parent record
            $serviceRecord = ServiceRecord::create([
                'ticket_number'            => $ticketNumber,
                'machine_id'               => $machineId,
                'site_id'                  => $siteId,
                'is_breakdown_service'     => $isBreakdownService,
                'breakdown_id'             => $breakdownId,
                'service_type'             => $serviceType,
                'service_date'             => $data['service_date'],
                'hours_odometer_reading'   => isset($data['hours_odometer_reading']) ? $data['hours_odometer_reading'] : null,
                'km_run'                   => isset($data['km_run']) ? $data['km_run'] : null,
                'time_gap_months'          => isset($data['time_gap_months']) ? $data['time_gap_months'] : null,
                'downtime_start'           => $downtimeStart,
                'downtime_end'             => $downtimeEnd,
                'downtime_minutes'         => $downtimeMinutes,
                'base_service_amount'      => $baseAmount,
                'checklist_amount_total'   => 0.00,
                'spare_parts_amount_total' => 0.00,
                'total_amount'             => 0.00,
                'spare_parts_changed'      => isset($data['spare_parts_changed']) && filter_var($data['spare_parts_changed'], FILTER_VALIDATE_BOOLEAN),
                'status'                   => $status,
                'performed_by'             => isset($data['performed_by']) ? $data['performed_by'] : null,
                'remarks'                  => isset($data['remarks']) ? $data['remarks'] : null,
                'created_by'               => $userId,
                'updated_by'               => $userId,
            ]);

            // Create checklist details if provided
            $checklistTotal = 0.00;
            if (isset($data['checklist']) && is_array($data['checklist'])) {
                $checklistData = $data['checklist'];

                $oilChange = isset($checklistData['oil_change']) && filter_var($checklistData['oil_change'], FILTER_VALIDATE_BOOLEAN);
                $oilChangeAmt = $oilChange ? (float) (isset($checklistData['oil_change_amount']) ? $checklistData['oil_change_amount'] : 0.00) : 0.00;

                $hydraulicOil = isset($checklistData['hydraulic_oil']) && filter_var($checklistData['hydraulic_oil'], FILTER_VALIDATE_BOOLEAN);
                $hydraulicOilAmt = $hydraulicOil ? (float) (isset($checklistData['hydraulic_oil_amount']) ? $checklistData['hydraulic_oil_amount'] : 0.00) : 0.00;

                $gearOil = isset($checklistData['gear_oil']) && filter_var($checklistData['gear_oil'], FILTER_VALIDATE_BOOLEAN);
                $gearOilAmt = $gearOil ? (float) (isset($checklistData['gear_oil_amount']) ? $checklistData['gear_oil_amount'] : 0.00) : 0.00;

                $fuelFilter = isset($checklistData['fuel_filter_change']) && filter_var($checklistData['fuel_filter_change'], FILTER_VALIDATE_BOOLEAN);
                $fuelFilterAmt = $fuelFilter ? (float) (isset($checklistData['fuel_filter_change_amount']) ? $checklistData['fuel_filter_change_amount'] : 0.00) : 0.00;

                $oilFilter = isset($checklistData['oil_filter_change']) && filter_var($checklistData['oil_filter_change'], FILTER_VALIDATE_BOOLEAN);
                $oilFilterAmt = $oilFilter ? (float) (isset($checklistData['oil_filter_change_amount']) ? $checklistData['oil_filter_change_amount'] : 0.00) : 0.00;

                ServiceChecklistDetail::create([
                    'service_record_id'         => $serviceRecord->id,
                    'oil_change'                => $oilChange,
                    'oil_change_amount'         => $oilChangeAmt,
                    'hydraulic_oil'             => $hydraulicOil,
                    'hydraulic_oil_amount'      => $hydraulicOilAmt,
                    'gear_oil'                  => $gearOil,
                    'gear_oil_amount'           => $gearOilAmt,
                    'fuel_filter_change'        => $fuelFilter,
                    'fuel_filter_change_amount' => $fuelFilterAmt,
                    'oil_filter_change'         => $oilFilter,
                    'oil_filter_change_amount'  => $oilFilterAmt,
                ]);

                $checklistTotal = $oilChangeAmt + $hydraulicOilAmt + $gearOilAmt + $fuelFilterAmt + $oilFilterAmt;
            }

            // Create spare parts details if changed
            $sparePartsTotal = 0.00;
            if ($serviceRecord->spare_parts_changed && isset($data['spare_parts']) && is_array($data['spare_parts'])) {
                foreach ($data['spare_parts'] as $part) {
                    $source = isset($part['source']) ? $part['source'] : 'inventory';
                    $quantity = (float) (isset($part['quantity']) ? $part['quantity'] : 1.00);

                    if ($source === 'inventory') {
                        $inventoryProductId = (int) $part['inventory_product_id'];

                        // Deduct stock via existing Inventory module service (ensures min_stock check)
                        $stockResult = $this->inventoryStockService->deductStock(
                            $inventoryProductId,
                            $quantity,
                            $userId,
                            "Ticket: {$ticketNumber}"
                        );

                        $partName = $stockResult['part_name'];
                        $unitPrice = (float) (isset($part['unit_price']) ? $part['unit_price'] : $stockResult['unit_price']);
                        $partAmount = $quantity * $unitPrice;

                        ServiceSparePart::create([
                            'service_record_id'    => $serviceRecord->id,
                            'source'               => 'inventory',
                            'inventory_product_id' => $inventoryProductId,
                            'part_name'            => $partName,
                            'vendor_name'          => null,
                            'quantity'             => $quantity,
                            'unit_price'           => $unitPrice,
                            'amount'               => $partAmount,
                        ]);

                        $sparePartsTotal += $partAmount;
                    } else {
                        $partName = $part['part_name'];
                        $vendorName = isset($part['vendor_name']) ? $part['vendor_name'] : null;
                        $partAmount = (float) (isset($part['amount']) ? $part['amount'] : 0.00);
                        $unitPrice = isset($part['unit_price']) ? (float) $part['unit_price'] : ($quantity > 0 ? $partAmount / $quantity : 0.00);

                        ServiceSparePart::create([
                            'service_record_id'    => $serviceRecord->id,
                            'source'               => 'vendor',
                            'inventory_product_id' => null,
                            'part_name'            => $partName,
                            'vendor_name'          => $vendorName,
                            'quantity'             => $quantity,
                            'unit_price'           => $unitPrice,
                            'amount'               => $partAmount,
                        ]);

                        $sparePartsTotal += $partAmount;
                    }
                }
            }

            // Recalculate totals server-side
            $totalAmount = $baseAmount + $checklistTotal + $sparePartsTotal;
            $serviceRecord->update([
                'checklist_amount_total'   => $checklistTotal,
                'spare_parts_amount_total' => $sparePartsTotal,
                'total_amount'             => $totalAmount,
            ]);

            // File uploads
            if (isset($data['attachments']) && is_array($data['attachments'])) {
                foreach ($data['attachments'] as $file) {
                    if ($file->isValid()) {
                        $path = $file->store('service_attachments', 'public');
                        ServiceAttachment::create([
                            'service_record_id' => $serviceRecord->id,
                            'file_path'         => $path,
                            'file_name'         => $file->getClientOriginalName(),
                            'file_type'         => $file->getClientMimeType(),
                            'file_size'         => $file->getSize(),
                            'uploaded_by'       => $userId,
                        ]);
                    }
                }
            }

            // Initial status history
            ServiceStatusHistory::create([
                'service_record_id' => $serviceRecord->id,
                'from_status'       => null,
                'to_status'         => $status,
                'remarks'           => $status === 'completed'
                    ? 'Service record created and completed with downtime recorded.'
                    : 'Initial service record created.',
                'changed_by'        => $userId,
                'created_at'        => now(),
            ]);

            // Initial audit log
            ServiceAuditLog::create([
                'service_record_id' => $serviceRecord->id,
                'action'            => 'created',
                'changes'           => null,
                'performed_by'      => $userId,
                'created_at'        => now(),
            ]);

            // Downtime supplied up front closes the linked breakdown immediately.
            if ($status === 'completed') {
                $this->closeLinkedBreakdown($serviceRecord, $userId);
            }

            return $serviceRecord->fresh()->load([
                'machine',
                'site',
                'breakdown',
                'checklistDetail',
                'spareParts.inventoryProduct',
                'attachments',
                'statusHistory',
                'auditLogs',
                'creator',
            ]);
        });
    }

    /**
     * Update an existing Service Record.
     *
     * @param ServiceRecord $record
     * @param array $data
     * @param int $userId
     * @return ServiceRecord
     */
    public function update(ServiceRecord $record, array $data, $userId)
    {
        return DB::transaction(function () use ($record, $data, $userId) {
            $oldData = $record->toArray();
            $oldStatus = $record->status;

            if (isset($data['service_date'])) {
                $record->service_date = $data['service_date'];
            }
            if (array_key_exists('hours_odometer_reading', $data)) {
                $record->hours_odometer_reading = $data['hours_odometer_reading'];
            }
            if (array_key_exists('km_run', $data)) {
                $record->km_run = $data['km_run'];
            }
            if (array_key_exists('time_gap_months', $data)) {
                $record->time_gap_months = $data['time_gap_months'];
            }
            if (array_key_exists('base_service_amount', $data)) {
                $record->base_service_amount = (float) $data['base_service_amount'];
            }
            if (array_key_exists('performed_by', $data)) {
                $record->performed_by = $data['performed_by'];
            }
            if (array_key_exists('remarks', $data)) {
                $record->remarks = $data['remarks'];
            }

            // Incoming values are times only. Anything not supplied falls back to
            // the time already stored, so the window is rebuilt against the current
            // service_date even when only one end changes or the date itself moved.
            $startTime = array_key_exists('downtime_start', $data)
                ? $data['downtime_start']
                : ($record->downtime_start ? $record->downtime_start->format('H:i:s') : null);

            $endTime = array_key_exists('downtime_end', $data)
                ? $data['downtime_end']
                : ($record->downtime_end ? $record->downtime_end->format('H:i:s') : null);

            list($downtimeStart, $downtimeEnd, $downtimeMinutes) = $this->resolveDowntimeWindow(
                $record->service_date,
                $startTime,
                $endTime
            );

            $record->downtime_start = $downtimeStart;
            $record->downtime_end = $downtimeEnd;

            if (isset($data['status'])) {
                $record->status = $data['status'];
            }

            // Downtime is the completion signal: a full window completes the record,
            // and completing without one would leave a closed breakdown reporting
            // zero downtime, which is what moving downtime here was meant to fix.
            $record->downtime_minutes = $downtimeMinutes;

            if ($record->downtime_minutes !== null) {
                $record->status = 'completed';
            } elseif ($record->status === 'completed') {
                throw new HttpResponseException(response()->json([
                    'status' => 422,
                    'message' => 'Validation failed',
                    'errors' => [
                        'downtime_end' => ['Downtime start and downtime end are required to complete a service record.']
                    ]
                ], 422));
            }

            $record->updated_by = $userId;
            $record->save();

            // Update checklist details if passed
            $checklistTotal = (float) $record->checklist_amount_total;
            if (isset($data['checklist']) && is_array($data['checklist'])) {
                $checklistData = $data['checklist'];
                $checklistRecord = ServiceChecklistDetail::firstOrNew(['service_record_id' => $record->id]);

                $oilChange = isset($checklistData['oil_change']) ? filter_var($checklistData['oil_change'], FILTER_VALIDATE_BOOLEAN) : $checklistRecord->oil_change;
                $oilChangeAmt = $oilChange ? (float) (isset($checklistData['oil_change_amount']) ? $checklistData['oil_change_amount'] : $checklistRecord->oil_change_amount) : 0.00;

                $hydraulicOil = isset($checklistData['hydraulic_oil']) ? filter_var($checklistData['hydraulic_oil'], FILTER_VALIDATE_BOOLEAN) : $checklistRecord->hydraulic_oil;
                $hydraulicOilAmt = $hydraulicOil ? (float) (isset($checklistData['hydraulic_oil_amount']) ? $checklistData['hydraulic_oil_amount'] : $checklistRecord->hydraulic_oil_amount) : 0.00;

                $gearOil = isset($checklistData['gear_oil']) ? filter_var($checklistData['gear_oil'], FILTER_VALIDATE_BOOLEAN) : $checklistRecord->gear_oil;
                $gearOilAmt = $gearOil ? (float) (isset($checklistData['gear_oil_amount']) ? $checklistData['gear_oil_amount'] : $checklistRecord->gear_oil_amount) : 0.00;

                $fuelFilter = isset($checklistData['fuel_filter_change']) ? filter_var($checklistData['fuel_filter_change'], FILTER_VALIDATE_BOOLEAN) : $checklistRecord->fuel_filter_change;
                $fuelFilterAmt = $fuelFilter ? (float) (isset($checklistData['fuel_filter_change_amount']) ? $checklistData['fuel_filter_change_amount'] : $checklistRecord->fuel_filter_change_amount) : 0.00;

                $oilFilter = isset($checklistData['oil_filter_change']) ? filter_var($checklistData['oil_filter_change'], FILTER_VALIDATE_BOOLEAN) : $checklistRecord->oil_filter_change;
                $oilFilterAmt = $oilFilter ? (float) (isset($checklistData['oil_filter_change_amount']) ? $checklistData['oil_filter_change_amount'] : $checklistRecord->oil_filter_change_amount) : 0.00;

                $checklistRecord->oil_change                = $oilChange;
                $checklistRecord->oil_change_amount         = $oilChangeAmt;
                $checklistRecord->hydraulic_oil             = $hydraulicOil;
                $checklistRecord->hydraulic_oil_amount      = $hydraulicOilAmt;
                $checklistRecord->gear_oil                  = $gearOil;
                $checklistRecord->gear_oil_amount           = $gearOilAmt;
                $checklistRecord->fuel_filter_change        = $fuelFilter;
                $checklistRecord->fuel_filter_change_amount = $fuelFilterAmt;
                $checklistRecord->oil_filter_change         = $oilFilter;
                $checklistRecord->oil_filter_change_amount  = $oilFilterAmt;
                $checklistRecord->save();

                $checklistTotal = $oilChangeAmt + $hydraulicOilAmt + $gearOilAmt + $fuelFilterAmt + $oilFilterAmt;
            }

            // Spare parts are replaced wholesale when the key is present: the edit
            // form posts the list it wants to end up with, not a delta. Absent key
            // means "leave the parts alone", so partial updates stay safe.
            $sparePartsDiff = null;
            if (array_key_exists('spare_parts', $data) || array_key_exists('spare_parts_changed', $data)) {
                // Sending a list without the flag still means parts were used —
                // deriving it from the list keeps a caller that omits the flag
                // from silently wiping the parts it just posted.
                $partsChanged = array_key_exists('spare_parts_changed', $data)
                    ? filter_var($data['spare_parts_changed'], FILTER_VALIDATE_BOOLEAN)
                    : !empty($data['spare_parts']);

                // Clearing the flag is how the UI says "no parts were used after
                // all", so it empties the list and returns the stock either way.
                $incomingParts = $partsChanged && isset($data['spare_parts']) && is_array($data['spare_parts'])
                    ? $data['spare_parts']
                    : [];

                $sparePartsDiff = $this->syncSpareParts($record, $incomingParts, $userId);

                $record->spare_parts_changed = $partsChanged;
                $record->save();
            }

            // Recalculate totals server-side
            $sparePartsTotal = (float) ServiceSparePart::where('service_record_id', $record->id)->sum('amount');
            $baseAmount = (float) $record->base_service_amount;
            $totalAmount = $baseAmount + $checklistTotal + $sparePartsTotal;

            $record->update([
                'checklist_amount_total'   => $checklistTotal,
                'spare_parts_amount_total' => $sparePartsTotal,
                'total_amount'             => $totalAmount,
            ]);

            // Handle file attachments if uploaded during update
            if (isset($data['attachments']) && is_array($data['attachments'])) {
                foreach ($data['attachments'] as $file) {
                    if ($file->isValid()) {
                        $path = $file->store('service_attachments', 'public');
                        ServiceAttachment::create([
                            'service_record_id' => $record->id,
                            'file_path'         => $path,
                            'file_name'         => $file->getClientOriginalName(),
                            'file_type'         => $file->getClientMimeType(),
                            'file_size'         => $file->getSize(),
                            'uploaded_by'       => $userId,
                        ]);
                    }
                }
            }

            // If status changed
            if ($oldStatus !== $record->status) {
                ServiceStatusHistory::create([
                    'service_record_id' => $record->id,
                    'from_status'       => $oldStatus,
                    'to_status'         => $record->status,
                    'remarks'           => isset($data['remarks']) ? $data['remarks'] : 'Status updated.',
                    'changed_by'        => $userId,
                    'created_at'        => now(),
                ]);

                // Completing a breakdown service closes the linked ticket.
                if ($record->status === 'completed') {
                    $this->closeLinkedBreakdown($record, $userId);
                }
            }

            // Write Audit Log
            $newData = $record->fresh()->toArray();
            $diff = [];
            foreach ($newData as $key => $val) {
                if (array_key_exists($key, $oldData) && $oldData[$key] != $val) {
                    $diff[$key] = [
                        'old' => $oldData[$key],
                        'new' => $val,
                    ];
                }
            }

            // Parts live in their own table, so the column diff above would only
            // ever show the totals moving. Spell the swap out instead — which part
            // went back to stock and which came out is the point of the trail.
            if ($sparePartsDiff) {
                $diff['spare_parts'] = $sparePartsDiff;
            }

            if (!empty($diff)) {
                ServiceAuditLog::create([
                    'service_record_id' => $record->id,
                    'action'            => 'updated',
                    'changes'           => $diff,
                    'performed_by'      => $userId,
                    'created_at'        => now(),
                ]);
            }

            return $record->fresh()->load([
                'machine',
                'site',
                'breakdown',
                'checklistDetail',
                'spareParts.inventoryProduct',
                'attachments',
                'statusHistory',
                'auditLogs',
                'creator',
                'updater',
            ]);
        });
    }

    /**
     * Replace a record's spare parts, reconciling inventory stock as it goes.
     *
     * Stock moves on the net change per product rather than on each row, so
     * re-saving a form without touching the parts writes nothing to the ledger,
     * and lowering a quantity from 5 to 3 returns 2 units instead of returning 5
     * and re-issuing 3. Returns run before deductions so stock freed by a removed
     * part is available to the parts replacing it, including for the min_stock
     * check — swapping one part for another can't fail on stock the swap itself
     * releases.
     *
     * @param  ServiceRecord  $record
     * @param  array  $parts  The full list the record should end up with.
     * @param  int  $userId
     * @return array|null  Old/new summary for the audit log, null when nothing moved.
     */
    protected function syncSpareParts(ServiceRecord $record, array $parts, $userId)
    {
        $existing = ServiceSparePart::where('service_record_id', $record->id)->get();

        if ($existing->isEmpty() && empty($parts)) {
            return null;
        }

        $oldQuantities = [];
        foreach ($existing as $row) {
            if ($row->source === 'inventory' && $row->inventory_product_id) {
                $productId = (int) $row->inventory_product_id;
                $oldQuantities[$productId] = (isset($oldQuantities[$productId]) ? $oldQuantities[$productId] : 0.00)
                    + (float) $row->quantity;
            }
        }

        $newQuantities = [];
        foreach ($parts as $part) {
            $source = isset($part['source']) ? $part['source'] : 'inventory';

            if ($source !== 'inventory' || empty($part['inventory_product_id'])) {
                continue;
            }

            $productId = (int) $part['inventory_product_id'];
            $newQuantities[$productId] = (isset($newQuantities[$productId]) ? $newQuantities[$productId] : 0.00)
                + (float) (isset($part['quantity']) ? $part['quantity'] : 1.00);
        }

        $returns = [];
        $deductions = [];
        $productIds = array_unique(array_merge(array_keys($oldQuantities), array_keys($newQuantities)));

        foreach ($productIds as $productId) {
            $old = isset($oldQuantities[$productId]) ? $oldQuantities[$productId] : 0.00;
            $new = isset($newQuantities[$productId]) ? $newQuantities[$productId] : 0.00;
            $delta = $new - $old;

            if ($delta < 0) {
                $returns[$productId] = abs($delta);
            } elseif ($delta > 0) {
                $deductions[$productId] = $delta;
            }
        }

        foreach ($returns as $productId => $quantity) {
            $this->inventoryStockService->restockStock(
                $productId,
                $quantity,
                $userId,
                "Ticket: {$record->ticket_number}"
            );
        }

        $partNames = [];
        foreach ($deductions as $productId => $quantity) {
            $result = $this->inventoryStockService->deductStock(
                $productId,
                $quantity,
                $userId,
                "Ticket: {$record->ticket_number}"
            );

            $partNames[$productId] = $result['part_name'];
        }

        $before = $this->sparePartsSummary($existing);

        ServiceSparePart::where('service_record_id', $record->id)->delete();

        foreach ($parts as $part) {
            $source = isset($part['source']) ? $part['source'] : 'inventory';
            $quantity = (float) (isset($part['quantity']) ? $part['quantity'] : 1.00);

            if ($source === 'inventory') {
                $productId = (int) $part['inventory_product_id'];

                // A product whose quantity was unchanged or reduced never went
                // through deductStock above, so its name is still unresolved.
                if (!isset($partNames[$productId])) {
                    $product = InventoryProduct::find($productId);
                    $partNames[$productId] = $product ? $product->name : 'Unknown Product';
                }

                // Inventory-issued parts carry no price: products are tracked by
                // quantity only, and their cost sits in the inventory module.
                ServiceSparePart::create([
                    'service_record_id'    => $record->id,
                    'source'               => 'inventory',
                    'inventory_product_id' => $productId,
                    'part_name'            => $partNames[$productId],
                    'vendor_name'          => null,
                    'quantity'             => $quantity,
                    'unit_price'           => 0.00,
                    'amount'               => 0.00,
                ]);

                continue;
            }

            $partAmount = (float) (isset($part['amount']) ? $part['amount'] : 0.00);

            ServiceSparePart::create([
                'service_record_id'    => $record->id,
                'source'               => 'vendor',
                'inventory_product_id' => null,
                'part_name'            => $part['part_name'],
                'vendor_name'          => isset($part['vendor_name']) ? $part['vendor_name'] : null,
                'quantity'             => $quantity,
                'unit_price'           => $quantity > 0 ? $partAmount / $quantity : 0.00,
                'amount'               => $partAmount,
            ]);
        }

        $after = $this->sparePartsSummary(
            ServiceSparePart::where('service_record_id', $record->id)->get()
        );

        if ($before == $after) {
            return null;
        }

        return ['old' => $before, 'new' => $after];
    }

    /**
     * Flatten spare part rows into the shape stored in the audit log.
     *
     * @param  \Illuminate\Support\Collection  $parts
     * @return array
     */
    protected function sparePartsSummary($parts)
    {
        return $parts->map(function ($part) {
            return [
                'source'               => $part->source,
                'inventory_product_id' => $part->inventory_product_id ? (int) $part->inventory_product_id : null,
                'part_name'            => $part->part_name,
                'vendor_name'          => $part->vendor_name,
                'quantity'             => (float) $part->quantity,
                'amount'               => (float) $part->amount,
            ];
        })->values()->all();
    }

    /**
     * Build the downtime window from the service date and two clock times.
     *
     * The client sends times only ("08:00" / "08:00:00") because the calendar day
     * is already on the record as service_date. An end time that is earlier than
     * the start means the repair ran past midnight, so it lands on the next day —
     * without that, a night shift job like 22:00 to 02:00 would come out negative.
     *
     * @param  mixed  $serviceDate
     * @param  string|null  $startTime
     * @param  string|null  $endTime
     * @return array  [Carbon|null $start, Carbon|null $end, int|null $minutes]
     */
    protected function resolveDowntimeWindow($serviceDate, $startTime, $endTime)
    {
        if (empty($startTime)) {
            return [null, null, null];
        }

        $date = $serviceDate instanceof Carbon
            ? $serviceDate->toDateString()
            : Carbon::parse($serviceDate)->toDateString();

        $start = Carbon::parse($date . ' ' . $startTime);

        if (empty($endTime)) {
            return [$start, null, null];
        }

        $end = Carbon::parse($date . ' ' . $endTime);

        if ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        return [$start, $end, (int) $end->diffInMinutes($start)];
    }

    /**
     * Close the breakdown ticket linked to a completed service record.
     *
     * The service record owns the downtime window now, so it is pushed onto the
     * ticket verbatim. breakdown_tickets.downtime_minutes still feeds the MTTR,
     * total downtime and equipment availability dashboards, so keeping it in sync
     * is what lets those reports survive the flow change untouched.
     *
     * @param  ServiceRecord  $record
     * @param  int  $userId
     * @return void
     */
    protected function closeLinkedBreakdown(ServiceRecord $record, $userId)
    {
        if (!$record->is_breakdown_service || !$record->breakdown_id) {
            return;
        }

        $breakdown = Breakdown::find($record->breakdown_id);

        if (!$breakdown || strtolower($breakdown->status) === 'closed') {
            return;
        }

        $breakdown->update([
            'status'           => 'closed',
            'resolved_at'      => now(),
            'resolved_by'      => $userId,
            'downtime_start'   => $record->downtime_start,
            'downtime_end'     => $record->downtime_end,
            'downtime_minutes' => $record->downtime_minutes,
            'resolution_notes' => 'Resolved via Service Record Ticket #' . $record->ticket_number,
        ]);
    }

    /**
     * Soft delete a Service Record.
     * Note: Inventory deductions are not automatically reversed on delete (manual reversal decision).
     *
     * @param ServiceRecord $record
     * @param int $userId
     * @return void
     */
    public function delete(ServiceRecord $record, $userId)
    {
        DB::transaction(function () use ($record, $userId) {
            ServiceAuditLog::create([
                'service_record_id' => $record->id,
                'action'            => 'deleted',
                'changes'           => null,
                'performed_by'      => $userId,
                'created_at'        => now(),
            ]);

            $record->delete();
        });
    }

    /**
     * Service history for one machine: the summary cards plus the timeline.
     *
     * Cancelled records are excluded throughout — work that never happened is
     * neither an expense nor a service the machine actually received.
     *
     * @param int $machineId
     * @param array $filters
     * @return array
     */
    public function getMachineServiceHistory($machineId, array $filters = [])
    {
        $machine = Machine::with('equipment')->find($machineId);

        $base = ServiceRecord::where('machine_id', $machineId)
            ->where('status', '!=', 'cancelled');

        // Summary is aggregated in one query, unfiltered, so the cards always
        // describe the whole machine even while the timeline below is filtered.
        $summary = (clone $base)->selectRaw("
                COUNT(*) as total_services,
                COALESCE(SUM(total_amount), 0) as total_expense,
                COALESCE(SUM(CASE WHEN service_type = 'general' THEN 1 ELSE 0 END), 0) as general_services,
                COALESCE(SUM(CASE WHEN service_type = 'repair' THEN 1 ELSE 0 END), 0) as repair_jobs,
                COALESCE(SUM(downtime_minutes), 0) as total_downtime_minutes
            ")->first();

        $query = (clone $base)
            ->select([
                'id',
                'ticket_number',
                'machine_id',
                'breakdown_id',
                'is_breakdown_service',
                'service_type',
                'service_date',
                'hours_odometer_reading',
                'km_run',
                'downtime_minutes',
                'total_amount',
                'status',
                'performed_by',
                'created_at',
                'deleted_at',
            ])
            ->with(['checklistDetail', 'breakdown:id,ticket_number'])
            ->withCount(['spareParts', 'attachments']);

        if (!empty($filters['service_type'])) {
            $query->where('service_type', $filters['service_type']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['date_from'])) {
            $query->where('service_date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('service_date', '<=', $filters['date_to']);
        }

        // Newest first, with id as the tiebreaker so same-day services keep a
        // stable order across pages.
        $query->orderBy('service_date', 'desc')->orderBy('id', 'desc');

        $totalDowntimeMinutes = (int) $summary->total_downtime_minutes;

        $payload = [
            'machine' => $machine ? [
                'id'            => $machine->id,
                'name'          => $machine->equipment_name,
                'category_id'   => $machine->equipment_id,
                'category_name' => optional($machine->equipment)->name,
                'is_active'     => (bool) $machine->is_active,
            ] : null,
            'summary' => [
                'total_expense'          => (float) $summary->total_expense,
                'total_services'         => (int) $summary->total_services,
                'general_services'       => (int) $summary->general_services,
                'repair_jobs'            => (int) $summary->repair_jobs,
                'total_downtime_minutes' => $totalDowntimeMinutes,
                'total_downtime_hours'   => round($totalDowntimeMinutes / 60, 2),
            ],
        ];

        if (!empty($filters['limit'])) {
            $records = $query->paginate($filters['limit']);

            $payload['history'] = ServiceRecordHistoryResource::collection($records->items());
            $payload['pagination'] = [
                'current_page' => $records->currentPage(),
                'last_page'    => $records->lastPage(),
                'per_page'     => $records->perPage(),
                'total'        => $records->total(),
                'from'         => $records->firstItem(),
                'to'           => $records->lastItem(),
            ];

            return $payload;
        }

        $payload['history'] = ServiceRecordHistoryResource::collection($query->get());

        return $payload;
    }

    /**
     * Get combined audit trail (audit logs + status history) for a record.
     *
     * @param int $serviceRecordId
     * @return Collection
     */
    public function getAuditTrail($serviceRecordId)
    {
        $auditLogs = ServiceAuditLog::where('service_record_id', $serviceRecordId)
            ->with('performer.employee')
            ->get()
            ->map(function ($item) {
                return [
                    'type'         => 'audit_log',
                    'action'       => $item->action,
                    'changes'      => $item->changes,
                    'performed_by' => $this->userLabel($item->performer),
                    'created_at'   => $item->created_at,
                ];
            });

        $statusHistory = ServiceStatusHistory::where('service_record_id', $serviceRecordId)
            ->with('changer.employee')
            ->get()
            ->map(function ($item) {
                return [
                    'type'        => 'status_history',
                    'from_status' => $item->from_status,
                    'to_status'   => $item->to_status,
                    'remarks'     => $item->remarks,
                    'changed_by'  => $this->userLabel($item->changer),
                    'created_at'  => $item->created_at,
                ];
            });

        return $auditLogs->concat($statusHistory)->sortByDesc('created_at')->values();
    }

    /**
     * Display label for a user acting on a service record.
     *
     * The users table holds no name, so the readable name comes from the linked
     * employee. Falls back to the login email when a user has no employee record.
     *
     * @param  \App\Models\User|null  $user
     * @return string|null
     */
    protected function userLabel($user)
    {
        if (!$user) {
            return null;
        }

        $name = optional($user->employee)->name;

        return $name ? $name : $user->email;
    }
}
