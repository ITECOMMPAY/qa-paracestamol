<?php

namespace ParacetamolTests\cases;

use ParacetamolTests\AcceptanceTester;

class NotDividableCest
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
     */
    public function test02(AcceptanceTester $I)
    {
        throw new \PHPUnit\Framework\AssertionFailedError('this test is failed just because');
    }

    /**
     * @param AcceptanceTester $I
     * @group dog
     */
    public function test03(AcceptanceTester $I)
    {
        sleep(3);
    }
}
