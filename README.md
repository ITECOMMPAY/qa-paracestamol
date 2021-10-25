Parallel runner for Codeception tests

# Demo

To quickly see Paracestamol in action just: 

1. Clone the repo
2. Do `composer install`
3. Execute `php ./paracestamol run acceptance ./tests/ 4`

# Installation

This utility is written for Unix-like OS and requires PHP 7.4 with json, mbstring and php-ds extensions.

Add the repository and the component to the composer.json of your Codeception tests project

```
{
    "require": {
        "itecommpay/qa-paracestamol": "dev-main"
    },
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/ITECOMMPAY/qa-paracestamol.git"
        }
    ]
}
```
Expect frequent API changes until the first numbered release.

# Usage

From inside your project directory:

```
php ./vendor/bin/paracestamol run suite_name path_to_codeception_yml number_of_processes
```

By default the utility displays progress bars. To get a more verbose output pass to it -v, -vv or -vvv argument.

## Options

If a file with the name `paracestamol.yml` is placed in the same directory as your `codeception.yml` then the following options will be read from it.
The options that are passed as command-line arguments will override the options that are defined in the `paracestamol.yml` file.

Example of the `paracestamol.yml` file:

```
project_name: ParacestamolTests
groups: []
env: []
rerun_count: 2
delay_msec: -1
max_rps: 50
idle_timeout_sec: 30
stat_endpoint: "http://localhost:3000"
show_first_fail: true

skip_tests:
    - SomeCest.php:test02
```

Example of the options overriding:

```
php ./paracestamol run acceptance ./tests/ 10 -v --rerun_count 3 --show_first_fail false
```
### Run

Option | Example | Description
--- | --- | --- 
rerun_count, r | -r 3 | How many times to rerun failed tests
continuous_rerun | --continuous_rerun true | If false — tests will be reran only after the run is finished. If true — failed tests will be added to the end of the run queue.
delay_msec, d | -d 25 | If several tests try to start at the same time then wait the given delay between these starts. 0 — cancels delay. -1 — automatically calculate the delay using the max_rps option. The bigger the delay the less is load on your CPU and application.
max_rps | --max_rps 50 | Used to calculate delay if the delay_msec option is set to -1. delay_msec will be set to 1000/min(max_rps, number_of_processes). Paracestamol can't send more than 100 RPS.
idle_timeout_sec, t | -t 600 | Terminate a test if it takes more than the given time to run
show_first_fail | --show_first_fail true | Show the output of the first failed test
skip_reruns | --skip_reruns SomeOtherCest.php:test02 | Do not rerun these tests (see the "Setting test names" section)
cest_wrapper | --cest_wrapper 'cest_rerun_failed' | How to treat cests by default: <br />'tests' — divide cests into tests (default) <br />'cest_rerun_whole' — as in option 'not_dividable_rerun_whole' <br />'cest_rerun_failed' — as in option 'not_dividable_rerun_failed'
dividable | --dividable SomeCest.php | These cests should be divided into separate tests. This option is active when cest_wrapper is set to non-default value. 
not_dividable_rerun_whole | --not_dividable_rerun_whole NotDividableCest.php | These cests should not be divided into separate tests. If any test in the cest is failed then the whole cest will be reran
not_dividable_rerun_failed | --not_dividable_rerun_failed NotDividableUntilRerunCest.php | These cests should not be divided into separate tests. If a test in the cest is failed then only the failed test will be reran
fast_cest_rerun | --fast_cest_rerun false | If all tests selected for the current run (or a rerun) from a Cest are failed — exclude the Cest from the following reruns. If during a rerun 50% of tests in a Cest from the 'not_dividable_rerun_failed' group are failed — for the next rerun extract these tests from the Cest and divide them among the parallel processes.

### Run stages

Option | Example | Description
--- | --- | --- 
run_before_series | --run_before_series SomeBeforeAfterCest.php:before01 | Run these tests in the given order before the main run
rerun_whole_series | --rerun_whole_series true | If a test from the run_before_series option is failed then rerun all tests from the run_before_series option
serial_before_fails_run | --serial_before_fails_run true | If run_before_series failed even after all reruns then stop the paracestamol execution
run_before_parallel | --run_before_parallel SomeParallelBeforeCest.php | Run these tests in parallel before the main run
run_after_parallel | --run_after_parallel parallelAfter | Run these tests in parallel after the main run
run_after_series | --run_after_series SomeBeforeAfterCest.php:after01 | Run these tests in the given order after all other test runs

