<?php

namespace ParacestamolTests\cases;

use ParacestamolTests\AcceptanceTester;

class TotallyFailingCest
{
    /**
     * @param AcceptanceTester $I
     * @group cat
     * @group dog
     * @group bird
     */
    public function test01(AcceptanceTester $I)
    {
        sleep(random_int(0, 2));

        throw new \PHPUnit\Framework\AssertionFailedError('this test is failed just because');
    }

    /**
     * @param AcceptanceTester $I
     * @group cat
     */
    public function test02(AcceptanceTester $I)
    {
        sleep(random_int(1, 3));

        throw new \PHPUnit\Framework\AssertionFailedError('this test is failed just because');
    }

    /**
     * @param AcceptanceTester $I
     * @group dog
     */
    public function test03(AcceptanceTester $I)
    {
        sleep(random_int(2, 4));

        throw new \PHPUnit\Framework\AssertionFailedError('this test is failed just because');
    }
}
