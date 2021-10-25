<?php


namespace ParacestamolTests;


abstract class AbstractCest
{
    /**
     * @param AcceptanceTester $I
     * @group cat
     * @group dog
     * @group bird
     */
    public function testA01(AcceptanceTester $I)
    {
        sleep(6);
    }
}
