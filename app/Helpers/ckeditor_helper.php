<?php

/**
 * CKEditor Helper for CodeIgniter 4
 * 
 * Helper functions for integrating CKEditor rich text editor.
 * Migrated from CI3 ckeditor_helper.php
 * 
 * @author Samuel Sanchez <samuel.sanchez.work@gmail.com> - http://kromack.com/
 * @package CodeIgniter
 * @license http://creativecommons.org/licenses/by-nc-sa/3.0/us/
 * @tutorial http://kromack.com/developpement-php/codeigniter/ckeditor-helper-for-codeigniter/
 * @see http://codeigniter.com/forums/viewthread/127374/
 * @version 2010-08-28 (Migrated to CI4)
 */

if (!function_exists('cke_initialize')) {
    /**
     * This function adds once the CKEditor's config vars
     * 
     * @author Samuel Sanchez 
     * @access private
     * @param array $data (default: array())
     * @return string
     */
    function cke_initialize($data = array())
    {
        $return = '';
        
        if (!defined('CI_CKEDITOR_HELPER_LOADED')) {
            define('CI_CKEDITOR_HELPER_LOADED', true);
            
            // Get base URL using CI4 helper
            $baseUrl = base_url();
            $path = $data['path'] ?? 'assets/ckeditor';
            
            $return = '<script type="text/javascript" src="' . $baseUrl . $path . '/ckeditor.js"></script>';
            $return .= "<script type=\"text/javascript\">CKEDITOR_BASEPATH = '" . $baseUrl . $path . "/';</script>";
        }
        
        return $return;
    }
}

if (!function_exists('cke_create_instance')) {
    /**
     * This function create JavaScript instances of CKEditor
     * 
     * @author Samuel Sanchez 
     * @access private
     * @param array $data (default: array())
     * @return string
     */
    function cke_create_instance($data = array())
    {
        if (!isset($data['id']) || empty($data['id'])) {
            return '';
        }
        
        $return = "<script type=\"text/javascript\">
            CKEDITOR.replace('" . $data['id'] . "', {";
        
        // Adding config values
        if (isset($data['config']) && is_array($data['config'])) {
            $configKeys = array_keys($data['config']);
            $lastKey = end($configKeys);
            
            foreach ($data['config'] as $k => $v) {
                // Support for extra config parameters
                if (is_array($v)) {
                    $return .= $k . " : [";
                    $return .= config_data($v);
                    $return .= "]";
                } else {
                    // Escape single quotes in string values
                    $v = str_replace("'", "\\'", $v);
                    $return .= $k . " : '" . $v . "'";
                }
                
                if ($k !== $lastKey) {
                    $return .= ",";
                }
            }
        }
        
        $return .= '});</script>';
        
        return $return;
    }
}

if (!function_exists('display_ckeditor')) {
    /**
     * This function displays an instance of CKEditor inside a view
     * 
     * @author Samuel Sanchez 
     * @access public
     * @param array $data (default: array())
     * @return string
     */
    function display_ckeditor($data = array())
    {
        // Initialization
        $return = cke_initialize($data);
        
        // Creating a Ckeditor instance
        $return .= cke_create_instance($data);
        
        // Adding styles values
        if (isset($data['styles']) && is_array($data['styles']) && isset($data['id'])) {
            $return .= "<script type=\"text/javascript\">CKEDITOR.addStylesSet( 'my_styles_" . $data['id'] . "', [";
            
            $styleKeys = array_keys($data['styles']);
            $lastStyleKey = end($styleKeys);
            
            foreach ($data['styles'] as $k => $v) {
                $return .= "{ name : '" . $k . "', element : '" . ($v['element'] ?? '') . "', styles : { ";
                
                if (isset($v['styles']) && is_array($v['styles'])) {
                    $styleSubKeys = array_keys($v['styles']);
                    $lastStyleSubKey = end($styleSubKeys);
                    
                    foreach ($v['styles'] as $k2 => $v2) {
                        $return .= "'" . $k2 . "' : '" . $v2 . "'";
                        
                        if ($k2 !== $lastStyleSubKey) {
                            $return .= ",";
                        }
                    }
                }
                
                $return .= '} }';
                
                if ($k !== $lastStyleKey) {
                    $return .= ',';
                }
            }
            
            $return .= ']);';
            $return .= "CKEDITOR.instances['" . $data['id'] . "'].config.stylesCombo_stylesSet = 'my_styles_" . $data['id'] . "';
            </script>";
        }
        
        return $return;
    }
}

if (!function_exists('config_data')) {
    /**
     * config_data function.
     * This function look for extra config data
     *
     * @author ronan
     * @link http://kromack.com/developpement-php/codeigniter/ckeditor-helper-for-codeigniter/comment-page-5/#comment-545
     * @access public
     * @param array $data. (default: array())
     * @return string
     */
    function config_data($data = array())
    {
        $return = '';
        $dataValues = array_values($data);
        $lastValue = end($dataValues);
        
        foreach ($data as $key) {
            if (is_array($key)) {
                $return .= "[";
                $keyValues = array_values($key);
                $lastKeyValue = end($keyValues);
                
                foreach ($key as $string) {
                    $return .= "'" . $string . "'";
                    if ($string !== $lastKeyValue) {
                        $return .= ",";
                    }
                }
                $return .= "]";
            } else {
                $return .= "'" . $key . "'";
            }
            
            if ($key !== $lastValue) {
                $return .= ",";
            }
        }
        
        return $return;
    }
}
