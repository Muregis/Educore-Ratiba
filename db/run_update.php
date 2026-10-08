<?php
require_once __DIR__ . '/db.php';
try {
    db()->exec("UPDATE bands SET day_start_time = '08:00' WHERE day_start_time IS NULL");
    db()->exec("UPDATE bands SET break_config = '[{\"time\":\"10:00-10:20\",\"label\":\"BREAK\"},{\"time\":\"12:20-14:00\",\"label\":\"LUNCH\"}]' WHERE break_config IS NULL OR break_config = '[]'");
    echo "Updated bands defaults";
} catch (Throwable $e) {
    echo $e->getMessage();
}
