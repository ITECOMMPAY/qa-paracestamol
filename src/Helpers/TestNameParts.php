<?php


namespace Paracetamol\Helpers;


use Ds\Set;

class TestNameParts
{
    protected Set $tests;
    protected Set $cests;
    protected Set $paths;

    protected array $strings;

    public function __construct(array $strings)
    {
        $this->tests = new Set();
        $this->cests = new Set();
        $this->paths = new Set();

        foreach ($strings as $string)
        {
            if (strpos($string, '.php:') !== false)
            {
                $this->tests->add($string);
                continue;
            }

            if (substr_compare($string, '.php', -4) === 0)
            {
                $this->cests->add($string);
                continue;
            }

            $this->paths->add($string);
        }

        $this->strings = $strings;
    }

    public function getTests() : Set
    {
        return $this->tests;
    }

    public function getCests() : Set
    {
        return $this->cests;
    }

    public function getPaths() : Set
    {
        return $this->paths;
    }

    public function getStrings() : array
    {
        return $this->strings;
    }

    public function isEmpty() : bool
    {
        return $this->tests->isEmpty() && $this->cests->isEmpty() && $this->paths->isEmpty();
    }
}
