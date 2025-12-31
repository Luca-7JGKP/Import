<?php
namespace wcf\acp\form;

use wcf\form\AbstractForm;
use wcf\system\WCF;
use wcf\util\StringUtil;

/**
 * Shows the calendar tracking settings form.
 * 
 * @author  Luca Berwind
 * @package com.lucaberwind.wcf.calendar.import
 * @version 1.3.1
 */
class CalendarImportSettingsForm extends AbstractForm {
    /**
     * @inheritDoc
     */
    public $activeMenuItem = 'wcf.acp.menu.link.calendar.import';
    
    /**
     * @inheritDoc
     */
    public $neededPermissions = [];
    
    /**
     * target import ID from calendar1_event_import table
     * @var int
     */
    public $targetImportID = 0;
    
    /**
     * board ID for new threads
     * @var int
     */
    public $boardID = 0;
    
    /**
     * create threads automatically
     * @var bool
     */
    public $createThreads = true;
    
    /**
     * convert timezone
     * @var bool
     */
    public $convertTimezone = true;
    
    /**
     * auto mark past events as read
     * @var bool
     */
    public $autoMarkPastRead = true;
    
    /**
     * mark updated events as unread
     * @var bool
     */
    public $markUpdatedUnread = true;
    
    /**
     * maximum events per import
     * @var int
     */
    public $maxEvents = 100;
    
    /**
     * log level
     * @var string
     */
    public $logLevel = 'info';
    
    /**
     * @inheritDoc
     */
    public function readData() {
        parent::readData();
        
        // Load existing values from options
        if (empty($_POST)) {
            if (defined('CALENDAR_IMPORT_TARGET_IMPORT_ID')) {
                $this->targetImportID = CALENDAR_IMPORT_TARGET_IMPORT_ID;
            }
            if (defined('CALENDAR_IMPORT_DEFAULT_BOARD_ID')) {
                $this->boardID = CALENDAR_IMPORT_DEFAULT_BOARD_ID;
            }
            if (defined('CALENDAR_IMPORT_CREATE_THREADS')) {
                $this->createThreads = (bool)CALENDAR_IMPORT_CREATE_THREADS;
            }
            if (defined('CALENDAR_IMPORT_CONVERT_TIMEZONE')) {
                $this->convertTimezone = (bool)CALENDAR_IMPORT_CONVERT_TIMEZONE;
            }
            if (defined('CALENDAR_IMPORT_AUTO_MARK_PAST_READ')) {
                $this->autoMarkPastRead = (bool)CALENDAR_IMPORT_AUTO_MARK_PAST_READ;
            }
            if (defined('CALENDAR_IMPORT_MARK_UPDATED_UNREAD')) {
                $this->markUpdatedUnread = (bool)CALENDAR_IMPORT_MARK_UPDATED_UNREAD;
            }
            if (defined('CALENDAR_IMPORT_MAX_EVENTS')) {
                $this->maxEvents = CALENDAR_IMPORT_MAX_EVENTS;
            }
            if (defined('CALENDAR_IMPORT_LOG_LEVEL')) {
                $this->logLevel = CALENDAR_IMPORT_LOG_LEVEL;
            }
        }
    }
    
    /**
     * @inheritDoc
     */
    public function readFormParameters() {
        parent::readFormParameters();
        
        if (isset($_POST['targetImportID'])) $this->targetImportID = intval($_POST['targetImportID']);
        if (isset($_POST['boardID'])) $this->boardID = intval($_POST['boardID']);
        $this->createThreads = isset($_POST['createThreads']);
        $this->convertTimezone = isset($_POST['convertTimezone']);
        $this->autoMarkPastRead = isset($_POST['autoMarkPastRead']);
        $this->markUpdatedUnread = isset($_POST['markUpdatedUnread']);
        if (isset($_POST['maxEvents'])) $this->maxEvents = intval($_POST['maxEvents']);
        if (isset($_POST['logLevel'])) $this->logLevel = StringUtil::trim($_POST['logLevel']);
    }
    
    /**
     * @inheritDoc
     */
    public function validate() {
        parent::validate();
        
        if ($this->createThreads && $this->boardID <= 0) {
            throw new \wcf\system\exception\UserInputException('boardID', 'notValid');
        }
        
        if ($this->maxEvents < 1 || $this->maxEvents > 10000) {
            throw new \wcf\system\exception\UserInputException('maxEvents', 'notValid');
        }
        
        $validLogLevels = ['error', 'warning', 'info', 'debug'];
        if (!in_array($this->logLevel, $validLogLevels)) {
            throw new \wcf\system\exception\UserInputException('logLevel', 'notValid');
        }
    }
    
    /**
     * @inheritDoc
     */
    public function save() {
        parent::save();
        
        $this->updateOption('calendar_import_target_import_id', $this->targetImportID);
        $this->updateOption('calendar_import_default_board_id', $this->boardID);
        $this->updateOption('calendar_import_create_threads', $this->createThreads ? 1 : 0);
        $this->updateOption('calendar_import_convert_timezone', $this->convertTimezone ? 1 : 0);
        $this->updateOption('calendar_import_auto_mark_past_read', $this->autoMarkPastRead ? 1 : 0);
        $this->updateOption('calendar_import_mark_updated_unread', $this->markUpdatedUnread ? 1 : 0);
        $this->updateOption('calendar_import_max_events', $this->maxEvents);
        $this->updateOption('calendar_import_log_level', $this->logLevel);
        
        \wcf\system\cache\builder\OptionCacheBuilder::getInstance()->reset();
        
        $this->saved();
        
        WCF::getTPL()->assign('success', true);
    }
    
    /**
     * Updates an option value in the database.
     */
    protected function updateOption($optionName, $optionValue) {
        $sql = "UPDATE wcf".WCF_N."_option SET optionValue = ? WHERE optionName = ?";
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute([$optionValue, $optionName]);
    }
    
    /**
     * @inheritDoc
     */
    public function assignVariables() {
        parent::assignVariables();
        
        WCF::getTPL()->assign([
            'targetImportID' => $this->targetImportID,
            'boardID' => $this->boardID,
            'createThreads' => $this->createThreads,
            'convertTimezone' => $this->convertTimezone,
            'autoMarkPastRead' => $this->autoMarkPastRead,
            'markUpdatedUnread' => $this->markUpdatedUnread,
            'maxEvents' => $this->maxEvents,
            'logLevel' => $this->logLevel
        ]);
    }
}
