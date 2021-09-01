<?php


namespace ParacetamolTests\cases\parallelAfter;


use ParacetamolTests\AcceptanceTester;

class SomeParallelAfterCest
{
    public function parallelAfter01(AcceptanceTester $I)
    {
        sleep(random_int(0, 2));
    }

    public function parallelAfter02(AcceptanceTester $I)
    {
        sleep(random_int(0, 2));
    }

    public function parallelAfter03(AcceptanceTester $I)
    {
        sleep(random_int(0, 2));
    }

    public function parallelAfter04(AcceptanceTester $I)
    {
        sleep(random_int(0, 2));
    }
}
