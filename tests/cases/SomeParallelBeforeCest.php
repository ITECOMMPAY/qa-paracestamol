<?php


namespace ParacestamolTests\cases;


use ParacestamolTests\AcceptanceTester;

class SomeParallelBeforeCest
{
    public function parallelBefore01(AcceptanceTester $I)
    {
        sleep(random_int(0, 2));
    }

    public function parallelBefore02(AcceptanceTester $I)
    {
        sleep(random_int(0, 2));
    }

    public function parallelBefore03(AcceptanceTester $I)
    {
        sleep(random_int(0, 2));
    }

    public function parallelBefore04(AcceptanceTester $I)
    {
        sleep(random_int(0, 2));
    }
}
