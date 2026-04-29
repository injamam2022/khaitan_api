<?php

/**
 * Stock Management Helper for CodeIgniter 4
 * 
 * Centralized utility for managing product stock consistently across the application.
 */

if (!function_exists('calculate_in_stock_status')) {
    /**
     * Calculate in_stock status from stock quantity
     * 
     * @param int $stock_quantity Stock quantity
     * @return string 'YES' if stock > 0, 'NO' otherwise
     */
    function calculate_in_stock_status($stock_quantity)
    {
        $stock_quantity = intval($stock_quantity);
        return $stock_quantity > 0 ? 'YES' : 'NO';
    }
}

if (!function_exists('prepare_stock_data')) {
    /**
     * Prepare stock data array for database update
     * Ensures stock_quantity and in_stock are always synchronized
     * 
     * @param int $stock_quantity Stock quantity
     * @return array Array with 'stock_quantity' and 'in_stock' keys
     */
    function prepare_stock_data($stock_quantity)
    {
        $stock_quantity = intval($stock_quantity);
        // Ensure stock quantity is not negative
        $stock_quantity = max(0, $stock_quantity);
        
        return [
            'stock_quantity' => $stock_quantity,
            'in_stock' => calculate_in_stock_status($stock_quantity)
        ];
    }
}

if (!function_exists('validate_stock_quantity')) {
    /**
     * Validate stock quantity
     * 
     * @param mixed $stock_quantity Stock quantity to validate
     * @param int $min Minimum allowed value (default: 0)
     * @return array ['valid' => bool, 'message' => string, 'value' => int|null]
     */
    function validate_stock_quantity($stock_quantity, $min = 0)
    {
        if (!is_numeric($stock_quantity)) {
            return [
                'valid' => false,
                'message' => 'Stock quantity must be a valid number',
                'value' => null
            ];
        }
        
        $stock_value = intval($stock_quantity);
        
        if ($stock_value < $min) {
            return [
                'valid' => false,
                'message' => "Stock quantity cannot be less than {$min}",
                'value' => null
            ];
        }
        
        return [
            'valid' => true,
            'message' => '',
            'value' => $stock_value
        ];
    }
}

if (!function_exists('is_in_stock')) {
    /**
     * Check if product is in stock
     * 
     * @param int $stock_quantity Stock quantity
     * @return bool True if in stock, false otherwise
     */
    function is_in_stock($stock_quantity)
    {
        return intval($stock_quantity) > 0;
    }
}

if (!function_exists('decrement_stock')) {
    /**
     * Decrement stock quantity (for order processing)
     * Returns updated stock data
     * 
     * @param int $current_stock Current stock quantity
     * @param int $quantity_to_decrement Quantity to subtract
     * @return array|false Array with 'stock_quantity' and 'in_stock' keys, or false on error
     */
    function decrement_stock($current_stock, $quantity_to_decrement)
    {
        $current_stock = intval($current_stock);
        $quantity_to_decrement = intval($quantity_to_decrement);
        
        if ($quantity_to_decrement <= 0) {
            return false; // Invalid decrement amount
        }
        
        if ($current_stock < $quantity_to_decrement) {
            return false; // Insufficient stock
        }
        
        $new_stock = $current_stock - $quantity_to_decrement;
        return prepare_stock_data($new_stock);
    }
}

if (!function_exists('increment_stock')) {
    /**
     * Increment stock quantity (for restocking)
     * Returns updated stock data
     * 
     * @param int $current_stock Current stock quantity
     * @param int $quantity_to_add Quantity to add
     * @return array|false Array with 'stock_quantity' and 'in_stock' keys, or false on error
     */
    function increment_stock($current_stock, $quantity_to_add)
    {
        $current_stock = intval($current_stock);
        $quantity_to_add = intval($quantity_to_add);
        
        if ($quantity_to_add <= 0) {
            return false; // Invalid increment amount
        }
        
        $new_stock = $current_stock + $quantity_to_add;
        return prepare_stock_data($new_stock);
    }
}
