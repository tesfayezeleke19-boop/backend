<?php

namespace App\Models;

use CodeIgniter\Model;

class AttendanceModel extends Model
{
    protected $table            = 'attendance_records';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'id',
        'attendance_id',
        'employee_id',
        'attendance_date',
        'status',
        'overtime_hours',
        'note',
        'created_by',
        'updated_by',
        'created_at',
        'updated_at',
    ];
}
