<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One upload of an edited Form B spreadsheet, held for review before anything
 * is generated from it.
 *
 * Nothing here feeds back into employee_payrolls or payrolls — the figures the
 * user changed live in this staging area only.
 */
class WageRegisterUpload extends Model
{
    use HasFactory;

    protected $fillable = [
        'month',
        'year',
        'uploaded_by',
        'original_filename',
        'total_rows',
        'valid_rows',
        'error_rows',
        'status',
    ];

    protected $casts = [
        'month' => 'integer',
        'year' => 'integer',
        'total_rows' => 'integer',
        'valid_rows' => 'integer',
        'error_rows' => 'integer',
    ];

    public function rows()
    {
        return $this->hasMany(WageRegisterUploadRow::class, 'upload_id')->orderBy('excel_row');
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public static function forMonth(int $month, int $year): ?self
    {
        return static::where('month', $month)->where('year', $year)->first();
    }

    public function getMonthLabelAttribute(): string
    {
        return \Carbon\Carbon::create($this->year, $this->month, 1)->format('F Y');
    }

    /**
     * The reference shown in the upload list. Derived from the id rather than
     * stored, so it can never drift out of step with the row it names.
     */
    public function getDocumentIdAttribute(): string
    {
        return 'DOC-' . str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }

    /**
     * What the list column shows. `pending` is reported as "Failed" because to
     * the user the upload did not go through — there are cells to fix first.
     */
    public function getStatusLabelAttribute(): string
    {
        return [
            'pending' => 'Failed',
            'ready' => 'Ready',
        ][$this->status] ?? ucfirst($this->status);
    }
}
