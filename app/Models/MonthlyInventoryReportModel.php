<?php
namespace App\Models;
use CodeIgniter\Model;
class MonthlyInventoryReportModel extends Model
{
    protected $table = 'monthly_inventory_reports'; protected $primaryKey = 'id'; protected $returnType = 'array';
    protected $allowedFields = ['report_month','status','submitted_by','submitted_at','updated_at','version']; protected $useTimestamps = false;
}
