<?php

/**
 * Toknot (http://toknot.com)
 *
 * @copyright  Copyright (c) 2011 - 2013 Toknot.com
 * @license    http://toknot.com/LICENSE.txt New BSD License
 * @link       https://github.com/chopins/toknot
 */

namespace Toknot\Boot\Exception;

use Toknot\Exception\CustomHttpStatusExecption;

class ForbiddenException extends CustomHttpStatusExecption {

    protected $httpStatus = 'Status:403 Forbidden';

}