<?php

namespace ParacestamolTests\cases;

use ParacestamolTests\AcceptanceTester;

class SomePassingCest
{
    /**
     * @param AcceptanceTester $I
     * @group cat
     * @group dog
     * @group bird
     */
    public function test01(AcceptanceTester $I)
    {
        sleep(1);
    }

    /**
     * @param AcceptanceTester $I
     * @group cat
     * @group bird
     */
    public function test02(AcceptanceTester $I)
    {
        sleep(2);
    }
}
