<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->GET('/', 'Home::index');

// Login Routes
$routes->match(['GET', 'POST', 'OPTIONS'], 'login', 'Login::index');
$routes->match(['POST', 'OPTIONS'], 'logout', 'Login::Logout');

// API Routes
$routes->group('api', ['namespace' => 'App\Controllers'], function($routes) {
    $routes->GET('me', 'Login::index');
    $routes->match(['POST', 'OPTIONS'], 'logout', 'Login::Logout');
    // Storefront compatibility endpoints (v2)
    $routes->match(['GET', 'OPTIONS'], 'home/banners/v2', 'Banners::bannersV2');
    $routes->match(['GET', 'OPTIONS'], 'products/slug/(:segment)', 'Products::slug/$1');
    $routes->match(['POST', 'OPTIONS'], 'products/lists/v2', 'Products::listsV2');
    $routes->match(['POST', 'OPTIONS'], 'products/filter/v2', 'Products::filterV2');
});

// Dashboard Routes
$routes->GET('dashboard', 'Dashboard::index');
$routes->GET('dashboard/revenue', 'Dashboard::revenue');

// Product Reviews Routes
$routes->GET('productreviews/reviews', 'Productreviews::reviews');
$routes->match(['GET', 'POST'], 'productreviews/add', 'Productreviews::add');
$routes->match(['GET', 'POST'], 'productreviews/edit/(:num)', 'Productreviews::edit/$1');
$routes->POST('productreviews/removed/(:num)', 'Productreviews::removed/$1');

// Product Description Routes
$routes->GET('product-descriptions/(:num)', 'ProductDescriptions::index/$1');
$routes->POST('product-descriptions/(:num)/save', 'ProductDescriptions::save/$1');
$routes->POST('product-descriptions/delete/(:num)', 'ProductDescriptions::delete/$1');
$routes->POST('product-descriptions/bulk/(:num)', 'ProductDescriptions::bulk/$1');

// Product Description Images Routes
$routes->GET('product-description-images/(:num)', 'ProductDescriptionImages::index/$1');
$routes->POST('product-description-images/(:num)', 'ProductDescriptionImages::index/$1');
$routes->POST('product-description-images/delete/(:num)', 'ProductDescriptionImages::delete/$1');
$routes->POST('product-description-images/update/(:num)', 'ProductDescriptionImages::update/$1');

// Bulk Product Operations Routes
$routes->match(['POST', 'OPTIONS'], 'products/bulk/delete', 'Products::bulk_delete');
$routes->match(['POST', 'OPTIONS'], 'products/bulk/status', 'Products::bulk_status');
$routes->match(['POST', 'OPTIONS'], 'products/bulk/restore', 'Products::bulk_restore');
$routes->match(['POST', 'OPTIONS'], 'products/bulk/update', 'Products::bulk_update');

// Promo Management Routes
$routes->GET('promos', 'Promo::index');
$routes->POST('promos/add', 'Promo::add');
$routes->match(['GET', 'POST'], 'promos/edit/(:num)', 'Promo::edit/$1');
$routes->POST('promos/delete/(:num)', 'Promo::delete/$1');
$routes->POST('promos/bulk/delete', 'Promo::bulk_delete');
$routes->POST('promos/bulk/status', 'Promo::bulk_status');

// Products Routes (Main CRUD)
$routes->GET('products', 'Products::index');
$routes->GET('products/(:num)', 'Products::index/$1');
$routes->match(['GET', 'POST', 'OPTIONS'], 'products/add', 'Products::add');
$routes->match(['GET', 'POST', 'OPTIONS'], 'products/edit/(:num)', 'Products::edit/$1');
$routes->match(['POST', 'OPTIONS'], 'products/deletepro/(:num)', 'Products::deletepro/$1');
$routes->match(['POST', 'OPTIONS'], 'products/updatestock/(:num)', 'Products::updatestock/$1');
$routes->GET('products/count', 'Products::count');
$routes->GET('products/statistics', 'Products::statistics');
$routes->GET('products/prices/(:num)', 'Products::prices/$1');

// Products - Categories Routes
$routes->GET('products/cat', 'Products::cat');
$routes->GET('products/cat/toplevel', 'Products::catTopLevel');
$routes->GET('products/subcat/(:num)', 'Products::subcat/$1');
$routes->match(['POST', 'OPTIONS'], 'products/cat/add', 'Products::catadd');
$routes->match(['GET', 'POST', 'OPTIONS'], 'products/cat/edit/(:num)', 'Products::catedit/$1');
$routes->match(['GET', 'POST', 'OPTIONS'], 'products/catedit/(:num)', 'Products::catedit/$1'); // alias for frontend using catedit

