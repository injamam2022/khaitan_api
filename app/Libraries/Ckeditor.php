<?php

namespace App\Libraries;

/**
 * CKEditor Library
 * 
 * PHP class that can be used to create editor instances in PHP pages on server side.
 * Migrated from CI3 Ckeditor.php library.
 * 
 * @see http://ckeditor.com
 * 
 * Sample usage:
 * @code
 * $CKEditor = new \App\Libraries\Ckeditor();
 * $CKEditor->editor("editor1", "<p>Initial value.</p>");
 * @endcode
 * 
 * NOTE: CKEditor assets should be located in: backend/assets/ckeditor/
 */
class Ckeditor
{
    /**
     * The version of CKEditor.
     */
    const VERSION = '3.6.1';
    
    /**
     * A constant string unique for each release of CKEditor.
     */
    const TIMESTAMP = 'B5GJ5GG';

    /**
     * URL to the CKEditor installation directory (absolute or relative to document root).
     * If not set, CKEditor will try to guess it's path.
     *
     * Example usage:
     * @code
     * $CKEditor->basePath = '/ckeditor/';
     * @endcode
     */
    public $basePath;
    
    /**
     * An array that holds the global CKEditor configuration.
     * For the list of available options, see http://docs.cksource.com/ckeditor_api/symbols/CKEDITOR.config.html
     *
     * Example usage:
     * @code
     * $CKEditor->config['height'] = 400;
     * // Use @@ at the beginning of a string to output it without surrounding quotes.
     * $CKEditor->config['width'] = '@@screen.width * 0.8';
     * @endcode
     */
    public $config = [];
    
    /**
     * A boolean variable indicating whether CKEditor has been initialized.
     * Set it to true only if you have already included
     * <script> tag loading ckeditor.js in your website.
     */
    public $initialized = false;
    
    /**
     * Boolean variable indicating whether created code should be printed out or returned by a function.
     *
     * Example 1: get the code creating CKEditor instance and print it on a page with the "echo" function.
     * @code
     * $CKEditor = new \App\Libraries\Ckeditor();
     * $CKEditor->returnOutput = true;
     * $code = $CKEditor->editor("editor1", "<p>Initial value.</p>");
     * echo "<p>Editor 1:</p>";
     * echo $code;
     * @endcode
     */
    public $returnOutput = false;
    
    /**
     * An array with textarea attributes.
     *
     * When CKEditor is created with the editor() method, a HTML <textarea> element is created,
     * it will be displayed to anyone with JavaScript disabled or with incompatible browser.
     */
    public $textareaAttributes = ["rows" => 8, "cols" => 60];
    
    /**
     * A string indicating the creation date of CKEditor.
     * Do not change it unless you want to force browsers to not use previously cached version of CKEditor.
     */
    public $timestamp = "B5GJ5GG";
    
    /**
     * An array that holds event listeners.
     */
    private $events = [];
    
    /**
     * An array that holds global event listeners.
     */
    private $globalEvents = [];

    /**
     * Main Constructor.
     *
     * @param string|null $basePath URL to the CKEditor installation directory (optional).
     */
    public function __construct($basePath = null)
    {
        if (!empty($basePath)) {
            $this->basePath = $basePath;
        }
    }

    /**
     * Creates a CKEditor instance.
     * In incompatible browsers CKEditor will downgrade to plain HTML <textarea> element.
     *
     * @param string $name Name of the CKEditor instance (this will be also the "name" attribute of textarea element).
     * @param string $value Initial value (optional).
     * @param array $config The specific configurations to apply to this editor instance (optional).
     * @param array $events Event listeners for this editor instance (optional).
     *
     * Example usage:
     * @code
     * $CKEditor = new \App\Libraries\Ckeditor();
     * $CKEditor->editor("field1", "<p>Initial value.</p>");
     * @endcode
     *
     * Advanced example:
     * @code
     * $CKEditor = new \App\Libraries\Ckeditor();
     * $config = array();
     * $config['toolbar'] = array(
     *     array( 'Source', '-', 'Bold', 'Italic', 'Underline', 'Strike' ),
     *     array( 'Image', 'Link', 'Unlink', 'Anchor' )
     * );
     * $events['instanceReady'] = 'function (ev) {
     *     alert("Loaded: " + ev.editor.name);
     * }';
     * $CKEditor->editor("field1", "<p>Initial value.</p>", $config, $events);
     * @endcode
     */
    public function editor($name, $value = "", $config = [], $events = [])
    {
        $attr = "";
        foreach ($this->textareaAttributes as $key => $val) {
            $attr .= " " . $key . '="' . str_replace('"', '&quot;', $val) . '"';
        }
        $out = "<textarea name=\"" . $name . "\"" . $attr . ">" . htmlspecialchars($value) . "</textarea>\n";
        if (!$this->initialized) {
            $out .= $this->init();
        }

        $_config = $this->configSettings($config, $events);

        $js = $this->returnGlobalEvents();
        if (!empty($_config)) {
            $js .= "CKEDITOR.replace('" . $name . "', " . $this->jsEncode($_config) . ");";
        } else {
            $js .= "CKEDITOR.replace('" . $name . "');";
        }

        $out .= $this->script($js);

        if (!$this->returnOutput) {
            echo $out;
            $out = "";
        }

        return $out;
    }

