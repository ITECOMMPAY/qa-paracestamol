<?php


namespace ParacetamolTests\cases;


use ParacetamolTests\AcceptanceTester;

class SomeBeforeAfterCest
{
    public function before01(AcceptanceTester $I)
    {
        sleep(random_int(1, 3));
    }

    public function before02(AcceptanceTester $I)
    {
        sleep(random_int(1, 3));
    }

    public function after01(AcceptanceTester $I)
    {
        sleep(random_int(1, 3));
    }

    public function after02(AcceptanceTester $I)
    {
        sleep(random_int(1, 3));
    }
}
