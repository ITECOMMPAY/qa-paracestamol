<?php


namespace Paracetamol\Command;


use Paracetamol\Helpers\CodeceptionSettingsParser;
use Paracetamol\Helpers\CommandParamsToSettingsSaver;
use Paracetamol\Log\Log;
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
    use CodeceptionSettingsParser;

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

            ->addOption('rerun_count',          'r', InputOption::VALUE_REQUIRED, 'Number of reruns for failed tests')
            ->addOption('continuous_rerun',    null, InputOption::VALUE_REQUIRED, 'If false - tests will be reran only after the run is finished. If true - failed tests will be added to the end of the run queue.')
            ->addOption('groups',               'g', InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Only execute tests marked by the given groups')
            ->addOption('delay_msec',           'd', InputOption::VALUE_REQUIRED, 'Delay in milliseconds (a one thousandth of a second) between sequential test runs')
            ->addOption('env',                 null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Run tests in selected environment')
            ->addOption('override',             'o', InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Override codeception config values')
            ->addOption('idle_timeout_sec',     't', InputOption::VALUE_REQUIRED, 'Test idle timeout')
            ->addOption('stat_endpoint',       null, InputOption::VALUE_REQUIRED, 'PostgREST endpoint for sending test duration statistics')
            ->addOption('show_first_fail',     null, InputOption::VALUE_REQUIRED, 'Show output of the first failed test')
            ->addOption('cache_tests',         null, InputOption::VALUE_REQUIRED, 'Compute a hash for every test file. Save test data with computed hash in a cache file. If the cache file is already exists - use test data from it instead of parsing a test again if the hash for the test matches.')
            ->addOption('store_cache_in',      null, InputOption::VALUE_REQUIRED, 'Store cache in the given folder.')
            ->addOption('project_name',        null, InputOption::VALUE_REQUIRED, 'Test project name to discern tests for test duration statistics.')
            ->addOption('parac_config',        null, InputOption::VALUE_REQUIRED, 'Full path to paracetamol config', '')
            ->addOption('only_tests',          null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Run only these tests or cests.')
            ->addOption('skip_tests',          null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'These tests or cests will be skipped.')
            ->addOption('skip_reruns',         null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Do not rerun these tests or cests.')
            ->addOption('run_before_series',   null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'These tests or cests will be run before the main run, in series.')
            ->addOption('rerun_whole_series',  null, InputOption::VALUE_REQUIRED, 'If a test in a serial run is failed - rerun the whole serial run.')
            ->addOption('serial_before_fails_run',  null, InputOption::VALUE_REQUIRED, 'If a serial run is failed even after all reruns - stop execution.')
            ->addOption('run_before_parallel', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'These tests or cests will be run before the main run, in parallel.')
            ->addOption('run_after_series',    null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'These tests or cests will be run after the main run, in series.')
            ->addOption('run_after_parallel',  null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'These tests or cests will be run after the main run, in parallel.')
            ->addOption('cest_wrapper',        null, InputOption::VALUE_REQUIRED, "How to treat cests by default. 'tests' - divide cests into tests (default); 'cest_rerun_whole' - as in option 'not_dividable_rerun_whole'; 'cest_rerun_failed' - as in option 'not_dividable_rerun_failed'.")
            ->addOption('dividable',           null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'These cests should be divided into tests.')
            ->addOption('not_dividable_rerun_whole',   null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'These cests should not be divided. If any test in the cest is failed then the whole cest will be rerunned.')
            ->addOption('not_dividable_rerun_failed',  null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'These cests should not be divided. If a test in the cest is failed then only the failed test will be rerunned.')
            ->addOption('max_rps',             null, InputOption::VALUE_REQUIRED, 'Max allowed RPS. Used in calculation of adaptive delay.')
            ->addOption('bulk_rows_count',     null, InputOption::VALUE_REQUIRED, 'For PostgREST. How many rows insert/get in one request.')
            ->addOption('run_output_path',     null, InputOption::VALUE_REQUIRED, 'Will be used instead the codeception output directory for storing results.')
            ->addOption('no_memory_limit',     null, InputOption::VALUE_REQUIRED, "Executes ini_set('memory_limit', '-1'); for process")
            ->addOption('only_tests_respect_groups', null, InputOption::VALUE_REQUIRED, "only_tests don\'t ignore groups")
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
            $this->overrideSettings($input, 'bulk_rows_count');
            $this->overrideSettings($input, 'run_output_path');
            $this->overrideSettings($input, 'no_memory_limit');
            $this->overrideSettings($input, 'only_tests_respect_groups');

            $this->resolveProjectName();
            $this->resolveAdaptiveDelay();
            $this->resolveRunOutputPath();
            $this->resolveOnlyTestsMode();

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

    protected function resolveOnlyTestsMode() : void
    {
        if (empty($this->settings->getOnlyTests()))
        {
            return;
        }

        if ($this->settings->isOnlyTestsRespectGroups())
        {
            return;
        }

        $this->settings->setGroups([]);
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
