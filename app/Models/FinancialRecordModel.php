<?php
namespace App\Models;
use CodeIgniter\Model;
class FinancialRecordModel extends Model
{
    protected $table = 'financial_records'; protected $primaryKey = 'id'; protected $returnType = 'array';
    protected $allowedFields = ['record_number','source_type','source_id','record_date','description','amount','status','created_by']; protected $useTimestamps = false;
}
