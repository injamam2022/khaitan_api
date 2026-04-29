<?php

namespace App\Libraries;

/**
 * CKFinder Library
 * 
 * PHP class for integrating CKFinder file manager.
 * Migrated from CI3 Ckfinder.php library.
 * 
 * @see http://ckfinder.com
 * 
 * NOTE: CKFinder assets should be located in: backend/assets/ckfinder/
 */
class Ckfinder
{
    const DEFAULT_BASEPATH = '/assets/ckfinder/';

    public $basePath;
    public $width;
    public $height;
    public $selectFunction;
    public $selectFunctionData;
    public $selectThumbnailFunction;
    public $selectThumbnailFunctionData;
    public $disableThumbnailSelection = false;
    public $className = '';
    public $id = '';
    public $startupPath;
    public $resourceType;
    public $rememberLastFolder = true;
    public $startupFolderExpanded = false;

    /**
     * PHP 5 Constructor
     * 
     * @param string $basePath Base path to CKFinder
     * @param string $width Width of CKFinder iframe
     * @param int $height Height of CKFinder iframe
     * @param string|null $selectFunction JavaScript function to call when file is selected
     */
    public function __construct($basePath = self::DEFAULT_BASEPATH, $width = '100%', $height = 400, $selectFunction = null)
    {
        $this->basePath = $basePath;
        $this->width = $width;
        $this->height = $height;
        $this->selectFunction = $selectFunction;
        $this->selectThumbnailFunction = $selectFunction;
    }

    /**
     * Renders CKFinder in the current page.
     */
    public function create()
    {
        echo $this->createHtml();
    }

    /**
     * Gets the HTML needed to create a CKFinder instance.
     *
     * @return string
     */
    public function createHtml()
    {
        $className = $this->className;
        if (!empty($className)) {
            $className = ' class="' . $className . '"';
        }

        $id = $this->id;
        if (!empty($id)) {
            $id = ' id="' . $id . '"';
        }

        return '<iframe src="' . $this->buildUrl() . '" width="' . $this->width . '" ' .
            'height="' . $this->height . '"' . $className . $id . ' frameborder="0" scrolling="no"></iframe>';
    }

    /**
     * Build CKFinder URL with query parameters
     *
     * @param string $url Optional URL override
     * @return string
     */
    private function buildUrl($url = "")
    {
        if (!$url) {
            $url = $this->basePath;
        }

        $qs = "";

        if (empty($url)) {
            $url = self::DEFAULT_BASEPATH;
        }

        // Use CI4 base_url() helper
        if (strpos($url, '/') === 0) {
            // Absolute path - prepend base URL
            $baseUrl = base_url();
            $url = rtrim($baseUrl, '/') . $url;
        } elseif (strpos($url, 'http') !== 0) {
            // Relative path - prepend base URL
            $baseUrl = base_url();
            $url = rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
        }

        if (substr($url, -1) != '/') {
            $url = $url . '/';
        }

        $url .= 'ckfinder.html';

        if (!empty($this->selectFunction)) {
            $qs .= '?action=js&amp;func=' . $this->selectFunction;
        }

        if (!empty($this->selectFunctionData)) {
            $qs .= $qs ? "&amp;" : "?";
            $qs .= 'data=' . rawurlencode($this->selectFunctionData);
        }

        if ($this->disableThumbnailSelection) {
            $qs .= $qs ? "&amp;" : "?";
            $qs .= "dts=1";
        } elseif (!empty($this->selectThumbnailFunction) || !empty($this->selectFunction)) {
            $qs .= $qs ? "&amp;" : "?";
            $qs .= 'thumbFunc=' . (!empty($this->selectThumbnailFunction) ? $this->selectThumbnailFunction : $this->selectFunction);

            if (!empty($this->selectThumbnailFunctionData)) {
                $qs .= '&amp;tdata=' . rawurlencode($this->selectThumbnailFunctionData);
            } elseif (empty($this->selectThumbnailFunction) && !empty($this->selectFunctionData)) {
                $qs .= '&amp;tdata=' . rawurlencode($this->selectFunctionData);
            }
        }

        if (!empty($this->startupPath)) {
            $qs .= ($qs ? "&amp;" : "?");
            $qs .= "start=" . urlencode($this->startupPath . ($this->startupFolderExpanded ? ':1' : ':0'));
        }

        if (!empty($this->resourceType)) {
            $qs .= ($qs ? "&amp;" : "?");
            $qs .= "type=" . urlencode($this->resourceType);
        }

        if (!$this->rememberLastFolder) {
            $qs .= ($qs ? "&amp;" : "?");
            $qs .= "rlf=0";
        }

        if (!empty($this->id)) {
            $qs .= ($qs ? "&amp;" : "?");
            $qs .= "id=" . urlencode($this->id);
        }

        return $url . $qs;
    }

    /**
     * Static "Create".
     *
     * @param string $basePath Base path to CKFinder
     * @param string $width Width of CKFinder iframe
     * @param int $height Height of CKFinder iframe
     * @param string|null $selectFunction JavaScript function to call when file is selected
     */
    public static function createStatic($basePath = self::DEFAULT_BASEPATH, $width = '100%', $height = 400, $selectFunction = null)
    {
        $finder = new self($basePath, $width, $height, $selectFunction);
        $finder->create();
    }

