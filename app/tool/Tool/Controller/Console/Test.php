<?php

/**
 * Toknot (http://toknot.com)
 *
 * @copyright  Copyright (c) 2011 - 2017 Toknot.com
 * @license    http://toknot.com/LICENSE.txt New BSD License
 * @link       https://github.com/chopins/toknot
 */

namespace Tool\Controller\Console;

use Toknot\Share\DB\DB;

/**
 * Test
 *
 */
class Test {

    /**
     * @console test
     */
    public function __construct() {
        //select a, b, c from (select * from table)
        echo DB::SELECT;
       
    }

}
