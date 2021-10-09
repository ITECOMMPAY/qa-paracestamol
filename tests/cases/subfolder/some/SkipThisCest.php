<?php

namespace ParacetamolTests\cases\subfolder\some;

use ParacetamolTests\AcceptanceTester;

class SkipThisCest
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
}
