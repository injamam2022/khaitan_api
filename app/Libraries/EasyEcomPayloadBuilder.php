<?php

namespace App\Libraries;

/**
 * Centralized payload builder for EasyEcom API requests.
 * Separates EasyEcom API contract mapping from business logic.
 *
 * Used by:
 * - Products controller (product/variation sync on create/edit)
 * - EasyecomBackfill command (legacy product migration)
 */
class EasyEcomPayloadBuilder
{
    /**
     * Build CreateMasterProduct payload from normalized product data.
     *
     * @param array $product Product row with keys: sku_number, product_name, brand_name, category_name,
     *                       hsn_code, unit_name, model, gst_rate, mrp, sale_price, weight, dimensions,
     *                       product_code, unit_id, final_price, product_price
     * @param string $description Product description text
     * @param string $imageUrl    Primary product image URL
     * @return array EasyEcom CreateMasterProduct payload
     */
    public static function buildCreateProductPayload(array $product, string $description = '', string $imageUrl = ''): array
    {
        $sku       = trim((string) ($product['sku_number'] ?? ''));
        $brand     = trim((string) ($product['brand_name'] ?? ''));
        $category  = trim((string) ($product['category_name'] ?? ''));
        $hsnCode   = trim((string) ($product['hsn_code'] ?? ''));
        $unitName  = trim((string) ($product['unit_name'] ?? ''));
        $model     = trim((string) ($product['model'] ?? ''));
        $gstRate   = (float) ($product['gst_rate'] ?? 0);
        $productName = trim((string) ($product['product_name'] ?? ''));

        $finalPrice = (float)($product['final_price'] ?? 0);
        if ($finalPrice > 0) {
            $mrp = $finalPrice;
            $salePrice = $finalPrice;
        } else {
            $mrp = (float)($product['mrp'] ?? 0);
            $salePrice = (float)($product['sale_price'] ?? $mrp);
        }

        $weight = (float) ($product['weight'] ?? 0);

        [$dimLength, $dimWidth, $dimHeight] = self::parseDimensions($product['dimensions'] ?? '');

        return [
            'AccountingSKU'  => $sku,
            'Sku'            => $sku,
            'ProductName'    => $productName,
            'Brand'          => $brand,
            'Category'       => $category,
            'Mrp'            => (string) number_format($mrp, 2, '.', ''),
            'SellingPrice'   => (string) number_format($salePrice, 2, '.', ''),
            'Cost'           => (string) number_format($salePrice, 2, '.', ''),
            'Weight'         => (string) (($weight > 0) ? $weight : '0'),
            'Description'    => $description,
            'AccountingUnit' => $unitName !== '' ? $unitName : (isset($product['unit_id']) ? (string) $product['unit_id'] : ''),
            'ModelName'      => $productName ?: $model,
            'ModelNumber'    => $model !== '' ? $model : ($product['product_code'] ?? $sku),
            'ProductTaxCode' => $hsnCode,
            'TaxRuleName'    => $gstRate > 0 ? (string) $gstRate : '',
            'ImageURL'       => $imageUrl,
            'Height'         => $dimHeight !== '' ? $dimHeight : '0',
            'Length'         => $dimLength !== '' ? $dimLength : '0',
            'Width'          => $dimWidth !== '' ? $dimWidth : '0',
            'Color'          => '',
            'Size'           => '',
            'EANUPC'         => '',
            'itemType'       => '0',
            'materialType'   => 1,
            'customFields'   => (object) [],
        ];
    }

    /**
     * Build CreateMasterProduct payload for a variation (child product).
     *
     * @param array  $product   Parent product row
     * @param array  $variation Variation row with keys: sku, variation_name, price
     * @return array EasyEcom CreateMasterProduct payload
     */
    public static function buildCreateVariationPayload(array $product, array $variation, string $imageUrl = ''): array
    {
        $sku = trim((string) ($variation['sku'] ?? ''));
        $productName  = trim((string) ($product['product_name'] ?? ''));
        $variationName = trim((string) ($variation['variation_name'] ?? ''));
        $name = $variationName !== '' ? $productName . ' - ' . $variationName : $productName;
        
        $priceVal = (float)($variation['final_price'] ?? $variation['price'] ?? 0);
        $weight = (float) ($product['weight'] ?? 0);
        $gstRate = (float) ($product['gst_rate'] ?? 0);
        $unitName = trim((string) ($product['unit_name'] ?? ''));

        [$dimLength, $dimWidth, $dimHeight] = self::parseDimensions($product['dimensions'] ?? '');

        return [
            'AccountingSKU'  => $sku,
            'Sku'            => $sku,
            'ProductName'    => $name,
            'Brand'          => trim((string) ($product['brand_name'] ?? '')),
            'Category'       => trim((string) ($product['category_name'] ?? '')),
            'Mrp'            => (string) number_format($priceVal, 2, '.', ''),
            'SellingPrice'   => (string) number_format($priceVal, 2, '.', ''),
            'Cost'           => (string) number_format($priceVal, 2, '.', ''),
            'Weight'         => (string) (($weight > 0) ? $weight : '0'),
            'Length'         => $dimLength !== '' ? $dimLength : '0',
            'Width'          => $dimWidth !== '' ? $dimWidth : '0',
            'Height'         => $dimHeight !== '' ? $dimHeight : '0',
            'ImageURL'       => $imageUrl,
            'AccountingUnit' => $unitName !== '' ? $unitName : (isset($product['unit_id']) ? (string) $product['unit_id'] : ''),
            'Description'    => '',
            'ModelName'      => $name,
            'ModelNumber'    => trim((string) ($product['model'] ?? '')) ?: $sku,
            'ProductTaxCode' => trim((string) ($product['hsn_code'] ?? '')),
            'TaxRuleName'    => $gstRate > 0 ? (string) $gstRate : '',
            'itemType'       => '0',
            'materialType'   => 1,
            'customFields'   => (object) [],
        ];
    }

