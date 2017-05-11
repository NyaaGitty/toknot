<?php

/**
 * Toknot (http://toknot.com)
 *
 * @copyright  Copyright (c) 2011 - 2017 Toknot.com
 * @license    http://toknot.com/LICENSE.txt New BSD License
 * @link       https://github.com/chopins/toknot
 * @since 4.0
 * @filesource
 * @package Toknot.Exception
 */

namespace Toknot\Exception;

/**
 * NotAllowedMethodException
 * 
 */
class MethodNotAllowedException extends HttpResponseExcetion {

    public function __construct($exception) {
        parent::__construct(405, 'Method Not Allowed', $exception);
    }

}
