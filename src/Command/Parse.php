<?php

namespace Paracetamol\Command;

use Paracetamol\Helpers\CodeceptionSettingsParser;
use Paracetamol\Helpers\CommandParamsToSettingsSaver;
use Paracetamol\Log\Log;
use Paracetamol\Paracetamol\ParacetamolParse;
use Paracetamol\Settings\ISettingsSerializer;
use Paracetamol\Settings\SettingsParse;
use Paracetamol\Settings\SettingsSerializer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class Parse extends Command
{
    use CommandParamsToSettingsSaver;
    use CodeceptionSettingsParser;

    protected Log                $log;
    protected SettingsParse      $settings;
    protected SettingsSerializer $settingsSerializer;
    protected ParacetamolParse   $paracetamolParse;

    public function __construct(Log $log, SettingsParse $settings, SettingsSerializer $settingsSerializer, ParacetamolParse $paracetamolParse)
    {
        parent::__construct();

        $this->log = $log;
        $this->settings = $settings;
        $this->settingsSerializer = $settingsSerializer;
        $this->paracetamolParse = $paracetamolParse;
    }

    protected function configure(): void
    {
        $this
            ->setName('parse')
            ->setDescription('Parse tests and output json')

            ->addArgument('suite',         InputArgument::REQUIRED, 'Suite name')
            ->addArgument('config',        InputArgument::REQUIRED, 'Path to codeception.yml')
            ->addArgument('result_file',   InputArgument::REQUIRED, 'The result file to save')

            ->addOption('override',         'o', InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Override codeception config values')
            ->addOption('env',             null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Run tests in selected environment')
            ->addOption('cache_tests',     null, InputOption::VALUE_REQUIRED, 'Compute a hash for every test file. Save test data with computed hash in a cache file. If the cache file is already exists - use test data from it instead of parsing a test again if the hash for the test matches.')
            ->addOption('store_cache_in',  null, InputOption::VALUE_REQUIRED, 'Store cache in the given folder.')
            ->addOption('no_memory_limit', null, InputOption::VALUE_REQUIRED, "Executes ini_set('memory_limit', '-1');")
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        $this->log->setStyle(new SymfonyStyle($input, $output));

        $this->log->title('Parsing the given codeception tests');

        $this->log->text('Initializing');

        $this->log->veryVerbose("Parsing settings");

        try
        {
            $this->saveArgument($input, 'suite');
            $this->saveArgument($input, 'result_file');

            $this->resolveCodeceptionBinPath();
            $this->resolveCodeceptionConfigPath($input);

            $this->loadCodeceptionConfig($input);

            $this->overrideSettings($input, 'cache_tests');
            $this->overrideSettings($input, 'store_cache_in');

            $this->noMemoryLimit($input);

            $this->paracetamolParse->execute();
        }
        catch (\Throwable $e)
        {
            $this->log->error($e->getMessage());
            $this->trySaveError($e);

            return 1;
        }

        return 0;
    }

    protected function getLog() : Log
    {
        return $this->log;
    }

    protected function getSettings() : SettingsParse
    {
        return $this->settings;
    }

    protected function getSettingsSerializer() : ISettingsSerializer
    {
        return $this->settingsSerializer;
    }

    protected function trySaveError(\Throwable $e)
    {
        $resultFile = $this->settings->getResultFile();

        if (empty($resultFile))
        {
            return;
        }

        $error = [
            'status' => 'fail',
            'data' => [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]
        ];

        file_put_contents($resultFile, json_encode($error), LOCK_EX);
    }

    protected function noMemoryLimit(InputInterface $input)
    {
        if ($input->getOption('no_memory_limit') !== true)
        {
            return;
        }

        ini_set('memory_limit', '-1');
    }
}
