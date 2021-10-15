<?php


namespace Paracetamol\Command;


use Codeception\Configuration;
use Paracetamol\Helpers\CodeceptionProjectParser;
use Paracetamol\Helpers\CommandParamsToSettingsSaver;
use Paracetamol\Log\Log;
use Paracetamol\Module\ParacetamolHelper;
use Paracetamol\Paracetamol\ParacetamolRun;
use Paracetamol\Settings\SettingsRun;
use Paracetamol\Settings\SettingsSerializer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

class Run extends Command
{
    use CommandParamsToSettingsSaver;
    use CodeceptionProjectParser;

    protected Log                $log;
    protected SettingsRun        $settings;
    protected SettingsSerializer $settingsSerializer;
    protected ParacetamolRun     $paracetamolRun;

    public function __construct(Log $log, SettingsRun $settings, SettingsSerializer $settingsSerializer, ParacetamolRun $paracetamolRun)
    {
        parent::__construct();

        $this->log = $log;
        $this->settings = $settings;
        $this->settingsSerializer = $settingsSerializer;
        $this->paracetamolRun = $paracetamolRun;
    }

    protected function configure(): void
    {
        $this
            ->setName('run')
            ->setDescription('Run tests')

            ->addArgument('suite',         InputArgument::REQUIRED, 'Suite name')
            ->addArgument('config',        InputArgument::REQUIRED, 'Path to codeception.yml')
            ->addArgument('process_count', InputArgument::REQUIRED, 'Number of parallel processes')

            // Run options
            ->addOption('rerun_count',          'r', InputOption::VALUE_REQUIRED, 'How many times to rerun failed tests')
            ->addOption('continuous_rerun',    null, InputOption::VALUE_REQUIRED, 'If false - tests will be reran only after the current run is finished. If true - failed tests will be added to the end of the current run queue')
            ->addOption('delay_msec',           'd', InputOption::VALUE_REQUIRED, 'If several tests try to start at the same time then wait the given delay between these starts.' . PHP_EOL . '0 - cancels delay;' . PHP_EOL . '-1 - automatically calculate the delay using the max_rps option')
            ->addOption('max_rps',             null, InputOption::VALUE_REQUIRED, 'Used to calculate delay if the delay_msec option is set to -1. delay_msec will be set to 1000/min(max_rps, number_of_processes)')
            ->addOption('idle_timeout_sec',     't', InputOption::VALUE_REQUIRED, 'Terminate a test if it takes more than the given time to run')
            ->addOption('show_first_fail',     null, InputOption::VALUE_REQUIRED, 'Show the output of the first failed test')
            ->addOption('skip_reruns',         null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Do not rerun these tests')
            ->addOption('cest_wrapper',        null, InputOption::VALUE_REQUIRED, 'How to treat cests by default:' . PHP_EOL . "'tests' - divide cests into tests (default);" . PHP_EOL . "'cest_rerun_whole' - as in option 'not_dividable_rerun_whole';" . PHP_EOL . "'cest_rerun_failed' - as in option 'not_dividable_rerun_failed'")
            ->addOption('dividable',           null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'These cests should be divided into separate tests. This option is active when cest_wrapper is set to non-default value')
            ->addOption('not_dividable_rerun_whole',   null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'These cests should not be divided into separate tests. If any test in the cest is failed then the whole cest will be reran')
            ->addOption('not_dividable_rerun_failed',  null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'These cests should not be divided into separate tests. If a test in the cest is failed then only the failed test will be reran')
            ->addOption('fast_cest_rerun',     null, InputOption::VALUE_REQUIRED, 'If all tests selected for the current run (or a rerun) from a Cest are failed - exclude the Cest from the following reruns')

            // Run stages options
            ->addOption('run_before_series',   null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Run these tests in the given order before the main run')
            ->addOption('rerun_whole_series',  null, InputOption::VALUE_REQUIRED, 'If a test from the run_before_series option is failed then rerun all tests from the run_before_series option')
            ->addOption('serial_before_fails_run',  null, InputOption::VALUE_REQUIRED, 'If run_before_series failed even after all reruns then stop the paracetamol execution')
            ->addOption('run_before_parallel', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Run these tests in parallel before the main run')
            ->addOption('run_after_parallel',  null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Run these tests in parallel after the main run')
            ->addOption('run_after_series',    null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Run these tests in the given order after all other test runs')

            // Parsing options
            ->addOption('cache_tests',         null, InputOption::VALUE_REQUIRED, 'Parsing a bunch of big tests can take a long time. Paracetamol can cache a parsing results and use them in the further runs. The caching takes some time too and is not recommended if you don\'t experience the problem with long parsing times')
            ->addOption('store_cache_in',      null, InputOption::VALUE_REQUIRED, 'Where to store the parsing cache')

            // Filtering options
            ->addOption('groups',               'g', InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Run only tests marked with the given groups')
            ->addOption('only_tests',          null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Run only these tests')
            ->addOption('skip_tests',          null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Skip these tests')

            // Environment options
            ->addOption('env',                 null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, "Run tests in the selected environment. Note that all env arguments are treated as one environment, the same way if it was merged with ','. Also note that the order in which the environment files are given is NOT preserved when they are given using separate arguments.")
            ->addOption('override',             'o', InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Overrides codeception config values')
            ->addOption('run_output_path',     null, InputOption::VALUE_REQUIRED, 'Overrides the tests output directory')

            // Statistics options
            ->addOption('stat_endpoint',       null, InputOption::VALUE_REQUIRED, 'PostgREST endpoint for sending test duration statistics')
            ->addOption('project_name',        null, InputOption::VALUE_REQUIRED, 'Used for test duration statistics. By default your test suite namespace is used as your test project name. You can override it using this option')
            ->addOption('bulk_rows_count',     null, InputOption::VALUE_REQUIRED, 'Used for test duration statistics. How many rows get from the statistics database in a single request')

            // Paracetamol options
            ->addOption('parac_config',        null, InputOption::VALUE_REQUIRED, 'If your paracetamol.yml is stored in the different directory than your codeception.yml or have a non-default name set the path to it using this option', '')
            ->addOption('no_memory_limit',     null, InputOption::VALUE_REQUIRED, 'Tries to turn off PHP memory_limit (better raise it in your PHP settings instead)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        $this->log->setStyle(new SymfonyStyle($input, $output));

        $this->log->title('Performing a parallel run');

        $this->log->text('Initializing');

        $this->log->veryVerbose("Parsing settings");

        try
        {
            $this->setRunId();

            $this->saveArgument($input, 'suite');
            $this->saveArgument($input, 'process_count');

            $this->resolveCodeceptionBinPath();
            $this->resolveCodeceptionConfigPath($input);

            $this->loadParacetamolSettings($input);
            $this->loadCodeceptionConfig($input);

            $this->overrideSettings($input, 'project_name');
            $this->overrideSettings($input, 'rerun_count');
            $this->overrideSettings($input, 'continuous_rerun');
            $this->overrideSettings($input, 'groups');
            $this->overrideSettings($input, 'delay_msec');
            $this->overrideSettings($input, 'max_rps');
            $this->overrideSettings($input, 'stat_endpoint');
            $this->overrideSettings($input, 'run_output_path');
            $this->overrideSettings($input, 'show_first_fail');
            $this->overrideSettings($input, 'cache_tests');
            $this->overrideSettings($input, 'store_cache_in');
            $this->overrideSettings($input, 'idle_timeout_sec');
            $this->overrideSettings($input, 'only_tests');
            $this->overrideSettings($input, 'skip_tests');
            $this->overrideSettings($input, 'skip_reruns');
            $this->overrideSettings($input, 'run_before_series');
            $this->overrideSettings($input, 'run_before_parallel');
            $this->overrideSettings($input, 'run_after_series');
            $this->overrideSettings($input, 'run_after_parallel');
            $this->overrideSettings($input, 'cest_wrapper');
            $this->overrideSettings($input, 'dividable');
            $this->overrideSettings($input, 'not_dividable_rerun_whole');
            $this->overrideSettings($input, 'not_dividable_rerun_failed');
            $this->overrideSettings($input, 'rerun_whole_series');
            $this->overrideSettings($input, 'serial_before_fails_run');
            $this->overrideSettings($input, 'fast_cest_rerun');
            $this->overrideSettings($input, 'bulk_rows_count');
            $this->overrideSettings($input, 'run_output_path');
            $this->overrideSettings($input, 'no_memory_limit');

            $this->resolveProjectName();
            $this->resolveAdaptiveDelay();
            $this->resolveRunOutputPath();
            $this->resolveParacetamolModule();

            $this->noMemoryLimit();

            $this->paracetamolRun->execute();
        }
        catch (\Throwable $e)
        {
            $this->log->error($e->getMessage() . PHP_EOL . PHP_EOL . $e->getTraceAsString());
            return 1;
        }

        return 0;
    }

    protected function getLog() : Log
    {
        return $this->log;
    }

    protected function getSettings() : SettingsRun
    {
        return $this->settings;
    }

    protected function getSettingsSerializer() : SettingsSerializer
    {
        return $this->settingsSerializer;
    }




    protected function setRunId() : void
    {
        $this->settings->setRunId((new \DateTime())->format('Y-m-d\TH-i-s') . '_' . bin2hex(random_bytes(4)));
    }

    protected function resolveAdaptiveDelay() : void
    {
        $this->settings->setAdaptiveDelay($this->settings->getDelayMsec() === -1);
    }

    protected function resolveProjectName() : void
    {
        if ($this->settings->getProjectName() !== '')
        {
            return;
        }

        $this->settings->setProjectName($this->settings->getNamespace());
    }

    protected function resolveRunOutputPath() : void
    {
        if ($this->settings->getRunOutputPath() !== '')
        {
            return;
        }

        $this->settings->setRunOutputPath($this->settings->getOutputPath());
    }

    protected function resolveParacetamolModule() : void
    {
        $enabledModules = $this->settings->getEnabledModules();

        $classWithoutFirstSlash = substr(ParacetamolHelper::class, 0);

        if (in_array(ParacetamolHelper::class, $enabledModules))
        {
            $this->settings->setParacetamolModuleEnabled(true);
            return;
        }

        if (in_array($classWithoutFirstSlash, $enabledModules))
        {
            $this->settings->setParacetamolModuleName($classWithoutFirstSlash);
            return;
        }
    }

    protected function loadParacetamolSettings(InputInterface $input) : void
    {
        $configPath = $input->getOption('parac_config');

        if (empty($configPath))
        {
            $configPath = $this->settings->getTestProjectPath();
        }
        elseif (strpos($configPath, DIRECTORY_SEPARATOR) === false)
        {
            $configPath = $this->settings->getTestProjectPath() . DIRECTORY_SEPARATOR . $configPath;
        }

        if (substr_compare($configPath, '.yml', -4) !== 0)
        {
            $configPath .= DIRECTORY_SEPARATOR . 'paracetamol.yml';
        }

        if (!file_exists($configPath))
        {
            return;
        }

        $config = file_get_contents($configPath);

        $this->settingsSerializer->getSerializer()->deserialize($config, SettingsRun::class, 'yaml', [AbstractNormalizer::OBJECT_TO_POPULATE => $this->settings]);
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
