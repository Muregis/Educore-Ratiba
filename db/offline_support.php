<?php
/**
 * Offline Mode Support for Schools with Poor Internet
 * Provides local data caching and sync capabilities
 */

class OfflineSupport {
    private $schoolId;
    private $cacheDir;
    
    public function __construct($schoolId) {
        $this->schoolId = $schoolId;
        $this->cacheDir = __DIR__ . '/../cache/offline_' . $schoolId;
        $this->ensureCacheDir();
    }
    
    private function ensureCacheDir() {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }
    
    /**
     * Cache essential data for offline access
     */
    public function cacheEssentialData() {
        require_once __DIR__ . '/db.php';
        
        try {
            // Cache school info
            $stmt = db()->prepare('SELECT * FROM schools WHERE id = ?');
            $stmt->execute([$this->schoolId]);
            $this->saveToCache('school_info.json', $stmt->fetch());
            
            // Cache teachers
            $stmt = db()->prepare('SELECT * FROM teachers WHERE school_id = ?');
            $stmt->execute([$this->schoolId]);
            $this->saveToCache('teachers.json', $stmt->fetchAll());
            
            // Cache rooms
            $stmt = db()->prepare('SELECT * FROM rooms WHERE school_id = ?');
            $stmt->execute([$this->schoolId]);
            $this->saveToCache('rooms.json', $stmt->fetchAll());
            
            // Cache bands
            $stmt = db()->prepare('SELECT * FROM bands WHERE school_id = ?');
            $stmt->execute([$this->schoolId]);
            $this->saveToCache('bands.json', $stmt->fetchAll());
            
            // Cache classes
            $stmt = db()->prepare('SELECT * FROM classes WHERE school_id = ?');
            $stmt->execute([$this->schoolId]);
            $this->saveToCache('classes.json', $stmt->fetchAll());
            
            // Cache subjects
            $stmt = db()->prepare('SELECT * FROM subjects WHERE school_id = ?');
            $stmt->execute([$this->schoolId]);
            $this->saveToCache('subjects.json', $stmt->fetchAll());
            
            // Cache calendar
            $stmt = db()->prepare('SELECT * FROM school_calendar WHERE school_id = ?');
            $stmt->execute([$this->schoolId]);
            $this->saveToCache('calendar.json', $stmt->fetchAll());
            
            // Cache holidays
            $stmt = db()->prepare('SELECT * FROM school_holidays WHERE school_id = ?');
            $stmt->execute([$this->schoolId]);
            $this->saveToCache('holidays.json', $stmt->fetchAll());
            
            // Update cache timestamp
            $this->saveToCache('cache_timestamp.json', [
                'cached_at' => date('Y-m-d H:i:s'),
                'school_id' => $this->schoolId
            ]);
            
            return true;
        } catch (Throwable $e) {
            error_log('Offline cache error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Load cached data for offline use
     */
    public function loadCachedData($dataType) {
        $file = $this->cacheDir . '/' . $dataType . '.json';
        if (file_exists($file)) {
            return json_decode(file_get_contents($file), true);
        }
        return null;
    }
    
    /**
     * Save data to cache
     */
    private function saveToCache($filename, $data) {
        $file = $this->cacheDir . '/' . $filename;
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
    }
    
    /**
     * Check if cache is fresh (less than 24 hours old)
     */
    public function isCacheFresh() {
        $timestamp = $this->loadCachedData('cache_timestamp');
        if (!$timestamp || !isset($timestamp['cached_at'])) {
            return false;
        }
        
        $cacheTime = strtotime($timestamp['cached_at']);
        $currentTime = time();
        $hoursDiff = ($currentTime - $cacheTime) / 3600;
        
        return $hoursDiff < 24; // Cache is fresh if less than 24 hours old
    }
    
    /**
     * Record offline changes for later sync
     */
    public function recordOfflineChange($entityType, $entityData, $action = 'create') {
        $changesFile = $this->cacheDir . '/pending_changes.json';
        $changes = [];
        
        if (file_exists($changesFile)) {
            $changes = json_decode(file_get_contents($changesFile), true) ?: [];
        }
        
        $changes[] = [
            'id' => uniqid(),
            'entity_type' => $entityType,
            'action' => $action,
            'data' => $entityData,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        file_put_contents($changesFile, json_encode($changes, JSON_PRETTY_PRINT));
    }
    
    /**
     * Get pending changes for sync
     */
    public function getPendingChanges() {
        $changesFile = $this->cacheDir . '/pending_changes.json';
        if (file_exists($changesFile)) {
            return json_decode(file_get_contents($changesFile), true) ?: [];
        }
        return [];
    }
    
    /**
     * Clear pending changes after successful sync
     */
    public function clearPendingChanges() {
        $changesFile = $this->cacheDir . '/pending_changes.json';
        if (file_exists($changesFile)) {
            unlink($changesFile);
        }
    }
    
    /**
     * Sync offline changes to server
     */
    public function syncChanges() {
        require_once __DIR__ . '/db.php';
        
        $changes = $this->getPendingChanges();
        if (empty($changes)) {
            return ['success' => true, 'message' => 'No pending changes to sync'];
        }
        
        try {
            db()->beginTransaction();
            $syncedCount = 0;
            $errors = [];
            
            foreach ($changes as $change) {
                try {
                    switch ($change['entity_type']) {
                        case 'teacher':
                            $this->syncTeacher($change);
                            $syncedCount++;
                            break;
                        case 'room':
                            $this->syncRoom($change);
                            $syncedCount++;
                            break;
                        case 'subject':
                            $this->syncSubject($change);
                            $syncedCount++;
                            break;
                        // Add more entity types as needed
                        default:
                            $errors[] = "Unknown entity type: {$change['entity_type']}";
                    }
                } catch (Throwable $e) {
                    $errors[] = "Error syncing {$change['entity_type']}: " . $e->getMessage();
                }
            }
            
            db()->commit();
            
            if (empty($errors)) {
                $this->clearPendingChanges();
                $this->cacheEssentialData(); // Refresh cache after sync
                return ['success' => true, 'message' => "Synced {$syncedCount} changes successfully"];
            } else {
                return ['success' => false, 'message' => 'Partial sync completed', 'errors' => $errors];
            }
            
        } catch (Throwable $e) {
            db()->rollBack();
            return ['success' => false, 'message' => 'Sync failed: ' . $e->getMessage()];
        }
    }
    
    private function syncTeacher($change) {
        $data = $change['data'];
        
        if ($change['action'] === 'create') {
            $stmt = db()->prepare(
                'INSERT INTO teachers (school_id, staff_id, tsc_number, name, max_lessons_per_week) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $this->schoolId,
                $data['staff_id'] ?? null,
                $data['tsc_number'] ?? null,
                $data['name'],
                $data['max_lessons_per_week'] ?? null
            ]);
        } elseif ($change['action'] === 'update') {
            $stmt = db()->prepare(
                'UPDATE teachers SET staff_id = ?, tsc_number = ?, name = ?, max_lessons_per_week = ? WHERE id = ? AND school_id = ?'
            );
            $stmt->execute([
                $data['staff_id'] ?? null,
                $data['tsc_number'] ?? null,
                $data['name'],
                $data['max_lessons_per_week'] ?? null,
                $data['id'],
                $this->schoolId
            ]);
        }
    }
    
    private function syncRoom($change) {
        $data = $change['data'];
        
        if ($change['action'] === 'create') {
            $stmt = db()->prepare(
                'INSERT INTO rooms (school_id, name, capacity, room_type) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([
                $this->schoolId,
                $data['name'],
                $data['capacity'] ?? null,
                $data['room_type'] ?? null
            ]);
        }
    }
    
    private function syncSubject($change) {
        $data = $change['data'];
        
        if ($change['action'] === 'create') {
            $stmt = db()->prepare(
                'INSERT INTO subjects (school_id, band_id, class_id, name, lessons_per_week, assigned_teacher_id) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $this->schoolId,
                $data['band_id'],
                $data['class_id'],
                $data['name'],
                $data['lessons_per_week'],
                $data['assigned_teacher_id'] ?? null
            ]);
        }
    }
    
    /**
     * Get offline status for display
     */
    public function getOfflineStatus() {
        $cacheAge = null;
        $timestamp = $this->loadCachedData('cache_timestamp');
        
        if ($timestamp && isset($timestamp['cached_at'])) {
            $cacheTime = strtotime($timestamp['cached_at']);
            $cacheAge = round((time() - $cacheTime) / 3600); // hours
        }
        
        $pendingChanges = count($this->getPendingChanges());
        
        return [
            'cache_fresh' => $this->isCacheFresh(),
            'cache_age_hours' => $cacheAge,
            'pending_changes' => $pendingChanges,
            'cache_exists' => file_exists($this->cacheDir . '/school_info.json')
        ];
    }
}

/**
 * Check if current request is in offline mode
 */
function isOfflineMode() {
    // Check for offline flag in session or URL parameter
    return isset($_SESSION['offline_mode']) || isset($_GET['offline']);
}

/**
 * Enable offline mode
 */
function enableOfflineMode() {
    $_SESSION['offline_mode'] = true;
}

/**
 * Disable offline mode
 */
function disableOfflineMode() {
    unset($_SESSION['offline_mode']);
}