    /**
     * Static "SetupFCKeditor".
     *
     * @param object $editorObj FCKeditor object reference
     * @param string $basePath Base path to CKFinder
     * @param string|null $imageType Image resource type
     * @param string|null $flashType Flash resource type
     */
    public static function setupFCKeditor(&$editorObj, $basePath = self::DEFAULT_BASEPATH, $imageType = null, $flashType = null)
    {
        if (empty($basePath)) {
            $basePath = self::DEFAULT_BASEPATH;
        }

        $ckfinder = new self($basePath);
        $ckfinder->setupFCKeditorObject($editorObj, $imageType, $flashType);
    }

    /**
     * Non-static method of attaching CKFinder to FCKeditor
     *
     * @param object $editorObj FCKeditor object reference
     * @param string|null $imageType Image resource type
     * @param string|null $flashType Flash resource type
     */
    public function setupFCKeditorObject(&$editorObj, $imageType = null, $flashType = null)
    {
        $url = $this->basePath;

        // If it is a path relative to the current page.
        if (isset($url[0]) && $url[0] != '/') {
            $request = \Config\Services::request();
            $requestUri = $request->getUri()->getPath();
            $url = substr($requestUri, 0, strrpos($requestUri, '/') + 1) . $url;
        }

        $url = $this->buildUrl($url);
        $qs = (strpos($url, "?") !== false) ? "&" : "?";

        if ($this->width !== '100%' && is_numeric(str_ireplace("px", "", $this->width))) {
            $width = intval($this->width);
            $editorObj->config['LinkBrowserWindowWidth'] = $width;
            $editorObj->config['ImageBrowserWindowWidth'] = $width;
            $editorObj->config['FlashBrowserWindowWidth'] = $width;
        }
        if ($this->height !== 400 && is_numeric(str_ireplace("px", "", $this->height))) {
            $height = intval($this->height);
            $editorObj->config['LinkBrowserWindowHeight'] = $height;
            $editorObj->config['ImageBrowserWindowHeight'] = $height;
            $editorObj->config['FlashBrowserWindowHeight'] = $height;
        }

        $editorObj->config['LinkBrowserURL'] = $url;
        $editorObj->config['ImageBrowserURL'] = $url . $qs . 'type=' . (empty($imageType) ? 'Images' : $imageType);
        $editorObj->config['FlashBrowserURL'] = $url . $qs . 'type=' . (empty($flashType) ? 'Flash' : $flashType);

        $dir = substr($url, 0, strrpos($url, "/") + 1);
        $editorObj->config['LinkUploadURL'] = $dir . urlencode('core/connector/php/connector.php?command=QuickUpload&type=Files');
        $editorObj->config['ImageUploadURL'] = $dir . urlencode('core/connector/php/connector.php?command=QuickUpload&type=') . (empty($imageType) ? 'Images' : $imageType);
        $editorObj->config['FlashUploadURL'] = $dir . urlencode('core/connector/php/connector.php?command=QuickUpload&type=') . (empty($flashType) ? 'Flash' : $flashType);
    }

    /**
     * Static "SetupCKEditor".
     *
     * @param object $editorObj CKEditor object reference
     * @param string $basePath Base path to CKFinder
     * @param string|null $imageType Image resource type
     * @param string|null $flashType Flash resource type
     */
    public static function setupCKEditor(&$editorObj, $basePath = self::DEFAULT_BASEPATH, $imageType = null, $flashType = null)
    {
        if (empty($basePath)) {
            $basePath = self::DEFAULT_BASEPATH;
        }

        $ckfinder = new self($basePath);
        $ckfinder->setupCKEditorObject($editorObj, $imageType, $flashType);
    }

    /**
     * Non-static method of attaching CKFinder to CKEditor
     *
     * @param object $editorObj CKEditor object reference
     * @param string|null $imageType Image resource type
     * @param string|null $flashType Flash resource type
     */
    public function setupCKEditorObject(&$editorObj, $imageType = null, $flashType = null)
    {
        $url = $this->basePath;

        // If it is a path relative to the current page.
        if (isset($url[0]) && $url[0] != '/') {
            $request = \Config\Services::request();
            $requestUri = $request->getUri()->getPath();
            $url = substr($requestUri, 0, strrpos($requestUri, '/') + 1) . $url;
        }

        $url = $this->buildUrl($url);
        $qs = (strpos($url, "?") !== false) ? "&" : "?";

        if ($this->width !== '100%' && is_numeric(str_ireplace("px", "", $this->width))) {
            $width = intval($this->width);
            $editorObj->config['filebrowserWindowWidth'] = $width;
        }
        if ($this->height !== 400 && is_numeric(str_ireplace("px", "", $this->height))) {
            $height = intval($this->height);
            $editorObj->config['filebrowserWindowHeight'] = $height;
        }

        $editorObj->config['filebrowserBrowseUrl'] = $url;
        $editorObj->config['filebrowserImageBrowseUrl'] = $url . $qs . 'type=' . (empty($imageType) ? 'Images' : $imageType);
        $editorObj->config['filebrowserFlashBrowseUrl'] = $url . $qs . 'type=' . (empty($flashType) ? 'Flash' : $flashType);

        $dir = substr($url, 0, strrpos($url, "/") + 1);
        $editorObj->config['filebrowserUploadUrl'] = $dir . 'core/connector/php/connector.php?command=QuickUpload&type=Files';
        $editorObj->config['filebrowserImageUploadUrl'] = $dir . 'core/connector/php/connector.php?command=QuickUpload&type=' . (empty($imageType) ? 'Images' : $imageType);
        $editorObj->config['filebrowserFlashUploadUrl'] = $dir . 'core/connector/php/connector.php?command=QuickUpload&type=' . (empty($flashType) ? 'Flash' : $flashType);
    }
}
