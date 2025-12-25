<?php
/**
 * Simple DOCX Text Extractor
 * 
 * Extracts plain text from .docx files without requiring external libraries
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Extract text from a .docx file
 * 
 * @param string $filepath Path to the .docx file
 * @return string|false Extracted text or false on failure
 */
function lendcity_extract_docx_text($filepath) {
    if (!file_exists($filepath)) {
        return false;
    }
    
    // .docx files are ZIP archives
    $zip = new ZipArchive();
    
    if ($zip->open($filepath) !== true) {
        return false;
    }
    
    // The main document content is in word/document.xml
    $content = $zip->getFromName('word/document.xml');
    $zip->close();
    
    if ($content === false) {
        return false;
    }
    
    // Parse XML and extract text
    $xml = simplexml_load_string($content);
    
    if ($xml === false) {
        return false;
    }
    
    // Register namespaces
    $xml->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    
    // Extract all text nodes
    $text_nodes = $xml->xpath('//w:t');
    
    $text = '';
    foreach ($text_nodes as $node) {
        $text .= (string)$node . ' ';
    }
    
    // Clean up extra whitespace
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);
    
    return $text;
}

/**
 * Get word count from .docx file
 * 
 * @param string $filepath Path to the .docx file
 * @return int Word count or 0 on failure
 */
function lendcity_get_docx_word_count($filepath) {
    $text = lendcity_extract_docx_text($filepath);
    
    if ($text === false) {
        return 0;
    }
    
    return str_word_count($text);
}
