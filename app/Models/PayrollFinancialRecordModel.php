<?php
namespace App\Models;
use CodeIgniter\Model;
class PayrollFinancialRecordModel extends Model
{
    protected $table = 'payroll_financial_records'; protected $primaryKey = 'id'; protected $returnType = 'array';
    protected $allowedFields = ['record_number','payroll_run_id','record_date','total_amount','created_by']; protected $useTimestamps = false;
}
