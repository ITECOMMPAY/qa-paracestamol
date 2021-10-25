<?php

namespace ParacestamolTests\cases;

use ParacestamolTests\AcceptanceTester;

class NotDividableUntilRerunFlickingCest
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
        $rand = random_int(0, 1);

        if ($rand === 1)
        {
            sleep(2);
        }
        else
        {
            throw new \PHPUnit\Framework\AssertionFailedError('out of luck');
        }
    }

    /**
     * @param AcceptanceTester $I
     * @group dog
     */
    public function test03(AcceptanceTester $I)
    {
        sleep(3);
    }

    /**
     * @param AcceptanceTester $I
     * @group cat
     * @group dog
     */
    public function test04(AcceptanceTester $I)
    {
        $rand = random_int(0, 1);

        if ($rand === 1)
        {
            sleep(4);
        }
        else
        {
            throw new \PHPUnit\Framework\AssertionFailedError('out of luck');
        }
    }
}
