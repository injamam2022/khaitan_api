<?php

namespace App\Models;

use CodeIgniter\Model;

class ProfileModel extends Model
{
    protected $table = 'admin_login';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $protectFields = true;
    protected $allowedFields = [
        'username', 'password', 'fullname', 'usertype', 'user_type',
        'row_status', 'created_at', 'updated_at'
    ];

    protected $useTimestamps = false;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    protected $validationRules = [];
    protected $validationMessages = [];
    protected $skipValidation = false;
    protected $cleanValidationRules = true;

    protected $allowCallbacks = true;
    protected $beforeInsert = [];
    protected $afterInsert = [];
    protected $beforeUpdate = [];
    protected $afterUpdate = [];
    protected $beforeFind = [];
    protected $afterFind = [];
    protected $beforeDelete = [];
    protected $afterDelete = [];

    /**
     * Validate login credentials
     * 
     * Supports both bcrypt (new) and MD5 (legacy) passwords.
     * Automatically migrates MD5 passwords to bcrypt on successful login.
     * 
     * @param string $username Username
     * @param string $password Password (plain text)
     * @return object|false User object or false if invalid
     */
    /**
     * Validate login. Returns user object or false. Never throws.
     */
    public function validateLogin($username, $password)
    {
        try {
            $username = is_string($username) ? trim($username) : '';
            $password = is_string($password) ? $password : '';
            if ($username === '' || $password === '') {
                return false;
            }

            $builder = $this->db->table('admin_login');
            $builder->where('username', $username);
            $builder->limit(1);
            $result = $builder->get()->getRowArray();

            // If not found by username and input looks like email: try email column, or part before @
            if ((empty($result) || !isset($result['password'])) && str_contains($username, '@')) {
                try {
                    $fields = $this->db->getFieldNames($this->table);
                    if (is_array($fields) && in_array('email', $fields, true)) {
                        $builder2 = $this->db->table($this->table);
                        $builder2->where('email', $username);
                        $builder2->limit(1);
                        $result = $builder2->get()->getRowArray();
                    }
                } catch (\Throwable $e) {
                    // Ignore
                }
                if (empty($result) || !isset($result['password'])) {
                    $usernamePart = trim(explode('@', $username)[0]);
                    if ($usernamePart !== '') {
                        $builder3 = $this->db->table($this->table);
                        $builder3->where('username', $usernamePart);
                        $builder3->limit(1);
                        $result = $builder3->get()->getRowArray();
                    }
                }
            }

            if (empty($result) || !isset($result['password'])) {
                return false;
            }
            if (isset($result['row_status']) && $result['row_status'] !== 'ACTIVE') {
                return false;
            }

            $stored_hash = $result['password'];
            if (!is_string($stored_hash) || $stored_hash === '') {
                return false;
            }

            $password_valid = false;
            // Bcrypt (starts with $2y$ or $2a$)
            if (str_starts_with($stored_hash, '$2')) {
                $password_valid = password_verify($password, $stored_hash);
            }
            // Legacy MD5 (32-char hex)
            elseif (strlen($stored_hash) === 32 && ctype_xdigit($stored_hash) && md5($password) === $stored_hash) {
                $password_valid = true;
                $new_hash = password_hash($password, PASSWORD_BCRYPT);
                if ($new_hash !== false) {
                    $this->db->table('admin_login')->where('id', $result['id'])->update(['password' => $new_hash]);
                }
            }
            // Plain text stored by mistake (e.g. manual DB update): accept once and upgrade to bcrypt
            elseif ($stored_hash === $password) {
                $password_valid = true;
                $new_hash = password_hash($password, PASSWORD_BCRYPT);
                if ($new_hash !== false) {
                    $this->db->table('admin_login')->where('id', $result['id'])->update(['password' => $new_hash]);
                }
            }

            if ($password_valid) {
                return (object) $result;
            }
            return false;
        } catch (\Throwable $e) {
            log_message('error', 'ProfileModel::validateLogin - ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get user by ID
     * 
     * @param int $user_id User ID
     * @return object|false User object or false if not found
     */
    public function getUserById($user_id)
    {
        $builder = $this->db->table('admin_login');
        $builder->where('id', (int)$user_id);
        $builder->where('row_status', 'ACTIVE');
        $builder->limit(1);

        $result = $builder->get()->getRowArray();

        if (empty($result)) {
            return false;
        }

        return (object)$result;
    }

    /**
     * Check if old password matches
     * 
     * Supports both bcrypt (new) and MD5 (legacy) passwords.
     * 
     * @param int $uId User ID
     * @param string $oldpass Old password (plain text)
     * @return bool True if password matches, false otherwise
     */
    public function checkTheOldPassword($uId, $oldpass)
    {
        $builder = $this->db->table('admin_login');
        $builder->where('id', (int)$uId);
        $builder->limit(1);

        $result = $builder->get()->getRowArray();

        if (empty($result)) {
            return false;
        }

        $stored_hash = $result['password'];

        // Check if password is bcrypt (starts with $2y$ or $2a$)
        if (password_verify($oldpass, $stored_hash)) {
            return true;
        } elseif (md5($oldpass) === $stored_hash) {
            // Legacy MD5 password
            return true;
        }

        return false;
    }

    /**
     * Update user password
     * 
     * Uses bcrypt for secure password hashing.
     * 
     * @param int $uId User ID
     * @param string $newpass New password (plain text)
     * @return bool True if updated, false otherwise
     */
    public function updateThePassword($uId, $newpass)
    {
        // Use bcrypt for new passwords
        $nPass = password_hash($newpass, PASSWORD_BCRYPT);

        $builder = $this->db->table('admin_login');
        $builder->where('id', (int)$uId);

        return $builder->update(['password' => $nPass]);
    }
}
