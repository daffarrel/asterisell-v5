<?php

// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2009-2019 Massimo Zaniboni <massimo.zaniboni@asterisell.com>

sfLoader::loadHelpers(array('I18N', 'Debug', 'Date', 'Asterisell'));

/**
 * Process all queued jobs.
 *
 * A Job Queue allows to execute Jobs in a separate way respect the direct execution inside the web-server session.
 *
 * This increase the efficiency and robustness of the application, because an off-line work has
 * no the memory and CPU time constraints.
 *
 * Another advantage is that the system is like a blackboard, where developers can add new Jobs.
 * Every Job is activated when it recognize a certain event and it can generate other events.
 *
 */
class JobQueueProcessor
{

    /**
     * @var bool true for showing the status of jobs
     */
    static $IS_INTERACTIVE = false;

    static public function setIsInteractive($v)
    {
        self::$IS_INTERACTIVE = $v;
    }

    //////////////////
    // MANAGE MUTEX //
    //////////////////

    const MUTEX_FILE_NAME = "jobqueueprocessor";

    /**
     * @var Mutex|null
     */
    protected $mutex = NULL;

    /**
     * Try acquiring a lock. This can be used internally or externally.
     * Call `unlock` at the end.
     *
     * @return bool TRUE if the lock was acquired.
     */
    public function lock()
    {

        // Only one processor can execute jobs because they can change the
        // external environment.
        // In any case if there is another job processor running, then
        // current jobs will be executed in any case so it is not a problem.
        $mutex = new Mutex(JobQueueProcessor::MUTEX_FILE_NAME);
        $acquired = $mutex->maybeLock();

        if ($acquired) {
            $this->mutex = $mutex;
        }

        return $acquired;
    }

    public function unlock()
    {
        if (!is_null($this->mutex)) {
            $this->mutex->unlock();
        }
    }

    //////////////////
    // PROCESS JOBS //
    //////////////////

    /**
     * Execute all pending jobs.
     *
     * The worklflow is:
     * - start with upgrading jobs, if they exists
     * - execute fixed jobs
     * - execute event jobs
     * - if event jobs fire new events keep executing them, until there are no any more events
     * - if event jobs were executed at least once, then restart the loop with fixed jobs
     *
     * @param bool $isInteractive true for showing debug messages
     * @param bool $isDebugMode true for enabling debug mode
     * @return bool|null TRUE if it is all OK, FALSE if there are problems,
     * NULL if the job queue processor is already locked.
     */
    public function process($isInteractive = false, $isDebugMode = false)
    {
        $isLocked = $this->lock();

        // another job-queue-processor is running
        if (!$isLocked) return NULL;

        $result = FALSE;
        try {
            self::$IS_INTERACTIVE = $isInteractive;
            ArProblemException::beginLogTransaction();
            try {
                $this->areThereAbortedJobs($isInteractive);
                $result = $this->inner_process($isInteractive, $isDebugMode);
            } finally {
                ArProblemException::commitLogTransaction();
            }
        } finally {
            $this->unlock();
        }
        return $result;
    }

