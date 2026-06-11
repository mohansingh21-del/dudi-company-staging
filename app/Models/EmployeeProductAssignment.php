<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeProductAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'product_id',
        'issued_date',
        'site_id',
        'department_id',
        'quantity',
        'remarks',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'issued_date' => 'date',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function site()
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }
}
