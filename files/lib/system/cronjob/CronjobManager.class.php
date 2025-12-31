<?php
namespace wcf\system\cronjob;

use wcf\system\WCF;
use wcf\data\cronjob\Cronjob;

/**
 * Utility-Klasse für Cronjob-Verwaltung.
 * Demonstriert KORREKTE Verwendung von LIKE-Platzhaltern in SQL-Abfragen.
 * 
 * FIX: Wildcards (%) müssen in Parameter-Werten enthalten sein, NICHT in SQL
 * 
 * @author  Luca Berwind
 * @package com.lucaberwind.wcf.calendar.import
 */
class CronjobManager {
    
    /**
     * Findet Cronjobs nach Klassennamen-Mustern.
     * 
     * WICHTIG: Dies demonstriert die KORREKTE Verwendung von LIKE mit Prepared Statements.
     * Die Wildcards (%) müssen in den Parameter-Werten enthalten sein, NICHT in der SQL-Abfrage.
     * 
     * @param array $patterns Array von Klassennamen-Mustern
     * @return array Array passender Cronjobs
     */
    public function findCronjobsByPattern(array $patterns) {
        if (empty($patterns)) {
            return [];
        }
        
        // WHERE-Klausel mit korrekten Platzhaltern aufbauen
        $conditions = [];
        $parameters = [];
        
        foreach ($patterns as $pattern) {
            $conditions[] = "cronjobClassName LIKE ?";
            // KORREKT: Wildcards in Parameter-Wert einschließen, nicht in SQL
            // Die Wildcards sind Teil der Daten, nicht Teil der Query-Struktur
            $parameters[] = '%' . $pattern . '%';
        }
        
        $sql = "SELECT cronjobID, cronjobClassName, packageID 
                FROM wcf".WCF_N."_cronjob 
                WHERE " . implode(' OR ', $conditions);
        
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute($parameters);
        
        $cronjobs = [];
        while ($row = $statement->fetchArray()) {
            $cronjobs[] = $row;
        }
        
        return $cronjobs;
    }
    
    /**
     * Beispiel-Verwendung: Findet Import-bezogene Cronjobs.
     * 
     * @return array
     */
    public function findImportRelatedCronjobs() {
        $patterns = [
            'CalendarImport',
            'ICalSync', 
            'EventImport'
        ];
        
        return $this->findCronjobsByPattern($patterns);
    }
    
    /**
     * Findet Cronjobs nach einzelnem Muster.
     * 
     * @param string $pattern Einzelnes Muster
     * @return array
     */
    public function findCronjobsBySinglePattern($pattern) {
        $sql = "SELECT cronjobID, cronjobClassName, packageID 
                FROM wcf".WCF_N."_cronjob 
                WHERE cronjobClassName LIKE ?";
        
        $statement = WCF::getDB()->prepareStatement($sql);
        
        // Wildcards vor und nach Muster hinzufügen
        $statement->execute(['%' . $pattern . '%']);
        
        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Sucht Cronjobs mit case-insensitive Matching.
     * Hinweis: MySQL LIKE ist standardmäßig case-insensitive mit utf8mb4_unicode_ci Collation.
     * 
     * @param array $patterns
     * @return array
     */
    public function findCronjobsCaseInsensitive(array $patterns) {
        if (empty($patterns)) {
            return [];
        }
        
        $conditions = [];
        $parameters = [];
        
        foreach ($patterns as $pattern) {
            // MySQL LIKE mit utf8mb4_unicode_ci ist bereits case-insensitive
            // Aber wir können es explizit machen falls nötig
            $conditions[] = "LOWER(cronjobClassName) LIKE LOWER(?)";
            $parameters[] = '%' . $pattern . '%';
        }
        
        $sql = "SELECT cronjobID, cronjobClassName, packageID 
                FROM wcf".WCF_N."_cronjob 
                WHERE " . implode(' OR ', $conditions);
        
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute($parameters);
        
        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Findet Cronjobs nach Package-ID und Muster.
     * 
     * @param int $packageID
     * @param string $pattern
     * @return array
     */
    public function findCronjobsByPackageAndPattern($packageID, $pattern) {
        $sql = "SELECT cronjobID, cronjobClassName, packageID 
                FROM wcf".WCF_N."_cronjob 
                WHERE packageID = ? AND cronjobClassName LIKE ?";
        
        $statement = WCF::getDB()->prepareStatement($sql);
        $statement->execute([$packageID, '%' . $pattern . '%']);
        
        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * FALSCHES BEISPIEL (zur Referenz - NICHT VERWENDEN):
     * Dies ist wie man LIKE-Platzhalter NICHT verwenden sollte.
     * 
     * @example Dies ist ein SCHLECHTES Beispiel - niemals dieses Muster verwenden!
     */
    public function findCronjobsByPatternIncorrect() {
        // FALSCH: Dies wird fehlschlagen weil die Wildcards fehlen
        // $sql = "SELECT cronjobID, cronjobClassName, packageID 
        //         FROM wcf1_cronjob 
        //         WHERE cronjobClassName LIKE ? OR cronjobClassName LIKE ? OR cronjobClassName LIKE ?";
        // $statement = WCF::getDB()->prepareStatement($sql);
        // $statement->execute(['CalendarImport', 'ICalSync', 'EventImport']); // FEHLENDE WILDCARDS!
        
        // Das Obige wird nur exakte Strings matchen, keine Muster, weil % Wildcards fehlen
    }
}