    /**
     * Replaces a <textarea> with a CKEditor instance.
     *
     * @param string $id The id or name of textarea element.
     * @param array $config The specific configurations to apply to this editor instance (optional).
     * @param array $events Event listeners for this editor instance (optional).
     *
     * Example 1: adding CKEditor to <textarea name="article"></textarea> element:
     * @code
     * $CKEditor = new \App\Libraries\Ckeditor();
     * $CKEditor->replace("article");
     * @endcode
     */
    public function replace($id, $config = [], $events = [])
    {
        $out = "";
        if (!$this->initialized) {
            $out .= $this->init();
        }

        $_config = $this->configSettings($config, $events);

        $js = $this->returnGlobalEvents();
        if (!empty($_config)) {
            $js .= "CKEDITOR.replace('" . $id . "', " . $this->jsEncode($_config) . ");";
        } else {
            $js .= "CKEDITOR.replace('" . $id . "');";
        }
        $out .= $this->script($js);

        if (!$this->returnOutput) {
            echo $out;
            $out = "";
        }

        return $out;
    }

    /**
     * Replace all <textarea> elements available in the document with editor instances.
     *
     * @param string|null $className If set, replace all textareas with class className in the page.
     *
     * Example 1: replace all <textarea> elements in the page.
     * @code
     * $CKEditor = new \App\Libraries\Ckeditor();
     * $CKEditor->replaceAll();
     * @endcode
     *
     * Example 2: replace all <textarea class="myClassName"> elements in the page.
     * @code
     * $CKEditor = new \App\Libraries\Ckeditor();
     * $CKEditor->replaceAll('myClassName');
     * @endcode
     */
    public function replaceAll($className = null)
    {
        $out = "";
        if (!$this->initialized) {
            $out .= $this->init();
        }

        $_config = $this->configSettings();

        $js = $this->returnGlobalEvents();
        if (empty($_config)) {
            if (empty($className)) {
                $js .= "CKEDITOR.replaceAll();";
            } else {
                $js .= "CKEDITOR.replaceAll('" . $className . "');";
            }
        } else {
            $classDetection = "";
            $js .= "CKEDITOR.replaceAll( function(textarea, config) {\n";
            if (!empty($className)) {
                $js .= "    var classRegex = new RegExp('(?:^| )' + '" . $className . "' + '(?:$| )');\n";
                $js .= "    if (!classRegex.test(textarea.className))\n";
                $js .= "        return false;\n";
            }
            $js .= "    CKEDITOR.tools.extend(config, " . $this->jsEncode($_config) . ", true);";
            $js .= "} );";
        }

        $out .= $this->script($js);

        if (!$this->returnOutput) {
            echo $out;
            $out = "";
        }

        return $out;
    }

    /**
     * Adds event listener.
     * Events are fired by CKEditor in various situations.
     *
     * @param string $event Event name.
     * @param string $javascriptCode Javascript anonymous function or function name.
     *
     * Example usage:
     * @code
     * $CKEditor->addEventHandler('instanceReady', 'function (ev) {
     *     alert("Loaded: " + ev.editor.name);
     * }');
     * @endcode
     */
    public function addEventHandler($event, $javascriptCode)
    {
        if (!isset($this->events[$event])) {
            $this->events[$event] = [];
        }
        // Avoid duplicates.
        if (!in_array($javascriptCode, $this->events[$event])) {
            $this->events[$event][] = $javascriptCode;
        }
    }

    /**
     * Clear registered event handlers.
     * Note: this function will have no effect on already created editor instances.
     *
     * @param string|null $event Event name, if not set all event handlers will be removed (optional).
     */
    public function clearEventHandlers($event = null)
    {
        if (!empty($event)) {
            $this->events[$event] = [];
        } else {
            $this->events = [];
        }
    }

    /**
     * Adds global event listener.
     *
     * @param string $event Event name.
     * @param string $javascriptCode Javascript anonymous function or function name.
     *
     * Example usage:
     * @code
     * $CKEditor->addGlobalEventHandler('dialogDefinition', 'function (ev) {
     *     alert("Loading dialog: " + ev.data.name);
     * }');
     * @endcode
     */
    public function addGlobalEventHandler($event, $javascriptCode)
    {
        if (!isset($this->globalEvents[$event])) {
            $this->globalEvents[$event] = [];
        }
        // Avoid duplicates.
        if (!in_array($javascriptCode, $this->globalEvents[$event])) {
            $this->globalEvents[$event][] = $javascriptCode;
        }
    }

    /**
     * Clear registered global event handlers.
     * Note: this function will have no effect if the event handler has been already printed/returned.
     *
     * @param string|null $event Event name, if not set all event handlers will be removed (optional).
     */
    public function clearGlobalEventHandlers($event = null)
    {
        if (!empty($event)) {
            $this->globalEvents[$event] = [];
        } else {
            $this->globalEvents = [];
        }
    }

