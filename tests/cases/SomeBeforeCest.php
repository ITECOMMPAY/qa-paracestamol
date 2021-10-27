<?php


namespace ParacestamolTests\cases;


use ParacestamolTests\AcceptanceTester;

class SomeBeforeCest
{
    public function before01(AcceptanceTester $I)
    {
        sleep(random_int(1, 3));
    }

    public function before02(AcceptanceTester $I)
    {
        sleep(random_int(1, 3));
    }
}
