<?php

namespace App\Libraries;

/**
 * PDF Library
 * 
 * Wrapper for TCPDF library for PDF generation.
 * Migrated from CI3 Pdf.php library.
 * 
 * Note: Requires TCPDF library to be available.
 * TCPDF can be installed via Composer: composer require tecnickcom/tcpdf
 * Or use the tcpdf directory from backend-old if available.
 */
class Pdf
{
    /**
     * TCPDF instance
     * 
     * @var \TCPDF|null
     */
    protected $tcpdf = null;

    /**
     * Constructor
     * 
     * Attempts to load TCPDF library
     */
    public function __construct()
    {
        // Try to load TCPDF from Composer first
        if (class_exists('\TCPDF')) {
            $this->tcpdf = new \TCPDF();
        }
        // Try to load TCPDF from old location if available
        elseif (file_exists(APPPATH . '../backend-old/application/libraries/tcpdf/tcpdf.php')) {
            require_once APPPATH . '../backend-old/application/libraries/tcpdf/tcpdf.php';
            if (class_exists('TCPDF')) {
                $this->tcpdf = new \TCPDF();
            }
        }
    }

    /**
     * Get TCPDF instance
     * 
     * @return \TCPDF|null
     */
    public function getTCPDF()
    {
        return $this->tcpdf;
    }

    /**
     * Check if TCPDF is available
     * 
     * @return bool
     */
    public function isAvailable()
    {
        return $this->tcpdf !== null;
    }

    /**
     * Create a new PDF instance
     * 
     * @param string $orientation Page orientation (P=portrait, L=landscape)
     * @param string $unit Unit of measure (pt, mm, cm, in)
     * @param string|array $format Page format (A4, Letter, etc. or array with width and height)
     * @param bool $unicode True if document contains unicode characters
     * @param string $encoding Character encoding
     * @param bool $diskcache If true reduce the RAM memory usage by caching temporary data on disk
     * @param bool $pdfa If true set the document to PDF/A mode
     * @return \TCPDF|null
     * @throws \Exception If TCPDF is not available
     */
    public function create($orientation = 'P', $unit = 'mm', $format = 'A4', $unicode = true, $encoding = 'UTF-8', $diskcache = false, $pdfa = false)
    {
        if ($this->isAvailable()) {
            return new \TCPDF($orientation, $unit, $format, $unicode, $encoding, $diskcache, $pdfa);
        } else {
            throw new \Exception('TCPDF library not available. Please install via Composer: composer require tecnickcom/tcpdf');
        }
    }
}