    /**
     * Build UpdateMasterProduct payload for syncing name/details.
     *
     * @param string $easyecomProductId EasyEcom product ID (RefProductCode)
     * @param array  $product           Product row
     * @return array EasyEcom UpdateMasterProduct payload
     */
    public static function buildUpdateProductPayload(string $eeProductId, array $product): array
    {
        $productName = trim((string) ($product['product_name'] ?? ''));
        $sku = trim((string) ($product['sku_number'] ?? ''));
        $finalPrice = (float)($product['final_price'] ?? 0);
        if ($finalPrice > 0) {
            $mrp = $finalPrice;
            $salePrice = $finalPrice;
        } else {
            $mrp = (float)($product['mrp'] ?? 0);
            $salePrice = (float)($product['sale_price'] ?? $mrp);
        }

        $weight = (float) ($product['weight'] ?? 0);
        $model = trim((string) ($product['model'] ?? ''));
        $hsnCode = trim((string) ($product['hsn_code'] ?? ''));
        $gstRate = (float) ($product['gst_rate'] ?? 0);
        $unitName = trim((string) ($product['unit_name'] ?? ''));
        $brand = trim((string) ($product['brand_name'] ?? ''));
        $category = trim((string) ($product['category_name'] ?? ''));
        
        [$dimLength, $dimWidth, $dimHeight] = self::parseDimensions($product['dimensions'] ?? '');

        return [
            'productId'      => $eeProductId,
            'product_name'   => $productName,
            'sku'            => $sku,
            'Mrp'            => (string) number_format($mrp, 2, '.', ''),
            'SellingPrice'   => (string) number_format($salePrice, 2, '.', ''),
            'Cost'           => (string) number_format($salePrice, 2, '.', ''),
            'Weight'         => (string) (($weight > 0) ? $weight : '0'),
            'ModelName'      => $productName ?: $model,
            'ModelNumber'    => $model !== '' ? $model : ($product['product_code'] ?? $sku),
            'Brand'          => $brand,
            'Category'       => $category,
            'ProductTaxCode' => $hsnCode,
            'TaxRuleName'    => $gstRate > 0 ? (string) $gstRate : '',
            'AccountingUnit' => $unitName !== '' ? $unitName : (isset($product['unit_id']) ? (string) $product['unit_id'] : ''),
            'Height'         => $dimHeight !== '' ? $dimHeight : '0',
            'Length'         => $dimLength !== '' ? $dimLength : '0',
            'Width'          => $dimWidth !== '' ? $dimWidth : '0',
        ];
    }

    /**
     * Build UpdateMasterProduct payload for a variation.
     */
    public static function buildUpdateVariationPayload(string $eeProductId, array $product, array $variation, string $imageUrl = ''): array
    {
        $productName  = trim((string) ($product['product_name'] ?? ''));
        $variationName = trim((string) ($variation['variation_name'] ?? ''));
        $name = $variationName !== '' ? $productName . ' - ' . $variationName : $productName;
        
        $priceVal = (float)($variation['final_price'] ?? $variation['price'] ?? 0);
        $weight = (float) ($product['weight'] ?? 0);
        $sku = trim((string) ($variation['sku'] ?? ''));
        $hsnCode = trim((string) ($product['hsn_code'] ?? ''));
        $gstRate = (float) ($product['gst_rate'] ?? 0);
        $unitName = trim((string) ($product['unit_name'] ?? ''));
        $brand = trim((string) ($product['brand_name'] ?? ''));
        $category = trim((string) ($product['category_name'] ?? ''));

        [$dimLength, $dimWidth, $dimHeight] = self::parseDimensions($product['dimensions'] ?? '');

        return [
            'productId'      => $eeProductId,
            'product_name'   => $name,
            'sku'            => $sku,
            'Mrp'            => (string) number_format($priceVal, 2, '.', ''),
            'SellingPrice'   => (string) number_format($priceVal, 2, '.', ''),
            'Cost'           => (string) number_format($priceVal, 2, '.', ''),
            'Weight'         => (string) (($weight > 0) ? $weight : '0'),
            'ImageURL'       => $imageUrl,
            'ModelName'      => $name,
            'ModelNumber'    => trim((string) ($product['model'] ?? '')) ?: $sku,
            'Brand'          => $brand,
            'Category'       => $category,
            'ProductTaxCode' => $hsnCode,
            'TaxRuleName'    => $gstRate > 0 ? (string) $gstRate : '',
            'AccountingUnit' => $unitName !== '' ? $unitName : (isset($product['unit_id']) ? (string) $product['unit_id'] : ''),
            'Height'         => $dimHeight !== '' ? $dimHeight : '0',
            'Length'         => $dimLength !== '' ? $dimLength : '0',
            'Width'          => $dimWidth !== '' ? $dimWidth : '0',
        ];
    }

    /**
     * Parse dimension string (LxWxH) into [length, width, height].
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private static function parseDimensions(mixed $raw): array
    {
        if (is_string($raw) && $raw !== '') {
            if (preg_match('/^(\d+(?:\.\d+)?)\s*[x×,]\s*(\d+(?:\.\d+)?)\s*[x×,]\s*(\d+(?:\.\d+)?)$/i', trim($raw), $m)) {
                return [$m[1], $m[2], $m[3]];
            }
        }
        return ['', '', ''];
    }
}
