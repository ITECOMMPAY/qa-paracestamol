<?php


namespace ParacetamolTests\cases;


use ParacetamolTests\AcceptanceTester;

class SomeOtherCest
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
    }

    /**
     * @param AcceptanceTester $I
     * @group bird
     */
    public function test04(AcceptanceTester $I)
    {
        sleep(random_int(3, 5));
    }

    public function testTimeout(AcceptanceTester $I)
    {
        sleep(60);
    }
}
