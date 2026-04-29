<?php

namespace App\Controllers;

use App\Models\EasyEcomSkuMappingModel;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;
use App\Libraries\EasyEcomClient;

/**
 * CRUD for EasyEcom SKU mapping (internal_sku <-> easyecom_sku).
 * Auth required for all actions.
 */
class EasyEcomSkuMapping extends BaseController
{
    use ResponseTrait;

    protected EasyEcomSkuMappingModel $model;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        $this->model = new EasyEcomSkuMappingModel();
        helper(['api_helper']);
    }

    /**
     * GET easyecom/product-catalog — fetch Product Master from EasyEcom.
     */
    public function productCatalog()
    {
        if (!check_auth()) {
            return $this->failUnauthorized('Unauthorized. Please login.');
        }
        try {
            $client = service('easyecom');
            if (!$client instanceof EasyEcomClient || !$client->isConfigured()) {
                return json_error('EasyEcom not configured. Set EASYECOM_* in .env.', 503);
            }
            $params = $this->request->getGet();
            if (is_array($params)) {
                $params = array_filter($params, static function ($v) {
                    return $v !== null && $v !== '';
                });
            } else {
                $params = [];
            }
            log_message('info', 'EasyEcom: [PRODUCT_CATALOG] fetching params=' . json_encode($params));
            $response = $client->getProductMaster($params);
            if (isset($response['_status']) && $response['_status'] >= 400) {
                log_message('error', 'EasyEcom: [PRODUCT_CATALOG] API error status=' . ($response['_status'] ?? ''));
                return $this->respond($response, (int) $response['_status']);
            }
            return json_success($response, 'Product catalog from EasyEcom');
        } catch (\Throwable $e) {
            log_message('error', 'EasyEcom: [PRODUCT_CATALOG] exception: ' . $e->getMessage());
            return json_error('Failed to fetch product catalog: ' . $e->getMessage(), 502);
        }
    }

    /**
     * GET easyecom/sku-mapping — list all mappings.
     */
    public function index()
    {
        if (!check_auth()) {
            return $this->failUnauthorized('Unauthorized. Please login.');
        }
        $list = $this->model->orderBy('internal_sku', 'asc')->findAll();
        return json_success($list);
    }

    /**
     * POST easyecom/sku-mapping — add a mapping.
     * Body: { "internal_sku": "...", "easyecom_sku": "..." }
     */
    public function add()
    {
        if (!check_auth()) {
            return $this->failUnauthorized('Unauthorized. Please login.');
        }
        $json = $this->request->getJSON(true) ?: [];
        $internal  = trim((string) ($json['internal_sku'] ?? $this->request->getPost('internal_sku') ?? ''));
        $easyecom  = trim((string) ($json['easyecom_sku'] ?? $this->request->getPost('easyecom_sku') ?? ''));
        if ($internal === '' || $easyecom === '') {
            return json_error('internal_sku and easyecom_sku are required', 400);
        }
        if ($this->model->where('internal_sku', $internal)->first()) {
            return json_error('internal_sku already mapped', 409);
        }
        if ($this->model->where('easyecom_sku', $easyecom)->first()) {
            return json_error('easyecom_sku already mapped', 409);
        }
        $id = $this->model->insert(['internal_sku' => $internal, 'easyecom_sku' => $easyecom]);
        if (!$id) {
            log_message('error', 'EasyEcom: [SKU_MAPPING] insert failed internal=' . $internal . ' easyecom=' . $easyecom);
            return json_error('Failed to add mapping', 500);
        }
        $row = $this->model->find($id);
        log_message('info', 'EasyEcom: [SKU_MAPPING] added id=' . $id . ' internal=' . $internal . ' easyecom=' . $easyecom);
        return json_success($row, 'Mapping added');
    }

    /**
     * DELETE easyecom/sku-mapping/(:num) — delete by id.
     */
    public function delete($id)
    {
        if (!check_auth()) {
            return $this->failUnauthorized('Unauthorized. Please login.');
        }
        $id = (int) $id;
        if ($id <= 0) {
            return json_error('Invalid id', 400);
        }
        if (!$this->model->delete($id)) {
            return json_error('Mapping not found or delete failed', 404);
        }
        log_message('info', 'EasyEcom: [SKU_MAPPING] deleted id=' . $id);
        return json_success(null, 'Mapping deleted');
    }

    /**
     * GET easyecom/test-auth — temporary debug route to isolate token fetch.
     * Steps: wait 6+ min (cooldown reset), call once, then check: SELECT * FROM easyecom_tokens.
     * Expected: exactly 1 row, no 429. Second call should log "Using cached token" and not call /access/token.
     * Remove or restrict in production.
     */
    public function testEasyEcomAuth()
    {
        if (!check_auth()) {
            return $this->failUnauthorized('Unauthorized. Please login.');
        }
        try {
            $client = service('easyecom');
            if (!$client instanceof EasyEcomClient || !$client->isConfigured()) {
                return $this->response->setJSON(['success' => false, 'message' => 'EasyEcom not configured']);
            }
            $token = $client->getValidAccessToken();
            return $this->response->setJSON(['success' => true, 'has_token' => !empty($token)]);
        } catch (\Throwable $e) {
            log_message('error', 'EasyEcom: [TEST_AUTH] ' . $e->getMessage());
            return $this->response->setJSON(['success' => false, 'message' => $e->getMessage()])->setStatusCode(502);
        }
    }
}
