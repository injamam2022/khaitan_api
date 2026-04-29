<?php

/**
 * Input Validation Helper for CodeIgniter 4
 * 
 * Centralized validation functions for backend controllers.
 * Provides consistent validation across all backend endpoints.
 */

if (!function_exists('validate_required_fields')) {
    /**
     * Validate required fields in request data
     * 
     * @param array $data Request data array
     * @param array $required_fields Array of required field names
     * @return array ['valid' => bool, 'message' => string, 'field' => string|null]
     */
    function validate_required_fields($data, $required_fields)
    {
        if (!is_array($data)) {
            return [
                'valid' => false,
                'message' => 'Invalid request data format',
                'field' => null
            ];
        }
        
        foreach ($required_fields as $field) {
            if (!isset($data[$field]) || (is_string($data[$field]) && trim($data[$field]) === '')) {
                $field_display = str_replace('_', ' ', $field);
                return [
                    'valid' => false,
                    'message' => ucfirst($field_display) . ' is required',
                    'field' => $field
                ];
            }
        }
        
        return ['valid' => true, 'message' => '', 'field' => null];
    }
}

if (!function_exists('validate_integer')) {
    /**
     * Validate integer field
     * 
     * @param mixed $value Value to validate
     * @param int $min Minimum value (optional)
     * @param int $max Maximum value (optional)
     * @return array ['valid' => bool, 'message' => string, 'value' => int|null]
     */
    function validate_integer($value, $min = null, $max = null)
    {
        if (!is_numeric($value)) {
            return [
                'valid' => false,
                'message' => 'Must be a valid number',
                'value' => null
            ];
        }
        
        $int_value = intval($value);
        
        if ($min !== null && $int_value < $min) {
            return [
                'valid' => false,
                'message' => "Must be at least {$min}",
                'value' => null
            ];
        }
        
        if ($max !== null && $int_value > $max) {
            return [
                'valid' => false,
                'message' => "Must be at most {$max}",
                'value' => null
            ];
        }
        
        return ['valid' => true, 'message' => '', 'value' => $int_value];
    }
}

if (!function_exists('validate_enum')) {
    /**
     * Validate enum value
     * 
     * @param mixed $value Value to validate
     * @param array $allowed_values Array of allowed values
     * @return array ['valid' => bool, 'message' => string, 'value' => mixed|null]
     */
    function validate_enum($value, $allowed_values)
    {
        if (!in_array($value, $allowed_values, true)) {
            $allowed_str = implode(', ', $allowed_values);
            return [
                'valid' => false,
                'message' => "Must be one of: {$allowed_str}",
                'value' => null
            ];
        }
        
        return ['valid' => true, 'message' => '', 'value' => $value];
    }
}
