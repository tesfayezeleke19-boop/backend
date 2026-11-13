<?php
namespace App\Models;
use CodeIgniter\Model;
class RoyaltyBatchModel extends Model
{
    protected $table = 'royalty_batches'; protected $primaryKey = 'id'; protected $returnType = 'array';
    protected $allowedFields = ['batch_number','status','total_amount','finalized_by','finalized_at']; protected $useTimestamps = false;
}
