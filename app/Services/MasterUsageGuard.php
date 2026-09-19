<?php

namespace App\Services;

use App\Models\BreakdownTicket;
use App\Models\BreakdownType;
use App\Models\Category;
use App\Models\Delay;
use App\Models\DelayCategory;
use App\Models\Department;
use App\Models\DispatchTrip;
use App\Models\Employee;
use App\Models\EmployeeProductAssignment;
use App\Models\FuelEntry;
use App\Models\Holiday;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\Inventory;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\Product;
use App\Models\Role;
use App\Models\ServiceRecord;
use App\Models\ServiceSparePart;
use App\Models\ShiftPlan;
use App\Models\Site;
use App\Models\SitePoint;
use App\Models\Store;
use App\Models\SubCategory;
use App\Models\Training;
use App\Models\TrainingType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Decides whether a master row may still be switched off.
 *
 * A master that something is still using cannot be deactivated, because the
 * records using it have to keep rendering it: a dropdown feed only serves
 * active rows, so retiring a master that is still referenced opens the
 * referencing record's edit screen with a blank picker — and saves the blank
 * back over a value the user never touched.
 *
 * "Still using it" deliberately means *live* usage, not any usage at all.
 * A record that is itself switched off, closed or cancelled is never going to
 * be edited again, so it does not need its picker to work and it does not hold
 * the master hostage. Without that rule every master would be a one-way door:
 * one assignment, ever, and it could never be retired.
 *
 * Which tables count, and what "live" means in each, is the map below. Pure
 * history that no screen edits — attendance, inventory logs, alerts, shift
 * histories — is deliberately absent: those rows are never reopened, so they
 * have no say in whether a master may retire.
 *
 * Reactivating is always allowed. Nothing breaks by putting a row back.
 */
class MasterUsageGuard
{
    /**
     * Every master class this guard knows how to check.
     *
     * @return array<int, string>
     */
    public function guardedMasters(): array
    {
        return array_keys($this->map());
    }

    /**
     * Live usages standing in the way of deactivating this master.
     *
     * @return array<int, array{label: string, count: int}>  empty means it may be switched off
     */
    public function blockers(Model $master): array
    {
        $checks = $this->checksFor($master);
        $id = $master->getKey();
        $blockers = [];

        foreach ($checks as $label => $count) {
            $found = (int) $count($id);

            if ($found > 0) {
                $blockers[] = ['label' => $label, 'count' => $found];
            }
        }

        return $blockers;
    }

    /**
     * One sentence naming what is in the way, or null when nothing is.
     */
    public function blockMessage(Model $master): ?string
    {
        $blockers = $this->blockers($master);

        if (empty($blockers)) {
            return null;
        }

        $parts = array_map(function ($blocker) {
            return $blocker['count'] . ' ' . $blocker['label'];
        }, $blockers);

        return 'This record cannot be deactivated because it is still in use by '
            . $this->joinWords($parts)
            . '. Deactivate or close them first.';
    }

    /**
     * @param  array<int, string>  $parts
     */
    protected function joinWords(array $parts): string
    {
        if (count($parts) === 1) {
            return $parts[0];
        }

        $last = array_pop($parts);

        return implode(', ', $parts) . ' and ' . $last;
    }

    /**
     * Master class => [human label => closure taking the master id, returning a count].
     *
     * @return array<string, callable>
     */
    protected function checksFor(Model $master): array
    {
        return $this->map()[get_class($master)] ?? [];
    }