    /**
     * @param bool $isInteractive true for showing debug messages
     * @param bool $isDebugMode true for running in slow debug mode
     * @return bool|null TRUE if it is all OK, FALSE if there are problems,
     * NULL if the job queue processor is already locked.
     */
    protected function inner_process($isInteractive = false, $isDebugMode = false)
    {
        if (self::checkIfThereIsPendingUpgradeJobs()) {
            $problemDuplicationKey = get_class($this) . " - not upgraded application";
            $problemDescription = "There are pending (not executed) application upgrade jobs.";
            $problemEffect = "The application will not process any new job, because application code can be not aligned with changes in database schema.";
            $problemSolution = "Try to delete error messages. If this problem appears again, then contact the assistance, because there is an error in the upgrading code.";
            ArProblemException::createWithoutGarbageCollection(
                ArProblemType::TYPE_CRITICAL,
                ArProblemDomain::APPLICATION,
                null,
                $problemDuplicationKey, $problemDescription, $problemEffect, $problemSolution);

            if ($isInteractive) {
                $this->displayError(ArProblemException::getLastErrorDescription(), ArProblemException::getLastErrorEffect(), ArProblemException::getLastErrorSolution());
            }

            return false;
            //upgrade procedure is now managed explicitely from management utility
        }


        //
        // Execute All Jobs, until completitions of Events
        //

        $allOk = TRUE;

        $fixedJobs = sfConfig::get('app_available_always_scheduled_jobs');
        $eventJobs = sfConfig::get('app_available_jobs');

        // this is a complete loop:
        // * first fixed jobs
        // * then event jobs, until there are no new added events
        // * then execute again a loop if there were some processing
        $executeFixedAndEventJobs = true;
        while ($executeFixedAndEventJobs) {

            // Execute "always_scheduled_jobs" following their order of declaration.
            foreach ($fixedJobs as $jobClass) {
                $jobData = new NullJobData();
                $jobLog = ArJobQueuePeer::addNewWithStateAndDescription($jobData, NULL, ArJobQueue::RUNNING, $jobClass);

                try {
                    $msg = $this->executeJob($jobClass, $isInteractive, $isDebugMode);
                    $jobLog->complete(ArJobQueue::DONE, $jobLog->getDescription() . ": " . $msg);
                } catch (Exception $e) {
                    $allOk = FALSE;
                    if (!is_null($jobLog)) {
                        $jobLog->complete(ArJobQueue::ERROR);
                    }

                    if ($e instanceof ArProblemException) {
                        // already inserted on problem table
                    } else {
                        $problemDuplicationKey = "FixedJobs Processor " . $jobClass;
                        $problemDescription = "Error during the execution of always_scheduled_job $jobClass . The error message is: " . $e->getMessage() . ". Stack trace: " . $e->getTraceAsString();
                        $problemEffect = "This error prevent the execution of the specified jobs, but not the execution of other always-scheduled and normal jobs.";
                        $problemProposedSolution = "Fix the problem. If you change the configuration file, you should probably re-rate all previous calls in order to back-propagate changes.";
                        ArProblemException::createWithoutGarbageCollection(
                            ArProblemType::TYPE_ERROR,
                            ArProblemDomain::APPLICATION,
                            null,
                            $problemDuplicationKey, $problemDescription, $problemEffect, $problemProposedSolution);
                    }

                    if ($isInteractive) {
                        $this->displayError(ArProblemException::getLastErrorDescription(), ArProblemException::getLastErrorEffect(), ArProblemException::getLastErrorSolution());
                    }
                }
            }

            // Process Jobs with Events

            $again = true;
            $executeFixedAndEventJobs = false;
            while ($again) {
                $jobEntry = ArJobQueuePeer::getFirstJobInTheQueue();
                // start the job if exists

                if (is_null($jobEntry)) {
                    $again = false;
                } else {
                    // if there is at least an event to process, then execute also fixed jobs
                    $executeFixedAndEventJobs = true;

                    $eventDescription = $jobEntry->getDescription();

                    try {
                        $jobEntryData = $jobEntry->unserializeDataJob();
                        $eventDescription .= " (Jobs executed for event \"" . get_class($jobEntryData) . "\":";

                        foreach ($eventJobs as $processorClass) {
                            /**
                             * @var JobProcessor $process
                             */
                            $process = new $processorClass();
                            $process->setDebugMode($isDebugMode);

                            if ($isInteractive) {
                                echo "\nStart job: " . get_class($process);
                            }

                            $eventLog = $process->processEvent($jobEntryData, $jobEntry->getId());
                            if ($eventLog === TRUE || (!is_null($eventLog))) {
                                $eventDescription .= "\n" . get_class($process) . ': ' . $eventLog;
                            }
                            self::checkOfNestedTransactions(get_class($process), $isInteractive);
                        }

                        $eventDescription .= ')';

                        $jobEntry->complete(ArJobQueue::DONE, $eventDescription);

                    } catch (Exception $e) {
                        $allOk = FALSE;

                        $eventDescription .= ')';

                        $jobEntry->complete(ArJobQueue::ERROR, $eventDescription);

                        if ($e instanceof ArProblemException) {
                        } else {
                            $problemDuplicationKey = 'job ' . $jobEntry->getId() . ' - ' . $e->getCode();
                            $problemDescription = "Error on job " . $jobEntry->getId() . ": " . $e->getCode() . ' - ' . $e->getMessage();
                            ArProblemException::createWithoutGarbageCollection(
                                ArProblemType::TYPE_ERROR,
                                ArProblemDomain::APPLICATION,
                                null,
                                $problemDuplicationKey, $problemDescription, '', '');
                        }

                        if ($isInteractive) {
                            $this->displayError(ArProblemException::getLastErrorDescription(), ArProblemException::getLastErrorEffect(), ArProblemException::getLastErrorSolution());
                        }
                    }
                }
            }
        }

        return $allOk;
    }

