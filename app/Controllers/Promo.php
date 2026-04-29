<?php

namespace App\Controllers;

use App\Models\PromoModel;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

class Promo extends BaseController
{
    use ResponseTrait;

    protected $promoModel;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        $this->promoModel = new PromoModel();
        helper(['api_helper']);
    }

    /**
     * Get list of promo codes
     * GET /backend/promos?status=ACTIVE|INACTIVE|DELETED
     */
    public function index()
    {
        try {
            // Get status filter from query parameter (optional)
            $status = $this->request->getGet('status') ?: null;
            
            $promo_list = $this->promoModel->get_promo($status);
            
            // Ensure promo_list is an array
            if (!is_array($promo_list)) {
                $promo_list = [];
            }
            
            return json_success($promo_list);
        } catch (\Exception $e) {
            log_message('error', 'Promo list error: ' . $e->getMessage());
            log_message('error', 'Stack trace: ' . $e->getTraceAsString());
            return json_error('Error loading promos: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Create new promo code
     * POST /backend/promos/add
     */
    public function add()
    {
        // Using CI4 recommended method check
        if ($this->request->is('post')) {
            try {
                $json = $this->request->getJSON(true);
                
                // Validate required fields
                if (empty($json['promo_code'])) {
                    return json_error('Promo code is required', 400);
                }

                // Prepare promo data
                $promo_data = [
                    'promo_code' => trim($json['promo_code']),
                    'discount_percent' => isset($json['discount_percent']) ? (float)$json['discount_percent'] : 0,
                    'discount_amount' => isset($json['discount_amount']) ? (float)$json['discount_amount'] : 0,
                    'min_order_amount' => isset($json['min_order_amount']) ? (float)$json['min_order_amount'] : 0,
                    'max_discount' => isset($json['max_discount']) ? (float)$json['max_discount'] : null,
                    'valid_from' => isset($json['valid_from']) && !empty($json['valid_from']) ? $json['valid_from'] : null,
                    'valid_to' => isset($json['valid_to']) && !empty($json['valid_to']) ? $json['valid_to'] : null,
                    'usage_limit' => isset($json['usage_limit']) && $json['usage_limit'] !== '' ? (int)$json['usage_limit'] : null,
                    'used_count' => 0,
                    'status' => isset($json['status']) ? strtoupper($json['status']) : 'ACTIVE',
                    'user_id' => null,
                    'fullname' => null,
                    'promo1' => null,
                    'is_primary' => 0
                ];

                // Check if promo code already exists
                $existing = $this->promoModel->promo_details($promo_data['promo_code']);
                if (isset($existing->id)) {
                    return json_error('Promo code already exists', 400);
                }

                // Save promo
                $result = $this->promoModel->save_promo($promo_data);
                
                if ($result === "exist") {
                    return json_error('Promo code already exists', 400);
                }
                
                if ($result && isset($result['id'])) {
                    return json_success(['id' => $result['id']], 'Promo code created successfully');
                } else {
                    return json_error('Failed to create promo code', 500);
                }
            } catch (\Exception $e) {
                log_message('error', 'Promo add error: ' . $e->getMessage());
                return json_error('Error creating promo: ' . $e->getMessage(), 500);
            }
        } else {
            return json_error('Method not allowed', 405);
        }
    }

    /**
     * Get promo details or update promo
     * GET /backend/promos/edit/{id} - Get promo details
     * POST /backend/promos/edit/{id} - Update promo
     */
    public function edit($id = null)
    {
        if ($id === null) {
            return json_error('Promo ID is required', 400);
        }

        $id = (int)$id;

        if ($this->request->getMethod() === 'get') {
            try {
                // Get promo by ID
                $promo = $this->promoModel->find($id);
                
                if (!$promo) {
                    return json_error('Promo not found', 404);
                }

                // Apply field mapping
                $promo_details = $this->promoModel->promo_details($promo['promo_code']);
                
                return json_success($promo_details);
            } catch (\Exception $e) {
                log_message('error', 'Promo get error: ' . $e->getMessage());
                return json_error('Error loading promo: ' . $e->getMessage(), 500);
            }
        } else {
            // Using CI4 recommended method check
            if ($this->request->is('post')) {
                try {
                    $json = $this->request->getJSON(true);
                
                // Check if promo exists
                $existing = $this->promoModel->find($id);
                if (!$existing) {
                    return json_error('Promo not found', 404);
                }

                // Prepare update data
                $update_data = [];
                
                if (isset($json['promo_code'])) {
                    // Check if new promo code conflicts with existing
                    $check = $this->promoModel->promo_details(trim($json['promo_code']));
                    if (isset($check->id) && $check->id != $id) {
                        return json_error('Promo code already exists', 400);
                    }
                    $update_data['promo_code'] = trim($json['promo_code']);
                }
                
                if (isset($json['discount_percent'])) {
                    $update_data['discount_percent'] = (float)$json['discount_percent'];
                }
                
                if (isset($json['discount_amount'])) {
                    $update_data['discount_amount'] = (float)$json['discount_amount'];
                }
                
                if (isset($json['min_order_amount'])) {
                    $update_data['min_order_amount'] = (float)$json['min_order_amount'];
                }
                
                if (isset($json['max_discount'])) {
                    $update_data['max_discount'] = $json['max_discount'] !== '' && $json['max_discount'] !== null ? (float)$json['max_discount'] : null;
                }
                
                if (isset($json['valid_from'])) {
                    $update_data['valid_from'] = !empty($json['valid_from']) ? $json['valid_from'] : null;
                }
                
                if (isset($json['valid_to'])) {
                    $update_data['valid_to'] = !empty($json['valid_to']) ? $json['valid_to'] : null;
                }
                
                if (isset($json['usage_limit'])) {
                    $update_data['usage_limit'] = $json['usage_limit'] !== '' && $json['usage_limit'] !== null ? (int)$json['usage_limit'] : null;
                }
                
                if (isset($json['status'])) {
                    $update_data['status'] = strtoupper($json['status']);
                }

                // Update promo
                $result = $this->promoModel->update_promo($update_data, $id);
                
                if ($result === "exist") {
                    return json_error('Promo code already exists', 400);
                }
                
                if ($result && isset($result['id'])) {
                    return json_success($result, 'Promo code updated successfully');
                } else {
                    return json_error('Failed to update promo code', 500);
                }
            } catch (\Exception $e) {
                log_message('error', 'Promo update error: ' . $e->getMessage());
                return json_error('Error updating promo: ' . $e->getMessage(), 500);
            }
        } else {
            return json_error('Method not allowed', 405);
        }
    }

    /**
     * Delete promo code (soft delete)
     * POST /backend/promos/delete/{id}
     */
    public function delete($id = null)
    {
        if ($id === null) {
            return json_error('Promo ID is required', 400);
        }

        // Using CI4 recommended method check
        if ($this->request->is('post')) {
            try {
                $id = (int)$id;
                
                // Check if promo exists
                $promo = $this->promoModel->find($id);
                if (!$promo) {
                    return json_error('Promo not found', 404);
                }

                // Soft delete by setting status to DELETED
                $this->promoModel->update($id, ['status' => 'DELETED']);
                
                return json_success(null, 'Promo code deleted successfully');
            } catch (\Exception $e) {
                log_message('error', 'Promo delete error: ' . $e->getMessage());
                return json_error('Error deleting promo: ' . $e->getMessage(), 500);
            }
        } else {
            return json_error('Method not allowed', 405);
        }
    }

    /**
     * Bulk delete promo codes
     * POST /backend/promos/bulk/delete
     * Body: { "promo_ids": [1, 2, 3] }
     */
    public function bulk_delete()
    {
        // Using CI4 recommended method check
        if ($this->request->is('post')) {
            try {
                $json = $this->request->getJSON(true);
                
                if (!isset($json['promo_ids']) || !is_array($json['promo_ids']) || empty($json['promo_ids'])) {
                    return json_error('promo_ids array is required', 400);
                }

                $promo_ids = array_map('intval', $json['promo_ids']);
                $success_count = 0;
                $failed_count = 0;

                foreach ($promo_ids as $promo_id) {
                    try {
                        $result = $this->promoModel->update($promo_id, ['status' => 'DELETED']);
                        if ($result) {
                            $success_count++;
                        } else {
                            $failed_count++;
                        }
                    } catch (\Exception $e) {
                        log_message('error', 'Bulk delete failed for promo ID ' . $promo_id . ': ' . $e->getMessage());
                        $failed_count++;
                    }
                }

                return json_success([
                    'success' => $success_count,
                    'failed' => $failed_count,
                    'total' => count($promo_ids)
                ], 'Bulk delete completed');
            } catch (\Exception $e) {
                log_message('error', 'Bulk delete error: ' . $e->getMessage());
                return json_error('Error in bulk delete: ' . $e->getMessage(), 500);
            }
        } else {
            return json_error('Method not allowed', 405);
        }
    }

    /**
     * Bulk update promo status
     * POST /backend/promos/bulk/status
     * Body: { "promo_ids": [1, 2, 3], "status": "ACTIVE" }
     */
    public function bulk_status()
    {
        // Using CI4 recommended method check
        if ($this->request->is('post')) {
            try {
                $json = $this->request->getJSON(true);
                
                if (!isset($json['promo_ids']) || !is_array($json['promo_ids']) || empty($json['promo_ids'])) {
                    return json_error('promo_ids array is required', 400);
                }

                if (!isset($json['status']) || !in_array(strtoupper($json['status']), ['ACTIVE', 'INACTIVE', 'DELETED'])) {
                    return json_error('Valid status is required (ACTIVE, INACTIVE, DELETED)', 400);
                }

                $promo_ids = array_map('intval', $json['promo_ids']);
                $status = strtoupper($json['status']);
                $success_count = 0;
                $failed_count = 0;

                foreach ($promo_ids as $promo_id) {
                    try {
                        $result = $this->promoModel->update($promo_id, ['status' => $status]);
                        if ($result) {
                            $success_count++;
                        } else {
                            $failed_count++;
                        }
                    } catch (\Exception $e) {
                        log_message('error', 'Bulk status update failed for promo ID ' . $promo_id . ': ' . $e->getMessage());
                        $failed_count++;
                    }
                }

                return json_success([
                    'success' => $success_count,
                    'failed' => $failed_count,
                    'total' => count($promo_ids)
                ], 'Bulk status update completed');
            } catch (\Exception $e) {
                log_message('error', 'Bulk status update error: ' . $e->getMessage());
                return json_error('Error in bulk status update: ' . $e->getMessage(), 500);
            }
        } else {
            return json_error('Method not allowed', 405);
        }
    }
}