// Products - Brands Routes
$routes->GET('products/brand', 'Products::brand');
$routes->match(['POST', 'OPTIONS'], 'products/brand/add', 'Products::brandadd');
$routes->match(['GET', 'POST', 'OPTIONS'], 'products/brand/edit/(:num)', 'Products::brandedit/$1');

// Products - Units Routes
$routes->GET('products/unit', 'Products::unit');

// Products - Variations Routes (dashboard uses variationadd/:id, variationedit/:id, etc.)
$routes->GET('products/variations/(:num)', 'Products::variations/$1');
$routes->match(['POST', 'OPTIONS'], 'products/variationadd/(:num)', 'Products::variationadd');
$routes->match(['GET', 'POST', 'OPTIONS'], 'products/variationedit/(:num)', 'Products::variationedit');
$routes->match(['POST', 'OPTIONS'], 'products/variationdelete/(:num)', 'Products::variationdelete');
$routes->match(['POST', 'OPTIONS'], 'products/variationstock/(:num)', 'Products::variationstock');
$routes->match(['POST', 'OPTIONS'], 'products/variationpricing/(:num)', 'Products::variationpricing');
// Alternate variation URLs (plural)
$routes->match(['POST', 'OPTIONS'], 'products/variations/add', 'Products::variationadd');
$routes->match(['GET', 'POST', 'OPTIONS'], 'products/variations/edit/(:num)', 'Products::variationedit/$1');
$routes->match(['POST', 'OPTIONS'], 'products/variations/delete/(:num)', 'Products::variationdelete/$1');
$routes->match(['POST', 'OPTIONS'], 'products/variations/stock/(:num)', 'Products::variationstock/$1');
$routes->match(['POST', 'OPTIONS'], 'products/variations/pricing/(:num)', 'Products::variationpricing/$1');

// Products - Analytics Routes
$routes->GET('products/analytics', 'Products::analytics');
$routes->GET('products/analytics/sales', 'Products::getSalesAnalytics');
$routes->GET('products/analytics/stock', 'Products::getStockAnalytics');
$routes->GET('products/analytics/products', 'Products::getProductAnalytics');

// Product Images Routes
$routes->GET('product-images/(:num)', 'ProductImages::index/$1');
$routes->POST('product-images/reorder/(:num)', 'ProductImages::reorder/$1');
$routes->POST('product-images/order/(:num)', 'ProductImages::order/$1');

// Variation Images Routes
$routes->GET('variation-images/(:num)', 'VariationImages::index/$1');
$routes->POST('variation-images/(:num)', 'VariationImages::upload/$1');
$routes->POST('variation-images/delete/(:num)', 'VariationImages::delete/$1');

// Order Routes
$routes->GET('orders', 'Order::index');
$routes->GET('orders/lists', 'Order::lists');
$routes->GET('orders/details', 'Order::orderDetails');
$routes->match(['GET', 'POST'], 'orders/edit', 'Order::editOrder');
$routes->POST('orders/push-easyecom', 'Order::pushToEasyEcom');
$routes->POST('orders/cancel-easyecom', 'Order::cancelOnEasyEcom');

// Profile Routes
$routes->match(['GET', 'POST'], 'profile/changepass', 'Profile::changepass');

// User Routes
$routes->GET('users', 'User::index');
$routes->POST('users/create', 'User::create');

// Home Products Routes
$routes->GET('homeproducts/cats', 'Homeproducts::cats');
$routes->match(['GET', 'POST'], 'homeproducts/add', 'Homeproducts::add');
$routes->match(['GET', 'POST'], 'homeproducts/edit/(:num)', 'Homeproducts::edit/$1');
$routes->POST('homeproducts/removed/(:num)', 'Homeproducts::removed/$1');
$routes->GET('homeproducts/products/(:num)', 'Homeproducts::products/$1');
$routes->POST('homeproducts/addproducts/(:num)', 'Homeproducts::addproducts/$1');
$routes->POST('homeproducts/removeproducts/(:num)/(:num)', 'Homeproducts::removeproducts/$1/$2');

// Home Sliders Routes
$routes->GET('homesliders/sliders', 'Homesliders::sliders');
$routes->match(['GET', 'POST'], 'homesliders/add', 'Homesliders::add');
$routes->match(['GET', 'POST'], 'homesliders/edit/(:num)', 'Homesliders::edit/$1');
$routes->POST('homesliders/removed/(:num)', 'Homesliders::removed/$1');