    /**
     * @param string $jobClass
     * @param bool $isInteractive
     * @param bool $isDebugMode
     * @return string
     */
    public function executeJob($jobClass, $isInteractive = FALSE, $isDebugMode = FALSE)
    {
        /**
         * @var FixedJobProcessor $job
         */
        $job = new $jobClass();
        $job->setDebugMode($isDebugMode);

        if ($isInteractive) {
            echo "\nStart job: " . get_class($job);
        }

        $msg = $job->process();
        self::checkOfNestedTransactions($jobClass, $isInteractive);

        return $msg;
    }

    /**
     * Echo/display the list of scheduled jobs, with development notes.
     */
    public function displayJobsWithDevNotes()
    {

        $fixedJobs = sfConfig::get('app_available_always_scheduled_jobs');
        $eventJobs = sfConfig::get('app_available_jobs');

        $i = 0;
        foreach ($fixedJobs as $jobClass) {
            $i++;

            echo "\n# $i) Always Scheduled Job: $jobClass\n";

            /**
             * @var FixedJobProcessor $job
             */
            $job = new $jobClass();

            $n = $job->getDevNotes();
            if (!is_null($n)) {
                echo "\n## Job Development Notes\n";
                echo $n;
                echo "\n";
            }

        }

        foreach ($eventJobs as $jobClass) {
            $i++;

            echo "\n# $i) Scheduled by Event Job: $jobClass\n";

            /**
             * @var FixedJobProcessor $job
             */
            $job = new $jobClass();

            $n = $job->getDevNotes();
            if (!is_null($n)) {
                echo "\n## Job Development Notes\n";
                echo $n;
                echo "\n";
            }
        }
    }

/////////////////////////////////
// MANAGE APPLICATION UPGRADES //
/////////////////////////////////

    /**
     * Add initial configurations to the database.
     *
     * @param bool $isInteractive
     */
    public static function applyInitialConfigurationJobsToTheDatabase($isInteractive)
    {
        self::$IS_INTERACTIVE = $isInteractive;
        self::upgradeApplicationWithOptions(true, false, true, true);
    }

    /**
     * Apply administration jobs, using different options.
     *
     * @param bool $isInteractive
     * @param bool $isDBUpgrade
     * @return bool true if upgrade was applied with success.
     */
    public static function applyNewUpgradingJobs($isInteractive, $isDBUpgrade)
    {
        self::$IS_INTERACTIVE = $isInteractive;
        if (self::checkIfThereIsPendingUpgradeJobs()) {
            try {
                self::upgradeApplicationWithOptions(false, true, true, true, $isDBUpgrade);
            } catch (ArProblemException $e) {
                return false;
            }
        } else {
            return true;
        }
    }

