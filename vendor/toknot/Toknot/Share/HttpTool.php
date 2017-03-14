<?php

/**
 * Toknot (http://toknot.com)
 *
 * @copyright  Copyright (c) 2011 - 2017 Toknot.com
 * @license    http://toknot.com/LICENSE.txt New BSD License
 * @link       https://github.com/chopins/toknot
 */

namespace Toknot\Share;

/**
 * StreamKit
 *
 * @author chopin
 */
class HttpTool {

    private $wrapper = null;

    public function __construct($url, $method = 'HEAD', $header = '') {
        $urlPart = parse_url($url);
        $option = [$urlPart['scheme'] => ['method' => $method, 'header' => $header]];
        $this->wrapper = Tookit::getStreamWrappersData($url, $option);
    }

    public function getHeader($field) {
        foreach ($this->wrapper as $header) {
            if (strpos($header, $field) === 0) {
                list(, $v) = explode(':', $header, 2);
                return trim($v);
            }
        }
        return '';
    }

    public function getStatus() {
        list(, $statusCode) = explode(' ', $this->wrapper[0], 3);
        return $statusCode;
    }

    public static function formatDate($time) {
        return date('D, d M Y H:i:s e', $time);
    }

    public static function formatHeader($field, $value) {
        return "$field: $value\r\n";
    }

}