// Home Banners Routes
$routes->GET('banners/banners', 'Banners::banners');
$routes->match(['GET', 'POST'], 'banners/add', 'Banners::add');
$routes->match(['GET', 'POST'], 'banners/edit/(:num)', 'Banners::edit/$1');
$routes->POST('banners/removed/(:num)', 'Banners::removed/$1');

// Pages Routes
$routes->match(['GET', 'POST'], 'pages/edit/(:num)', 'Pages::edit/$1');

// Delivery Routes (Phase 2 — Delhivery via EasyEcom; all require session auth)
$routes->GET('delivery/order', 'Delivery::order');
$routes->GET('delivery/orders', 'Delivery::orders');
$routes->GET('delivery/orders/shipped', 'Delivery::shipped');
$routes->GET('delivery/orders/pending-shipment', 'Delivery::pendingShipment');
$routes->GET('delivery/carrier/list', 'Delivery::carrierList');
$routes->GET('delivery/document', 'Delivery::document');
$routes->POST('delivery/sync-status', 'Delivery::syncStatus');

// EasyEcom Product Catalog (Point 4a) & SKU mapping (Point 4b) — auth required
$routes->GET('easyecom/product-catalog', 'EasyEcomSkuMapping::productCatalog');
$routes->GET('easyecom/test-auth', 'EasyEcomSkuMapping::testEasyEcomAuth'); // Debug: isolate token fetch; remove in prod
$routes->GET('easyecom/sku-mapping', 'EasyEcomSkuMapping::index');
$routes->POST('easyecom/sku-mapping', 'EasyEcomSkuMapping::add');
$routes->DELETE('easyecom/sku-mapping/(:num)', 'EasyEcomSkuMapping::delete/$1');

// EasyEcom Webhooks (incoming from EasyEcom; no session auth)
$routes->POST('webhooks/easyecom/inventory', 'EasyEcomWebhooks::inventory');
$routes->POST('webhooks/easyecom/confirm-order', 'EasyEcomWebhooks::confirmOrder');
$routes->POST('webhooks/easyecom/manifested', 'EasyEcomWebhooks::manifested');
$routes->POST('webhooks/easyecom/tracking', 'EasyEcomWebhooks::tracking');

// Unified EasyEcom webhook (event-type routing via x-easyecom-webhook-action header)
$routes->POST('webhooks/easyecom', 'Webhooks::easyEcomHandler');

// Cron Routes (secret-protected, no session auth)
$routes->GET('cron/inventory-sync', 'Cron\InventorySync::index');
$routes->GET('cron/stock-sync-push', 'Cron\StockSyncPush::index');
$routes->GET('cron/order-status-sync', 'Cron\OrderStatusSync::index');
$routes->GET('cron/easyecom-retry', 'Cron\EasyEcomRetry::index');

// Health Check Routes (Production Safe)
$routes->GET('health', 'Health::index');

// Simple Test Route (Security Check - Verify Backend is Running)
$routes->GET('test', 'Test::index');

// Debug/Test Routes - REMOVED FOR PRODUCTION SECURITY
// These endpoints expose sensitive information and should not be accessible in production
// Uncomment only for development/debugging purposes:
// $routes->get('testdb', 'TestDb::index');
// $routes->get('diagnostic', 'Diagnostic::index');
// $routes->match(['get', 'post'], 'debugproduct/test', 'DebugProduct::test');
// $routes->get('session-diagnostic', 'SessionDiagnostic::index');
// $routes->get('session-status', 'SessionDiagnostic::status');
// $routes->get('session-test', 'SessionTest::index');
// $routes->get('check-ci-sessions', 'CheckCiSessionsTable::index');
// $routes->post('check-ci-sessions/create', 'CheckCiSessionsTable::create');
// $routes->post('check-ci-sessions/fix', 'CheckCiSessionsTable::fix');

// Setup Routes - REMOVED FOR PRODUCTION SECURITY
// Setup should only be run once during initial installation
// Uncomment only for initial setup:
// $routes->match(['get', 'post'], 'setup', 'Setup::index');
// $routes->post('setup/testlogin', 'Setup::testlogin');

// API Documentation Routes
$routes->GET('api-docs', 'ApiDocs::index');
$routes->GET('api-docs/swagger.json', 'ApiDocs::json');