    /**
     * @return bool TRUE if there were pending upgrade commands
     */
    public static function checkIfThereIsPendingUpgradeJobs()
    {
        list($jobs1, $jobs2) = self::upgradeApplicationWithOptions(false, true, false, false);
        if ($jobs1 > 0) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Mark administration jobs as applied, without executing them.
     */
    public static function considerUpgradingJobsAsAlreadyAppliedWithoutExecutingThem($isInteractive)
    {
        self::$IS_INTERACTIVE = $isInteractive;
        self::upgradeApplicationWithOptions(false, false, false, true);
    }

    /**
     * Apply administration jobs, using different options.
     *
     * @param bool $executeConfigure TRUE for executing configure commands, FALSE for executing upgrading commands
     * @param bool $findNewCommands TRUE for applying only not already applied commands
     * @param bool $applyCommands TRUE for applying commands to SQL database
     * @param bool $storeCommands TRUE for storing in upgrade table the commands
     * @param bool|null $isDBUpgrade null for applying all jobs, true for applying only db upgrade jobs, false for applying only no-db upgrade jobs
     * @return array list(int, int) the number of upgrade jobs applied/applicable. The first is the number of total jobs, the second of jobs involving the CDR table.
     * @throws ArProblemException the error is added also to the problem table, and then throw
     */
    static function upgradeApplicationWithOptions($executeConfigure, $findNewCommands, $applyCommands, $storeCommands, $isDBUpgrade = null)
    {
        $result1 = 0;
        $result2 = 0;

        // retrieve list of commands

        $jobList = 'app_available_';
        if ($executeConfigure) {
            $jobList .= 'configure_jobs';
        } else {
            $jobList .= 'upgrade_jobs';
        }

        $jobs = sfConfig::get($jobList);

        // scan commands
        try {
            foreach ($jobs as $jobClass) {
                /**
                 * @var AdminJobProcessor $job
                 */
                $job = new $jobClass();
                $key = $job->getUpgradeKey();

                try {

                    if ($findNewCommands) {
                        $canBeApplied = !self::isAppliedUpgradeCommand($key);
                    } else {
                        $canBeApplied = true;
                    }

                    $isPostPoned = false;
                    if ($canBeApplied) {
                        if ((!is_null($isDBUpgrade)) && ($job->isDBUpgradeJob() !== $isDBUpgrade)) {
                            $canBeApplied = false;
                            $isPostPoned = true;
                        }
                    }

                    if ($applyCommands && $canBeApplied) {
                        $mustBeApplied = true;
                    } else {
                        $mustBeApplied = false;
                    }

                    if ($canBeApplied) {
                        $result1++;
                        if ($job->isCDRTableModified()) {
                            $result2++;
                        }
                    }

                    $msg = '';
                    if ($mustBeApplied) {
                        if (self::$IS_INTERACTIVE) {
                            echo "\nExecute application upgrading job: " . get_class($job) . ' - ' . $job->getUpgradeKey();
                        }

                        $msg = $job->process();
                        self::checkOfNestedTransactions(get_class($job), true);

                        echo "\n\n" . $msg;
                    }

                    if ($storeCommands && (!$isPostPoned)) {
                        self::markUpgradeCommand($key, $msg);
                    }

                } catch (Exception $e) {
                    $msg = 'Interrupt from error: ' . $e->getMessage();

                    // do not repeat the command again
                    if ($storeCommands) {
                        self::markUpgradeCommand($key, $msg);
                    }

                    // prevent running of other jobs
                    AsterisellUser::lockCronForMaintanance();

                    $problemDuplicationKey = 'error during ' . $jobList . ' ' . $jobClass;

                    if ($e instanceof ArProblemException) {
                        $problemDescription = "Error during upgrading job $jobList $jobClass: " . ArProblemException::getLastErrorDescription() . " - EFFECT: " . ArProblemException::getLastErrorEffect() . ' - SOLUTION: ' . ArProblemException::getLastErrorSolution() . ' - TRACE: ' . $e->getTraceAsString();;
                    } else {
                        $problemDescription = "Error during upgrading job $jobList $jobClass :" . $e->getCode() . ' - ' . $e->getMessage() . ' - ' . $e->getTraceAsString();
                    }

                    $problemEffect = 'The application is not correctly upgraded to the last version. It can have an unexpected behavior. This upgrade command will be marked as correctly executed, also if terminated with errors, in order to prevent further errors, applying two times, partial commands. The application cron-job is disabled by default, for preventing jobs running on a missconfigured instance.';
                    $problemSolution = 'Contact the assistance, because this is an error in the code.';
                    ArProblemException::createWithoutGarbageCollection(
                        ArProblemType::TYPE_CRITICAL,
                        ArProblemDomain::APPLICATION,
                        ArProblemResponsible::APPLICATION_ASSISTANCE,
                        $problemDuplicationKey, $problemDescription, $problemEffect, $problemSolution);

                    if (self::$IS_INTERACTIVE) {
                        self::displayError($problemDescription, $problemEffect, $problemSolution);
                    }
                }
            }
        } catch (ArProblemException $e) {
            throw($e);
        }

        return array($result1, $result2);
    }

    /**
     * @param string $key
     * @param string $msg
     */
    static function markUpgradeCommand($key, $msg)
    {
        if (!self::isAppliedUpgradeCommand($key)) {
            $upg = new ArApplicationUpgrade();
            $upg->setUpgKey($key);
            $upg->setInstallationDate(time());
            $upg->setUpgOutput($msg);
            $upg->save();
        }
    }

    /**
     * @param string $key
     * @return bool true if the command was already applied
     */
    static function isAppliedUpgradeCommand($key)
    {
        $c = new Criteria();
        $c->add(ArApplicationUpgradePeer::UPG_KEY, $key);
        $rs = ArApplicationUpgradePeer::doSelectOne($c);
        return !is_null($rs);
    }

    /////////////////////////////
    // CHECK AND MANAGE ERRORS //
    /////////////////////////////

    /**
     * Signal if there are aborted jobs.
     *
     * @param bool $isInteractive
     * @return bool TRUE if there are aborted jobs.
     */
    protected function areThereAbortedJobs($isInteractive)
    {
        $jobs = ArJobQueuePeer::getRunningJobs();

        // Set PEDING JOBS to ERROR
        $signalProblem = FALSE;
        foreach ($jobs as $job) {
            $signalProblem = TRUE;

            /**
             * @var ArJobQueue $job
             */
            $job->complete(ArJobQueue::ERROR, $job->getDescription() . ": an uncaught fatal error interrupted this job.");
        }

        // Signal the problem in the problem table
        //
        if ($signalProblem == TRUE) {
            $problemDuplicationKey = "AdviseIfThereAreUnprocessedJobs run";
            $problemDescription = "There are jobs causing a severe/fatal PHP error. These jobs are listed in JobLog as ERROR.";
            $problemEffect = "The job uncaught exception/fatal error, block the execution of this jobs, but also the execution of all following jobs. This halt the normal behaviour of the system.";
            $problemProposedSolution = 'Check the problem table. If there are no hints about the problem, contact the assistance.';
            ArProblemException::createWithoutGarbageCollection(
                ArProblemType::TYPE_CRITICAL,
                ArProblemDomain::APPLICATION,
                null,
                $problemDuplicationKey, $problemDescription, $problemEffect, $problemProposedSolution);

            if ($isInteractive) {
                $this->displayError($problemDescription, $problemEffect, $problemProposedSolution);
            }
        }

        return $signalProblem;
    }

    /**
     * @param string $className
     * @param bool $isInteractive
     */
    static protected function checkOfNestedTransactions($className, $isInteractive)
    {
        /**
         * @var PropelPDO $conn
         */
        $conn = Propel::getConnection();

        if ($conn instanceof PropelPDO) {

            if ($conn->getNestedTransactionCount() != 0) {
                $problemDuplicationKey = "Job with not closed transaction " . $className;
                $problemDescription = "Error during the execution of job \"" . $className . "\". The job does not manage nested transactions in the expected way.";
                $problemEffect = "All the behavior of this job and other jobs, is compromised, because there are is no proper management of nested transactions.";
                $problemProposedSolution = "Contact the assistance.";
                ArProblemException::createWithoutGarbageCollection(
                    ArProblemType::TYPE_CRITICAL,
                    ArProblemDomain::APPLICATION,
                    null,
                    $problemDuplicationKey, $problemDescription, $problemEffect, $problemProposedSolution);

                if ($isInteractive) {
                    self::displayError($problemDescription, $problemEffect, $problemProposedSolution);
                }
            }
        }
    }

    protected static function displayError($problemDescription, $problemEffect, $problemProposedSolution)
    {
        echo "\n   Unexpected error:";
        echo "\n     description: $problemDescription";
        echo "\n     effect: $problemEffect";
        echo "\n     proposed solution: $problemProposedSolution";
    }


}
