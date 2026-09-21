<?php
// Health check endpoint for Render
// Returns 200 OK if the web server is running
http_response_code(200);
header('Content-Type: application/json');
echo json_encode([
    'status' => 'healthy',
    'timestamp' => time(),
    'service' => 'edu-timetable'
]);
exit;