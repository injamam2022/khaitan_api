<?php

/**
 * Pricing Calculation Helper for CodeIgniter 4
 * 
 * Centralized utility for calculating product prices consistently across the application.
 */

if (!function_exists('calculate_sale_price')) {
    /**
     * Calculate sale price from MRP and discount
     * 
     * @param float $mrp Maximum Retail Price
     * @param float $discount Discount amount in INR (optional, defaults to 0)
     * @param float $discount_percent Discount percentage (optional, defaults to 0)
     * @return float Sale price after discount
     */
    function calculate_sale_price($mrp, $discount = 0, $discount_percent = 0)
    {
        $mrp = floatval($mrp);
        $discount = floatval($discount);
        $discount_percent = floatval($discount_percent);
        
        // If discount amount is provided, use it
        if ($discount > 0) {
            return max(0, $mrp - $discount);
        }
        
        // If discount percentage is provided, calculate from percentage
        if ($discount_percent > 0) {
            return max(0, $mrp - ($mrp * $discount_percent / 100));
        }
        
        // No discount, return MRP
        return max(0, $mrp);
    }
}

if (!function_exists('calculate_final_price')) {
    /**
     * Calculate final price including GST
     * 
     * @param float $sale_price Sale price (after discount)
     * @param float $gst_rate GST rate as percentage (e.g., 18 for 18%)
     * @return float Final price including GST
     */
    function calculate_final_price($sale_price, $gst_rate = 0)
    {
        $sale_price = floatval($sale_price);
        $gst_rate = floatval($gst_rate);
        
        if ($gst_rate > 0) {
            return max(0, $sale_price + ($sale_price * $gst_rate / 100));
        }
        
        return max(0, $sale_price);
    }
}

if (!function_exists('calculate_all_prices')) {
    /**
     * Calculate all pricing components in one call
     * 
     * @param float $mrp Maximum Retail Price
     * @param float $discount Discount amount in INR (optional)
     * @param float $discount_percent Discount percentage (optional)
     * @param float $gst_rate GST rate as percentage (optional)
     * @return array Array containing: mrp, discount, discount_percent, sale_price, final_price, gst_rate
     */
    function calculate_all_prices($mrp, $discount = 0, $discount_percent = 0, $gst_rate = 0)
    {
        $mrp = floatval($mrp);
        $discount = floatval($discount);
        $discount_percent = floatval($discount_percent);
        $gst_rate = floatval($gst_rate);
        
        $sale_price = calculate_sale_price($mrp, $discount, $discount_percent);
        $final_price = calculate_final_price($sale_price, $gst_rate);
        
        return [
            'mrp' => $mrp,
            'discount' => $discount,
            'discount_off_inpercent' => $discount_percent,
            'sale_price' => round($sale_price, 2),
            'final_price' => round($final_price, 2),
            'gst_rate' => $gst_rate
        ];
    }
}

if (!function_exists('calculate_discount_percent')) {
    /**
     * Calculate discount percentage from MRP and discount amount
     * 
     * @param float $mrp Maximum Retail Price
     * @param float $discount Discount amount in INR
     * @return float Discount percentage
     */
    function calculate_discount_percent($mrp, $discount)
    {
        $mrp = floatval($mrp);
        $discount = floatval($discount);
        
        if ($mrp <= 0) {
            return 0;
        }
        
        return round(($discount / $mrp) * 100, 2);
    }
}

if (!function_exists('calculate_discount_amount')) {
    /**
     * Calculate discount amount from MRP and discount percentage
     * 
     * @param float $mrp Maximum Retail Price
     * @param float $discount_percent Discount percentage
     * @return float Discount amount in INR
     */
    function calculate_discount_amount($mrp, $discount_percent)
    {
        $mrp = floatval($mrp);
        $discount_percent = floatval($discount_percent);
        
        return round($mrp * ($discount_percent / 100), 2);
    }
}
