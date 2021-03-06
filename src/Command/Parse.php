<?php

namespace Paracestamol\Command;

use Paracestamol\Helpers\CodeceptionProjectParser;
use Paracestamol\Helpers\CommandParamsToSettingsSaver;
use Paracestamol\Log\Log;
use Paracestamol\Paracestamol\ParacestamolParse;
use Paracestamol\Settings\ISettingsSerializer;
use Paracestamol\Settings\SettingsParse;
use Paracestamol\Settings\SettingsSerializer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class Parse extends Command
{
    use CommandParamsToSettingsSaver;
    use CodeceptionProjectParser;

    protected Log                $log;
    protected SettingsParse      $settings;
    protected SettingsSerializer $settingsSerializer;
    protected ParacestamolParse   $paracestamolParse;

    public function __construct(Log $log, SettingsParse $settings, SettingsSerializer $settingsSerializer, ParacestamolParse $paracestamolParse)
    {
        parent::__construct();

        $this->log = $log;
        $this->settings = $settings;
        $this->settingsSerializer = $settingsSerializer;
        $this->paracestamolParse = $paracestamolParse;
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
            ->addOption('env',             null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Run tests in the selected environment')
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
            $this->overrideSettings($input, 'no_memory_limit');

            $this->noMemoryLimit();

            $this->paracestamolParse->execute();
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

    protected function noMemoryLimit()
    {
        if (!$this->settings->isNoMemoryLimit())
        {
            return;
        }

        ini_set('memory_limit', '-1');
    }
}