### Parsing

Option | Example | Description
--- | --- | --- 
cache_tests | --cache_tests true | Parsing a bunch of big tests can take a long time. Paracestamol can cache a parsing results and use them in the further runs. The caching takes some time too and is not recommended if you don't experience the problem with long parsing times
store_cache_in | --store_cache_in /home/egor | Where to store the parsing cache

### Filtering

Option | Example | Description
--- | --- | --- 
groups, g | -g cat -g dog | Run only tests marked with the given groups
only_tests | --only_tests parallelAfter/SomeParallelAfterCest.php | Run only these tests (see the "Setting test names" section)
skip_tests | --skip_tests SomeCest.php:test02 | Skip these tests

### Environment

Option | Example | Description
--- | --- | --- 
env | --env shuffle,no_lint <br />(will preserve the given order) <br /><br /> --env shuffle --env no_lint <br />(will sort the envs naturally)| Run tests in the selected environment. Note that all env arguments are treated as one environment, the same way if it was merged with ','. Also note that the order in which the environment files are given is NOT preserved when they are given using separate arguments.
override, o | -o "settings: shuffle: true" | Overrides codeception config values
run_output_path | --run_output_path /home/egor/output | Overrides the tests output directory

### Statistics

Option | Example | Description
--- | --- | --- 
stat_endpoint | --stat_endpoint "http://localhost:3000" | See the "Using test duration statistics" section
project_name | --project_name ParacestamolTests | Used for test duration statistics. By default your test suite namespace is used as your test project name. You can override it using this option
bulk_rows_count | --bulk_rows_count 1000 | Used for test duration statistics. How many rows get from the statistics database in a single request.

### Paracestamol
Option | Example | Description
--- | --- | --- 
parac_config | --parac_config /home/egor/testproject/tests <br />(looks for paracestamol.yml in the directory)<br /><br /> --parac_config /home/egor/testproject/tests/paracestamol_fast.yml <br />OR<br /> --parac_config paracestamol_fast.yml <br />(if your paracestamol config stored in the same directory as your codeception.yml but have a non-default name) | If your paracestamol.yml is stored in the different directory than your codeception.yml or have a non-default name set the path to it using this option
no_memory_limit | --no_memory_limit true | Tries to turn off PHP memory_limit (better raise it in your PHP settings instead)

## Setting test names

Paracestamol uses the further naming convention in its config.

* parallelAfter — means all tests in parallelAfter subdirectory (relative to your tests directory)
* parallelAfter/SomeParallelAfterCest.php — means all tests in SomeParallelAfterCest.php (note .php at the end)
* parallelAfter/SomeParallelAfterCest.php:parallelAfter01 — means only this one test

## Using test duration statistics

Imagine that you have 4 tests:
* test1 — takes 18 minutes to run
* test2 — takes 12 minutes to run
* test3 — takes 5 minutes to run
* test4 — takes 1 minute to run

If you try to run these tests in 2 parallel processes then by default Paracestamol will divide them in the given order.
I.e. the first process will run test1 and test2 and take 30 minutes to run, while the second process will run test3 and test4 and take 6 minutes to run.

But if Paracestamol knew the duration of these tests it could run test1 in the first process and the other tests in the second process. This way both processes could take only 18 minutes to run.

To enable this feature you should do the following:
1. Install PostgreSQL
2. Create Paracestamol schema using the `create_statistics_schema.php` script (stored in the same folder as this README file)
3. Install PostgREST (https://postgrest.org/) and start it with the created schema
4. Pass the PostgREST endpoint to Paracestamol using the `stat_endpoint` option

## Codeception module

To allow paracestamol to enforce a delay between tests of a not dividable Cest (cest_rerun_whole or cest_rerun_failed) you should enable the ParacestamolHelper module in your project config:

```
modules:
    enabled:
        - Paracestamol\Module\ParacestamolHelper
```
