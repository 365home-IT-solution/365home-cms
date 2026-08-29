<?php

namespace Modules\Payment\Entities;

use App\Models\Concerns\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PaymentConfiguration extends Model
{
    use HasFactory, LogsAuditTrail;
    protected $table = "payment_configurations";
    protected $fillable = ['client_id', 'api_key', 'checksum_key',];
}
