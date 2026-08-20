<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Training extends Model
{
    use HasFactory;

    protected $table = 'trainings';

    protected $fillable = [
        'training_name',
        'training_type_id',
        'supervisor_id',
        'start_date',
        'end_date',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'is_active' => 'integer',
    ];

    public function trainingType()
    {
        return $this->belongsTo(TrainingType::class, 'training_type_id');
    }

    public function supervisor()
    {
        return $this->belongsTo(Employee::class, 'supervisor_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The enrolled batch. Kept as a plain belongsToMany so replacing the batch
     * on update is a single sync().
     */
    public function employees()
    {
        return $this->belongsToMany(
            Employee::class,
            'training_employees',
            'training_id',
            'employee_id'
        )->withTimestamps();
    }
}
