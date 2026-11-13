<?php
namespace App\Models;
use CodeIgniter\Model;
class PayrollRunModel extends Model
{
    protected $table = 'payroll_runs'; protected $primaryKey = 'id'; protected $returnType = 'array';
    protected $allowedFields = ['payroll_id','period_label','period_month','period_year','start_date','end_date','status','overtime_rate','created_by','submitted_by','submitted_at','approved_by','approved_at','rejection_reason','paid_by','paid_at','updated_at','version']; protected $useTimestamps = false;
}
