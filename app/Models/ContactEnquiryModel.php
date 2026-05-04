<?php

namespace App\Models;

use CodeIgniter\Model;

class ContactEnquiryModel extends Model
{
    protected $table = 'contact_enquiries';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'name', 'email', 'phone', 'message', 'address', 'country', 'state', 'city', 'pin', 'form_source',
        'ip_address', 'user_agent', 'email_sent', 'created_at',
    ];
}
