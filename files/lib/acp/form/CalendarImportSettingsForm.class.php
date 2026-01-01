<?php

namespace wcf\acp\form;

use wcf\data\calendar\Calendar;
use wcf\data\calendar\CalendarList;
use wcf\form\AbstractForm;
use wcf\system\exception\UserInputException;
use wcf\system\WCF;
use wcf\util\StringUtil;

/**
 * Calendar Import Settings Form
 * 
 * @author      Luca
 * @copyright   2023-2026 Luca
 * @license     GNU Lesser General Public License v2.1
 * @version     1.6.1
 */
class CalendarImportSettingsForm extends AbstractForm {
    /**
     * @inheritDoc
     */
    public $activeMenuItem = 'wcf.acp.menu.link.calendar.import';

    /**
     * @inheritDoc
     */
    public $neededPermissions = ['admin.calendar.canManageCalendar'];

    /**
     * @var string
     */
    public $importUrl = '';

    /**
     * @var int
     */
    public $calendarID = 0;

    /**
     * @var Calendar[]
     */
    public $availableCalendars = [];

    /**
     * @inheritDoc
     */
    public function readFormParameters() {
        parent::readFormParameters();

        if (isset($_POST['importUrl'])) {
            $this->importUrl = StringUtil::trim($_POST['importUrl']);
        }
        if (isset($_POST['calendarID'])) {
            $this->calendarID = intval($_POST['calendarID']);
        }
    }

    /**
     * @inheritDoc
     */
    public function validate() {
        parent::validate();

        if (empty($this->importUrl)) {
            throw new UserInputException('importUrl');
        }

        if (!filter_var($this->importUrl, FILTER_VALIDATE_URL)) {
            throw new UserInputException('importUrl', 'invalid');
        }

        if ($this->calendarID === 0) {
            throw new UserInputException('calendarID');
        }
    }

    /**
     * @inheritDoc
     */
    public function save() {
        parent::save();

        $this->fetchAndImportEvents();

        $this->saved();

        WCF::getTPL()->assign('success', true);
    }

    /**
     * Fetches events from the remote URL and imports them
     */
    protected function fetchAndImportEvents() {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->importUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'CalendarImport/1.6.1');
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        curl_close($ch);

        if ($response !== false) {
            $this->parseAndStoreEvents($response);
        }
    }

    /**
     * Parses the response and stores events
     * 
     * @param string $response
     */
    protected function parseAndStoreEvents($response) {
        // Implementation for parsing iCal/ICS format
    }

    /**
     * @inheritDoc
     */
    public function readData() {
        parent::readData();

        $calendarList = new CalendarList();
        $calendarList->readObjects();
        $this->availableCalendars = $calendarList->getObjects();
    }

    /**
     * @inheritDoc
     */
    public function assignVariables() {
        parent::assignVariables();

        WCF::getTPL()->assign([
            'importUrl' => $this->importUrl,
            'calendarID' => $this->calendarID,
            'availableCalendars' => $this->availableCalendars
        ]);
    }
}
