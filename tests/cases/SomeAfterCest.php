<?php


namespace ParacestamolTests\cases;


use ParacestamolTests\AcceptanceTester;

class SomeAfterCest
{
    public function after01(AcceptanceTester $I)
    {
        sleep(1);
    }

    public function after02(AcceptanceTester $I)
    {
        sleep(random_int(1, 3));
    }
}
