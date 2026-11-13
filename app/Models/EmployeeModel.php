<?php

namespace App\Models;

use CodeIgniter\Model;

class EmployeeModel extends Model
{
    protected $table            = 'employees';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $protectFields    = true;

    protected $allowedFields = [
        'id',
        'employee_id',
        'machine_employee_no',
        'full_name',
        'phone',
        'position',
        'hired_date',
        'emergency_contact_name',
        'emergency_phone',
        'document_attachment_id',
        'salary',
        'is_active',
        'updated_at',
    ];

    protected $useTimestamps = false;
}
