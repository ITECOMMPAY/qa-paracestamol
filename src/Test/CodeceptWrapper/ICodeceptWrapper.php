<?php

namespace Paracestamol\Test\CodeceptWrapper;

use Ds\Hashable;
use Ds\Set;
use Paracestamol\Helpers\TestNameParts;

interface ICodeceptWrapper extends Hashable
{
    public function start() : void;

    public function isRunning() : bool;

    public function isTimedOut() : bool;

    public function isSuccessful() : bool;

    public function isMarkedSkipped() : bool;

    public function getOutput() : string;

    public function getErrorOutput() : string;

    public function getStatusDescription() : string;


    public function matches(TestNameParts $nameParts) : bool;

    public function getMatch(TestNameParts $nameParts) : ?string;

    public function inGroups(Set $expectedGroups) : bool;


    public function getExpectedDuration() : ?int;

    public function setExpectedDuration(int $expectedDuration) : void;

    public function getActualDuration() : ?int;


    public function __toString();
}
