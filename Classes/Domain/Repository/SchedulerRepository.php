<?php
namespace Cobweb\ExternalImport\Domain\Repository;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use Cobweb\ExternalImport\Task\AutomatedSyncTask;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3\CMS\Scheduler\CronCommand\NormalizeCommand;
use TYPO3\CMS\Scheduler\Scheduler;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

/**
 * Pseudo-repository class for Scheduler tasks
 *
 * This is not a true repository from an Extbase point of view. It implements only a few features of a complete repository.
 *
 * @author Francois Suter (Cobweb) <typo3@cobweb.ch>
 * @package TYPO3
 * @subpackage tx_externalimport
 */
class SchedulerRepository implements SingletonInterface
{
    /**
     * @var string Name of the related task class
     */
    static public $taskClassName = AutomatedSyncTask::class;

    /**
     * List of all tasks (stored locally in case the repository is called several times)
     *
     * @var array
     */
    protected $tasks = array();

    /**
     * Local instance of the scheduler object
     *
     * @var Scheduler
     */
    protected $scheduler;

    public function __construct()
    {
        $this->scheduler = GeneralUtility::makeInstance(Scheduler::class);
        $allTasks = $this->scheduler->fetchTasksWithCondition('', true);
        /** @var $aTaskObject AbstractTask */
        foreach ($allTasks as $aTaskObject) {
            if (get_class($aTaskObject) === self::$taskClassName) {
                $this->tasks[] = $aTaskObject;
            }
        }
    }

    /**
     * Fetches all tasks related to the external import extension
     * The return array is structured per table/index
     *
     * @return array List of registered events/tasks, per table and index
     */
    public function fetchAllTasks()
    {
        $taskList = array();
        /** @var $taskObject AutomatedSyncTask */
        foreach ($this->tasks as $taskObject) {
            $key = $taskObject->table . '/' . $taskObject->index;
            $taskList[$key] = $this->assembleTaskInformation($taskObject);
        }
        return $taskList;
    }

    /**
     * Retrieves a scheduler task based on its id.
     *
     * @param int $uid Id of the task to retrieve
     * @throws \InvalidArgumentException
     * @return array
     */
    public function fetchTaskByUid($uid)
    {
        $uid = (int)$uid;
        /** @var $taskObject AutomatedSyncTask */
        foreach ($this->tasks as $taskObject) {
            if ($taskObject->getTaskUid() === $uid) {
                return $this->assembleTaskInformation($taskObject);
            }
        }
        // We didn't find a matching task, throw an exception
        throw new \InvalidArgumentException(
                'The chosen task could not be found',
                1463732926
        );
    }

    /**
     * Fetches the specific task that synchronizes all tables.
     *
     * @throws \InvalidArgumentException
     * @return array Information about the task, if defined
     */
    public function fetchFullSynchronizationTask()
    {
        // Check all tasks object to find the one with the "all" keyword as a table
        /** @var $taskObject AutomatedSyncTask */
        foreach ($this->tasks as $taskObject) {
            if ($taskObject->table === 'all') {
                return $this->assembleTaskInformation($taskObject);
            }
        }
        throw new \InvalidArgumentException(
                'No task registered for full synchronization',
                1337344319
        );
    }

    /**
     * Returns the list of all scheduler task groups.
     *
     * @return array
     */
    public function fetchAllGroups()
    {
        $groups = array(
            0 => ''
        );
        try {
            $rows = $this->getDatabaseConnection()->exec_SELECTgetRows(
                    'uid, groupName',
                    'tx_scheduler_task_group',
                    '1 = 1' . BackendUtility::deleteClause('tx_scheduler_task_group') . BackendUtility::BEenableFields('tx_scheduler_task_group'),
                    '',
                    'groupName'
            );
            foreach ($rows as $row) {
                $groups[$row['uid']] = $row['groupName'];
            }
        }
        catch (\Exception $e) {
            // Nothing to do, let an empty groups list be returned
        }
        return $groups;
    }

