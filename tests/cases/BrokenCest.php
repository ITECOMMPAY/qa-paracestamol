<?php

namespace ParacetamolTests\cases;

use ParacetamolTests\AcceptanceTester;

class BrokenCest
{
    public function test01(AcceptanceTester $I)
    {
        $a = 1 / 0;
    }
}
