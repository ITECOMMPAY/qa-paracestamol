<?php


namespace Paracestamol\Helpers;


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
            if ($testName = $this->normalizeTestName($string))
            {
                $this->tests->add($testName);
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

    public function matchesTest(string $testName, string $methodName = '') : bool
    {
        if ($methodName !== '')
        {
            $methodName = mb_strtolower($methodName);
            return $this->getTests()->contains("$testName:$methodName");
        }

        return $this->getTests()->contains($this->normalizeTestName($testName));
    }

    public function matchesCest(string $cestName) : bool
    {
        return $this->getCests()->contains($cestName);
    }

    public function matchesPath(string $path) : bool
    {
        foreach ($this->getSubpaths($path) as $subpath)
        {
            if ($this->getPaths()->contains($subpath))
            {
                return true;
            }
        }

        return false;
    }

    private function normalizeTestName(string $testName) : ?string
    {
        $p = mb_strpos($testName, '.php:');

        if ($p === false)
        {
            return null;
        }

        $cestName = mb_substr($testName, 0, $p+4);
        $testName = mb_strtolower(mb_substr($testName, $p+5));

        return "$cestName:$testName";
    }

    private function getSubpaths(string $path) : array
    {
        $result = [];
        $parts = explode('/', $path);

        if (count($parts) === 1)
        {
            return [$path];
        }

        while (!empty($parts))
        {
            $subpath = implode('/', $parts);
            $result []= $subpath;
            array_pop($parts);
        }

        return $result;
    }

    public function isEmpty() : bool
    {
        return $this->tests->isEmpty() && $this->cests->isEmpty() && $this->paths->isEmpty();
    }
}
