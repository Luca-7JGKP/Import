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
 * @version 1.1.1
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
    public $autoMarkPastEventsRead = true;
    
    /**
     * mark updated events as unread
     * @var bool
     */
    public $markUpdatedAsUnread = true;
    
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
    public function readFormParameters() {
        parent::readFormParameters();
        
        if (isset($_POST['targetImportID'])) $this->targetImportID = intval($_POST['targetImportID']);
        if (isset($_POST['boardID'])) $this->boardID = intval($_POST['boardID']);
        if (isset($_POST['createThreads'])) $this->createThreads = true;
        else $this->createThreads = false;
        if (isset($_POST['convertTimezone'])) $this->convertTimezone = true;
        else $this->convertTimezone = false;
        if (isset($_POST['autoMarkPastEventsRead'])) $this->autoMarkPastEventsRead = true;
        else $this->autoMarkPastEventsRead = false;
        if (isset($_POST['markUpdatedAsUnread'])) $this->markUpdatedAsUnread = true;
        else $this->markUpdatedAsUnread = false;
        if (isset($_POST['maxEvents'])) $this->maxEvents = intval($_POST['maxEvents']);
        if (isset($_POST['logLevel'])) $this->logLevel = StringUtil::trim($_POST['logLevel']);
    }
    
    /**
     * @inheritDoc
     */
    public function validate() {
        parent::validate();
        
        // Validate board ID - simple validation, just check if positive number when threads should be created
        if ($this->createThreads && $this->boardID <= 0) {
            throw new \wcf\system\exception\UserInputException('boardID', 'notValid');
        }
        
        // Validate max events
        if ($this->maxEvents < 1 || $this->maxEvents > 10000) {
            throw new \wcf\system\exception\UserInputException('maxEvents', 'notValid');
        }
        
        // Validate log level
        if (!in_array($this->logLevel, ['error', 'warning', 'info', 'debug'])) {
            throw new \wcf\system\exception\UserInputException('logLevel', 'notValid');
        }
    }
    
    /**
     * @inheritDoc
     */
    public function save() {
        parent::save();
        
        // Save options
        $sql = "UPDATE wcf".WCF_N."_option 
                SET optionValue = ? 
                WHERE optionName = ?";
        $statement = WCF::getDB()->prepareStatement($sql);
        
        $statement->execute([$this->targetImportID, 'calendar_import_target_import_id']);
        $statement->execute([$this->boardID, 'calendar_import_default_board_id']);
        $statement->execute([$this->createThreads ? '1' : '0', 'calendar_import_create_threads']);
        $statement->execute([$this->convertTimezone ? '1' : '0', 'calendar_import_convert_timezone']);
        $statement->execute([$this->autoMarkPastEventsRead ? '1' : '0', 'calendar_import_auto_mark_past_events_read']);
        $statement->execute([$this->markUpdatedAsUnread ? '1' : '0', 'calendar_import_mark_updated_as_unread']);
        $statement->execute([$this->maxEvents, 'calendar_import_max_events']);
        $statement->execute([$this->logLevel, 'calendar_import_log_level']);
        
        $this->saved();
        
        // Show success message
        WCF::getTPL()->assign('success', true);
    }
    
    /**
     * @inheritDoc
     */
    public function readData() {
        parent::readData();
        
        if (empty($_POST)) {
            // Load current values from options
            $this->targetImportID = defined('CALENDAR_IMPORT_TARGET_IMPORT_ID') ? intval(CALENDAR_IMPORT_TARGET_IMPORT_ID) : 0;
            $this->boardID = defined('CALENDAR_IMPORT_DEFAULT_BOARD_ID') ? intval(CALENDAR_IMPORT_DEFAULT_BOARD_ID) : 0;
            $this->createThreads = defined('CALENDAR_IMPORT_CREATE_THREADS') ? (CALENDAR_IMPORT_CREATE_THREADS == 1) : true;
            $this->convertTimezone = defined('CALENDAR_IMPORT_CONVERT_TIMEZONE') ? (CALENDAR_IMPORT_CONVERT_TIMEZONE == 1) : true;
            $this->autoMarkPastEventsRead = defined('CALENDAR_IMPORT_AUTO_MARK_PAST_EVENTS_READ') ? (CALENDAR_IMPORT_AUTO_MARK_PAST_EVENTS_READ == 1) : true;
            $this->markUpdatedAsUnread = defined('CALENDAR_IMPORT_MARK_UPDATED_AS_UNREAD') ? (CALENDAR_IMPORT_MARK_UPDATED_AS_UNREAD == 1) : true;
            $this->maxEvents = defined('CALENDAR_IMPORT_MAX_EVENTS') ? intval(CALENDAR_IMPORT_MAX_EVENTS) : 100;
            $this->logLevel = defined('CALENDAR_IMPORT_LOG_LEVEL') ? CALENDAR_IMPORT_LOG_LEVEL : 'info';
        }
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
            'autoMarkPastEventsRead' => $this->autoMarkPastEventsRead,
            'markUpdatedAsUnread' => $this->markUpdatedAsUnread,
            'maxEvents' => $this->maxEvents,
            'logLevel' => $this->logLevel
        ]);
    }
}
