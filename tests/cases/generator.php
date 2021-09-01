<?php

require_once __DIR__ . '/../../autoload.php';

mb_internal_encoding('UTF-8');
date_default_timezone_set('UTC');

function generate() : void
{
    $cestTemplate = <<<'NOWDOC'
<?php


namespace ParacetamolTests\cases;


use ParacetamolTests\AcceptanceTester;

class {{cest_name}}
{
{{tests}}
}
NOWDOC;

    $testTemplate = <<<'NOWDOC'
    /**
     * @param AcceptanceTester $I
     * @group {{group_name}}
     */
    public function {{test_name}}(AcceptanceTester $I)
    {
        sleep({{sleep_time}});
    }
    
NOWDOC;

    $groups = ['cat', 'dog', 'bird'];

    for ($cestNumber = 0; $cestNumber < 256; $cestNumber++)
    {
        $tests = [];

        for ($testNumber = 0; $testNumber < 256; $testNumber++)
        {
            $testSource = (new \Codeception\Util\Template($testTemplate))
                ->place('group_name', $groups[random_int(0, 2)])
                ->place('test_name', 'test' . sprintf('%003u', $testNumber))
                ->place('sleep_time', random_int(1, 10))
                ->produce();

            $tests []= $testSource;
        }

        $tests = implode(PHP_EOL, $tests);

        $cestName = 'Temp' . sprintf('%003u', $cestNumber) . 'Cest';
        $filename = __DIR__ . DIRECTORY_SEPARATOR . $cestName . '.php';

        $cestSource = (new \Codeception\Util\Template($cestTemplate))
            ->place('cest_name', $cestName)
            ->place('tests', $tests)
            ->produce();

        file_put_contents($filename, $cestSource);
    }
}

generate();
