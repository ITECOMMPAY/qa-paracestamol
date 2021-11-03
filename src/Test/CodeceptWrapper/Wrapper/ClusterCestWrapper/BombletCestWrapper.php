<?php

namespace Paracestamol\Test\CodeceptWrapper\Wrapper\ClusterCestWrapper;

use Paracestamol\Test\CodeceptWrapper\Wrapper\CestWrapper;

class BombletCestWrapper extends CestWrapper implements IClusterBomblet
{
    protected function isFailFast() : bool
    {
        return false;
    }
}
