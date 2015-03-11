<?php

/**
 * Toknot (http://toknot.com)
 *
 * @copyright  Copyright (c) 2011 - 2015 Toknot.com
 * @license    http://opensource.org/licenses/bsd-license.php New BSD License
 * @link       https://github.com/chopins/toknot
 */
namespace Toknot\Exception;

use Toknot\Exception\BaseException;

class BadPropertyGetException extends BaseException {
    protected $exceptionMessage = 'Bad Property Get (%s::$%s)';
    public function __construct($class,$property) {
        $this->exceptionMessage = sprintf($this->exceptionMessage, $class,$property);
        parent::__construct($this->exceptionMessage);
    }

}