    /**
     * Grabs the information about a given external import task and stores it into an array
     *
     * @param AutomatedSyncTask $taskObject The task to handle
     * @return array The information about the task
     */
    protected function assembleTaskInformation(AutomatedSyncTask $taskObject)
    {
        $cronCommand = $taskObject->getExecution()->getCronCmd();
        $interval = $taskObject->getExecution()->getInterval();
        $displayFormat = $GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy'] . ' ' . $GLOBALS['TYPO3_CONF_VARS']['SYS']['hhmm'];
        $editFormat = $GLOBALS['TYPO3_CONF_VARS']['SYS']['USdateFormat'] ? 'H:i m-d-Y' : 'H:i d-m-Y';

        $startTimestamp = $taskObject->getExecution()->getStart();
        $taskInformation = array(
                'uid' => $taskObject->getTaskUid(),
                'table' => $taskObject->table,
                'index' => $taskObject->index,
                // Format date as needed for display
                'nextexecution' => date($displayFormat, $taskObject->getExecutionTime()),
                'interval' => $interval,
                'croncmd' => $cronCommand,
                'frequency' => ($cronCommand !== '') ? $cronCommand : $interval,
                'frequencyText' => ($cronCommand !== '') ? $cronCommand : LocalizationUtility::translate(
                                        'number_of_seconds',
                                        'external_import',
                                        array($interval)
                                ),
                'group' => $taskObject->getTaskGroup(),
                // Format date and time as needed for form input
                'startTimestamp' => $startTimestamp,
                'startDate' => ($startTimestamp === 0) ? '' : date($editFormat, $taskObject->getExecution()->getStart())
        );
        return $taskInformation;
    }

    /**
     * Saves or updates a given task.
     *
     * If no uid is given, a new task is created.
     *
     * @param array $taskData List of fields to save. Must include "uid" for an existing registered task
     * @return boolean True or false depending on success or failure of action
     */
    public function saveTask($taskData)
    {
        if ($taskData['uid'] === 0) {
            // Create a new task instance and register the execution
            /** @var $task AutomatedSyncTask */
            $task = GeneralUtility::makeInstance(self::$taskClassName);
            $task->registerRecurringExecution(
                    $taskData['start'],
                    $taskData['interval'],
                    0,
                    false,
                    $taskData['croncmd']
            );
            // Set the data specific to external import
            $task->table = $taskData['table'];
            $task->index = $taskData['index'];
            $task->setTaskGroup($taskData['group']);
            $result = $this->scheduler->addTask($task);
        } else {
            $task = $this->scheduler->fetchTask($taskData['uid']);
            // Stop any existing execution(s)...
            $task->stop();
            /// ...and replace it(them) by a new one
            $task->registerRecurringExecution(
                    $taskData['start'],
                    $taskData['interval'],
                    0,
                    false,
                    $taskData['croncmd']);
            $task->setTaskGroup($taskData['group']);
            $result = $task->save();
        }
        return $result;
    }

    /**
     * Removes the registration of a given task.
     *
     * @param integer $uid Primary key of the task to remove
     * @return boolean True or false depending on success or failure of action
     */
    public function deleteTask($uid)
    {
        $result = false;
        $uid = (int)$uid;
        if ($uid > 0) {
            $task = $this->scheduler->fetchTask($uid);
            // Stop any existing execution(s) and save
            $result = $this->scheduler->removeTask($task);
        }
        return $result;
    }

    /**
     * Prepares the arguments as proper data for a scheduler task.
     *
     * @param string $frequency Automation frequency
     * @param int $group Scheduler task group
     * @param int $startDate Automation start date
     * @param string $table Name of the table for which to set an automated task for
     * @param string $index Index for which to set an automated task for
     * @param int $uid Id of an existing task (will be 0 for a new task)
     * @return array
     */
    public function prepareTaskData($frequency, $group, $startDate, $table = '', $index = '', $uid = 0)
    {
        // Assemble base data
        $taskData = array(
            'uid' => (int)$uid,
            'table' => $table,
            'index' => $index,
            'group' => (int)$group,
            'start' => (int)$startDate,
            'interval' => 0,
            'croncmd' => ''
        );
        // Handle frequency, which may be a simple number of seconds or a cron command
        // Try interpreting the frequency as a cron command
        try {
            NormalizeCommand::normalize($frequency);
            $taskData['croncmd'] = $frequency;
        }
        // If the cron command was invalid, we may still have a valid frequency in seconds
        catch (\Exception $e) {
            // Check if the frequency is a valid number
            // If yes, assume it is a frequency in seconds
            if (is_numeric($frequency)) {
                $taskData['interval'] = (int)$frequency;
            }
        }
        return $taskData;
    }

    /**
     * Returns the global database connection object.
     *
     * @return \TYPO3\CMS\Core\Database\DatabaseConnection
     */
    protected function getDatabaseConnection()
    {
        return $GLOBALS['TYPO3_DB'];
    }
}