    /**
     * @return array<string, array<string, callable>>
     */
    protected function map(): array
    {
        return [

            Department::class => [
                'active employee(s)' => fn($id) => Employee::where('department_id', $id)->where('is_active', 1)->count(),
                'product assignment(s) held by active employees' => fn($id) => EmployeeProductAssignment::where('department_id', $id)
                    ->whereHas('employee', fn($q) => $q->where('is_active', 1))->count(),
            ],

            Role::class => [
                'active employee(s)' => fn($id) => Employee::where('designation_id', $id)->where('is_active', 1)->count(),
                'active user account(s)' => fn($id) => DB::table('role_user')
                    ->join('users', 'users.id', '=', 'role_user.user_id')
                    ->where('role_user.role_id', $id)->where('users.is_active', 1)->count(),
            ],

            Site::class => [
                'active employee(s)' => fn($id) => Employee::where('site_id', $id)->where('is_active', 1)->count(),
                'active site point(s)' => fn($id) => SitePoint::where('site_id', $id)->where('is_active', 1)->count(),
                'active holiday(s)' => fn($id) => Holiday::where('site_id', $id)->where('is_active', 1)->count(),
                'open shift plan(s)' => fn($id) => ShiftPlan::where('site_id', $id)->notClosed()->count(),
                'open breakdown ticket(s)' => fn($id) => BreakdownTicket::where('mine_site_id', $id)->where('status', '!=', 'closed')->count(),
                'incident(s) under review' => fn($id) => Incident::where('location_id', $id)->where('status', 'Under Review')->count(),
                'open service record(s)' => fn($id) => ServiceRecord::where('site_id', $id)->whereNotIn('status', ['completed', 'cancelled'])->count(),
                'active fuel entr(y/ies)' => fn($id) => FuelEntry::where('mine_site_id', $id)->where('status', 'active')->count(),
                'dispatch trip(s)' => fn($id) => DispatchTrip::where('site_id', $id)->orWhere('mine_site_id', $id)->count(),
                'product assignment(s) held by active employees' => fn($id) => EmployeeProductAssignment::where('site_id', $id)
                    ->whereHas('employee', fn($q) => $q->where('is_active', 1))->count(),
            ],

            // Shifts and relays are deliberately unguarded: the client wants both
            // switchable at any time. Their only real blocker was the weekly
            // relay_shift_mappings rota, which each of them held the other
            // hostage with, and the rest of their usage (shift plans, tickets,
            // trips) is reporting history. If a screen ever reopens holding a
            // retired shift or relay, its picker shows blank, not a wrong value.

            LeaveType::class => [
                'pending or approved leave(s)' => fn($id) => Leave::where('leave_type_id', $id)
                    ->whereIn('status', ['pending', 'approved'])->count(),
            ],

            TrainingType::class => [
                'active training(s)' => fn($id) => Training::where('training_type_id', $id)->where('is_active', 1)->count(),
            ],

            IncidentType::class => [
                'incident(s) under review' => fn($id) => Incident::where('incident_type_id', $id)->where('status', 'Under Review')->count(),
            ],

            BreakdownType::class => [
                'open breakdown ticket(s)' => fn($id) => BreakdownTicket::where('breakdown_type_id', $id)->where('status', '!=', 'closed')->count(),
            ],

            DelayCategory::class => [
                // Delays carry no status of their own and stay editable, so any
                // delay on this category still needs it in its picker.
                'logged delay(s)' => fn($id) => Delay::where('delay_category_id', $id)->count(),
            ],

            SitePoint::class => [
                'dispatch trip(s)' => fn($id) => DispatchTrip::where('loading_point_id', $id)
                    ->orWhere('dumping_point_id', $id)->count(),
            ],

            Store::class => [
                'product(s) still in stock here' => fn($id) => Inventory::where('store_id', $id)
                    ->where('is_active', 1)->where('left_quantity', '>', 0)->count(),
                'product assignment(s) held by active employees' => fn($id) => EmployeeProductAssignment::where('store_id', $id)
                    ->whereHas('employee', fn($q) => $q->where('is_active', 1))->count(),
                'open service record(s)' => fn($id) => ServiceRecord::where('store_id', $id)
                    ->whereNotIn('status', ['completed', 'cancelled'])->count(),
            ],

            Product::class => [
                'unit(s) still in stock' => fn($id) => (int) Inventory::where('product_id', $id)
                    ->where('is_active', 1)->sum('left_quantity'),
                'assignment(s) held by active employees' => fn($id) => EmployeeProductAssignment::where('product_id', $id)
                    ->whereHas('employee', fn($q) => $q->where('is_active', 1))->count(),
                // Spare parts point at an inventory row, not at the product,
                // so the product is reached through the store's stock row.
                'open service record(s) using it as a spare part' => fn($id) => ServiceSparePart::whereHas('inventory', fn($q) => $q->where('product_id', $id))
                    ->whereHas('serviceRecord', fn($q) => $q->whereNotIn('status', ['completed', 'cancelled']))->count(),
            ],

            SubCategory::class => [
                'active product(s)' => fn($id) => Product::where('sub_category_id', $id)->where('is_active', 1)->count(),
            ],

            Category::class => [
                'active sub category(ies)' => fn($id) => SubCategory::where('category_id', $id)->where('is_active', 1)->count(),
            ],

            // Nothing references holidays, so one can always be switched off.
            // Listed so the master is knowingly covered rather than forgotten.
            Holiday::class => [],
        ];
    }
}
