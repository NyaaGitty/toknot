<?php

/**
 * Toknot (http://toknot.com)
 *
 * @copyright  Copyright (c) 2011 - 2013 Toknot.com
 * @license    http://opensource.org/licenses/bsd-license.php New BSD License
 * @link       https://github.com/chopins/toknot
 */

namespace Toknot\Exception;

use Toknot\Exception\StandardException;

class BadNamespaceException extends StandardException {

    protected $exceptionMessage = 'Bad Namespace Declaration (%s)';

    public function __construct($namespace) {
        $message = sprintf($this->exceptionMessage, $namespace);
        parent::__construct($message);
    }

}