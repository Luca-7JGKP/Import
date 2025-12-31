<?php

namespace wcf\acp\form;

use wcf\data\package\PackageCache;
use wcf\form\AbstractForm;
use wcf\system\event\EventHandler;
use wcf\system\option\OptionHandler;
use wcf\system\WCF;

/**
 * Form for calendar import settings with debug information collection.
 *
 * @author      Luca-7JGKP
 * @copyright   2025
 * @license     GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 */
class CalendarImportSettingsForm extends AbstractForm {
    /**
     * @inheritDoc
     */
    public $activeMenuItem = 'wcf.acp.menu.link.calendar.import.settings';

    /**
     * @inheritDoc
     */
    public $neededPermissions = ['admin.calendar.canManageImport'];

    /**
     * Debug information array
     * @var array
     */
    protected $debugInfo = [];

    /**
     * @inheritDoc
     */
    public function readData() {
        parent::readData();

        // Collect debug information
        $this->debugInfo = $this->collectDebugInfo();
    }

    /**
     * Collects debug information including package info, event listeners,
     * options, listener classes, and event classes.
     *
     * @return array
     */
    protected function collectDebugInfo(): array {
        $debugInfo = [];

        // Gather package info
        $debugInfo['packageInfo'] = $this->getPackageInfo();

        // Gather event listeners
        $debugInfo['eventListeners'] = $this->getEventListeners();

        // Gather options
        $debugInfo['options'] = $this->getOptions();

        // Gather listener classes
        $debugInfo['listenerClasses'] = $this->getListenerClasses();

        // Gather event classes
        $debugInfo['eventClasses'] = $this->getEventClasses();

        // Add collection timestamp
        $debugInfo['collectedAt'] = date('Y-m-d H:i:s');

        return $debugInfo;
    }

    /**
     * Retrieves package information.
     *
     * @return array
     */
    protected function getPackageInfo(): array {
        $packageInfo = [];

        $packages = PackageCache::getInstance()->getPackages();
        foreach ($packages as $package) {
            $packageInfo[] = [
                'packageID' => $package->packageID,
                'package' => $package->package,
                'packageName' => $package->getName(),
                'packageVersion' => $package->packageVersion,
                'packageDate' => $package->packageDate,
                'isApplication' => $package->isApplication,
            ];
        }

        return $packageInfo;
    }

    /**
     * Retrieves registered event listeners.
     *
     * @return array
     */
    protected function getEventListeners(): array {
        $eventListeners = [];

        $sql = "SELECT      event_listener.*, package.package AS packageIdentifier
                FROM        wcf1_event_listener event_listener
                LEFT JOIN   wcf1_package package
                ON          package.packageID = event_listener.packageID
                ORDER BY    event_listener.listenerClassName";
        $statement = WCF::getDB()->prepare($sql);
        $statement->execute();

        while ($row = $statement->fetchArray()) {
            $eventListeners[] = [
                'listenerID' => $row['listenerID'],
                'eventClassName' => $row['eventClassName'],
                'eventName' => $row['eventName'],
                'listenerClassName' => $row['listenerClassName'],
                'inherit' => $row['inherit'],
                'niceValue' => $row['niceValue'],
                'packageIdentifier' => $row['packageIdentifier'],
            ];
        }

        return $eventListeners;
    }

    /**
     * Retrieves system options.
     *
     * @return array
     */
    protected function getOptions(): array {
        $options = [];

        $sql = "SELECT      option_table.*, category.categoryName
                FROM        wcf1_option option_table
                LEFT JOIN   wcf1_option_category category
                ON          category.categoryID = option_table.categoryID
                ORDER BY    option_table.optionName";
        $statement = WCF::getDB()->prepare($sql);
        $statement->execute();

        while ($row = $statement->fetchArray()) {
            $options[] = [
                'optionID' => $row['optionID'],
                'optionName' => $row['optionName'],
                'categoryName' => $row['categoryName'],
                'optionType' => $row['optionType'],
                'optionValue' => $row['optionValue'],
                'isDisabled' => $row['isDisabled'],
            ];
        }

        return $options;
    }

    /**
     * Retrieves listener classes from event listeners.
     *
     * @return array
     */
    protected function getListenerClasses(): array {
        $listenerClasses = [];

        $sql = "SELECT DISTINCT listenerClassName
                FROM   wcf1_event_listener
                ORDER BY listenerClassName";
        $statement = WCF::getDB()->prepare($sql);
        $statement->execute();

        while ($row = $statement->fetchArray()) {
            $className = $row['listenerClassName'];
            $listenerClasses[] = [
                'className' => $className,
                'exists' => class_exists($className),
                'implements' => class_exists($className) ? class_implements($className) : [],
            ];
        }

        return $listenerClasses;
    }

    /**
     * Retrieves event classes from event listeners.
     *
     * @return array
     */
    protected function getEventClasses(): array {
        $eventClasses = [];

        $sql = "SELECT DISTINCT eventClassName
                FROM   wcf1_event_listener
                ORDER BY eventClassName";
        $statement = WCF::getDB()->prepare($sql);
        $statement->execute();

        while ($row = $statement->fetchArray()) {
            $className = $row['eventClassName'];
            $eventClasses[] = [
                'className' => $className,
                'exists' => class_exists($className),
                'parentClass' => class_exists($className) ? get_parent_class($className) : null,
            ];
        }

        return $eventClasses;
    }

    /**
     * @inheritDoc
     */
    public function assignVariables() {
        parent::assignVariables();

        WCF::getTPL()->assign([
            'debugInfo' => $this->debugInfo,
            'packageInfo' => $this->debugInfo['packageInfo'] ?? [],
            'eventListeners' => $this->debugInfo['eventListeners'] ?? [],
            'options' => $this->debugInfo['options'] ?? [],
            'listenerClasses' => $this->debugInfo['listenerClasses'] ?? [],
            'eventClasses' => $this->debugInfo['eventClasses'] ?? [],
            'debugCollectedAt' => $this->debugInfo['collectedAt'] ?? '',
        ]);
    }
}
