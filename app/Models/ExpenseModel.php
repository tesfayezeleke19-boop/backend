<?php
namespace App\Models;
use CodeIgniter\Model;
class ExpenseModel extends Model
{
    protected $table = 'expenses'; protected $primaryKey = 'id'; protected $returnType = 'array';
    protected $allowedFields = ['expense_number','expense_date','expense_type','description','supplier_name','supplier_tin','amount_excl_vat','vat_enabled','vat_rate','vat_amount','wht_enabled','wht_rate','wht_amount','total_amount','status','submitted_by','submitted_at','reviewed_by','reviewed_at','rejection_reason','paid_at','paid_by','attachment_id','created_by','updated_at','version'];
    protected $useTimestamps = false;
}
