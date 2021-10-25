<?php

namespace Paracestamol\Test;

use Codeception\Util\Autoload;
use Paracestamol\Log\Log;
use Paracestamol\Settings\SettingsParse;
use PHPUnit\Framework\TestSuite;
use Symfony\Component\Finder\Finder;

class Parser
{
    protected Log $log;
    protected SettingsParse $settings;

    public function __construct(Log $log, SettingsParse $settings)
    {
        $this->log = $log;
        $this->settings = $settings;
    }

    public function parseTests(string $testsDir, array $previousData = []) : array
    {
        $this->log->veryVerbose('Loading classes from the support directory');

        Autoload::addNamespace($this->settings->getNamespace(), $this->settings->getSupportPath());

        $this->log->veryVerbose('Searching for tests in: ' . $testsDir);

        return [
            'status' => 'success',
            'data'   => [
                'cests' => $this->parseFromDir($testsDir, $previousData['cests'] ?? [])
            ]
        ];
    }

    protected function parseFromDir(string $testsDir, array $previousCests = []) : array
    {
        $result = [];

        $files = Finder::create()
                       ->files()
                       ->name('*Cest.php')
                       ->in($testsDir)
                       ->followLinks()
        ;

        /** @var \Symfony\Component\Finder\SplFileInfo $file */
        foreach ($files as $file)
        {
            $realPath = $file->getRealPath();
            $cestName = $file->getRelativePathname();

            $hash = '';

            if ($this->settings->isCacheTests())
            {
                $hash = sha1_file($realPath);

                $previousHash = $previousCests[$cestName]['hash'] ?? '';

                if ($previousHash !== '' && $hash === $previousHash)
                {
                    $this->log->debug("$cestName - will be loaded from the previous result");

                    $result[$cestName] = $previousCests[$cestName];
                    continue;
                }
            }

            try
            {
                $result[$cestName] = $this->parseFromFile($file->getRealPath(), $file->getRelativePathname());
            }
            catch (\Throwable $e)
            {
                $this->log->note("$cestName - skipped because of the error: " . $e->getMessage());
                $result[$cestName]['error'] = $e->getMessage();
            }

            $result[$cestName]['hash'] = $hash;
        }

        return $result;
    }

    protected function parseFromFile(string $realPath, string $cestName) : array
    {
        $this->log->debug('Loading tests from the file: ' . $cestName);

        \Codeception\Lib\Parser::load($realPath);

        //TODO Do we need to support Cests that have multiple classes in one file?
        $testClass = \Codeception\Lib\Parser::getClassesFromFile($realPath)[0];

        if (substr($testClass, -strlen('Cest')) !== 'Cest')
        {
            return [];
        }

        if (!(new \ReflectionClass($testClass))->isInstantiable())
        {
            return [];
        }

        return [
            'groups' => (new TestSuite($testClass))->getGroups(),
            'tests' => $this->parseFromClass($testClass)
        ];
    }

    protected function parseFromClass(string $classNameFull) : array
    {
        $result = [];

        $methods = get_class_methods($classNameFull);

        foreach ($methods as $methodName)
        {
            if (strpos($methodName, '_') === 0)
            {
                continue;
            }

            $result[$methodName] = $this->parseFromMethod($classNameFull, $methodName);
        }

        return [$classNameFull => $result];
    }

    protected function parseFromMethod(string $classNameFull, string $methodName) : array
    {
        $groups = \PHPUnit\Util\Test::getGroups($classNameFull, $methodName);

        $result['groups'] = $groups;

        return $result;
    }
}
