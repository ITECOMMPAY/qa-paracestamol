<?php

namespace ParacestamolTests\cases;

use ParacestamolTests\AcceptanceTester;

class BrokenCest
{
    public function test01(AcceptanceTester $I)
    {
        $a = 1 / 0;
    }
}