    /**
     * Prints javascript code.
     *
     * @param string $js
     * @return string
     */
    private function script($js)
    {
        $out = "<script type=\"text/javascript\">";
        $out .= "//<![CDATA[\n";
        $out .= $js;
        $out .= "\n//]]>";
        $out .= "</script>\n";

        return $out;
    }

    /**
     * Returns the configuration array (global and instance specific settings are merged into one array).
     *
     * @param array $config The specific configurations to apply to editor instance.
     * @param array $events Event listeners for editor instance.
     * @return array
     */
    private function configSettings($config = [], $events = [])
    {
        $_config = $this->config;
        $_events = $this->events;

        if (is_array($config) && !empty($config)) {
            $_config = array_merge($_config, $config);
        }

        if (is_array($events) && !empty($events)) {
            foreach ($events as $eventName => $code) {
                if (!isset($_events[$eventName])) {
                    $_events[$eventName] = [];
                }
                if (!in_array($code, $_events[$eventName])) {
                    $_events[$eventName][] = $code;
                }
            }
        }

        if (!empty($_events)) {
            foreach ($_events as $eventName => $handlers) {
                if (empty($handlers)) {
                    continue;
                } elseif (count($handlers) == 1) {
                    $_config['on'][$eventName] = '@@' . $handlers[0];
                } else {
                    $_config['on'][$eventName] = '@@function (ev){';
                    foreach ($handlers as $handler => $code) {
                        $_config['on'][$eventName] .= '(' . $code . ')(ev);';
                    }
                    $_config['on'][$eventName] .= '}';
                }
            }
        }

        return $_config;
    }

    /**
     * Return global event handlers.
     *
     * @return string
     */
    private function returnGlobalEvents()
    {
        static $returnedEvents;
        $out = "";

        if (!isset($returnedEvents)) {
            $returnedEvents = [];
        }

        if (!empty($this->globalEvents)) {
            foreach ($this->globalEvents as $eventName => $handlers) {
                foreach ($handlers as $handler => $code) {
                    if (!isset($returnedEvents[$eventName])) {
                        $returnedEvents[$eventName] = [];
                    }
                    // Return only new events
                    if (!in_array($code, $returnedEvents[$eventName])) {
                        $out .= ($code ? "\n" : "") . "CKEDITOR.on('" . $eventName . "', $code);";
                        $returnedEvents[$eventName][] = $code;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Initializes CKEditor (executed only once).
     *
     * @return string
     */
    private function init()
    {
        static $initComplete;
        $out = "";

        if (!empty($initComplete)) {
            return "";
        }

        if ($this->initialized) {
            $initComplete = true;
            return "";
        }

        $args = "";
        $ckeditorPath = $this->ckeditorPath();

        if (!empty($this->timestamp) && $this->timestamp != "%" . "TIMESTAMP%") {
            $args = '?t=' . $this->timestamp;
        }

        // Skip relative paths...
        if (strpos($ckeditorPath, '..') !== 0) {
            $out .= $this->script("window.CKEDITOR_BASEPATH='" . $ckeditorPath . "';");
        }

        $out .= "<script type=\"text/javascript\" src=\"" . $ckeditorPath . 'ckeditor.js' . $args . "\"></script>\n";

        $extraCode = "";
        if ($this->timestamp != self::TIMESTAMP) {
            $extraCode .= ($extraCode ? "\n" : "") . "CKEDITOR.timestamp = '" . $this->timestamp . "';";
        }
        if ($extraCode) {
            $out .= $this->script($extraCode);
        }

        $initComplete = $this->initialized = true;

        return $out;
    }

    /**
     * Return path to ckeditor.js.
     *
     * @return string
     */
    private function ckeditorPath()
    {
        if (!empty($this->basePath)) {
            return $this->basePath;
        }

        // Use CI4 base_url() helper
        $baseUrl = base_url();
        return $baseUrl . 'assets/ckeditor/';
    }

    /**
     * This little function provides a basic JSON support.
     *
     * @param mixed $val
     * @return string
     */
    private function jsEncode($val)
    {
        if (is_null($val)) {
            return 'null';
        }
        if (is_bool($val)) {
            return $val ? 'true' : 'false';
        }
        if (is_int($val)) {
            return $val;
        }
        if (is_float($val)) {
            return str_replace(',', '.', $val);
        }
        if (is_array($val) || is_object($val)) {
            if (is_array($val) && (array_keys($val) === range(0, count($val) - 1))) {
                return '[' . implode(',', array_map([$this, 'jsEncode'], $val)) . ']';
            }
            $temp = [];
            foreach ($val as $k => $v) {
                $temp[] = $this->jsEncode("{$k}") . ':' . $this->jsEncode($v);
            }
            return '{' . implode(',', $temp) . '}';
        }
        // String otherwise
        if (strpos($val, '@@') === 0) {
            return substr($val, 2);
        }
        if (strtoupper(substr($val, 0, 9)) == 'CKEDITOR.') {
            return $val;
        }

        return '"' . str_replace(["\\", "/", "\n", "\t", "\r", "\x08", "\x0c", '"'], ['\\\\', '\\/', '\\n', '\\t', '\\r', '\\b', '\\f', '\"'], $val) . '"';
    }
}
