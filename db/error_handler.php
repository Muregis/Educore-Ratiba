<?php
declare(strict_types=1);

/**
 * db/error_handler.php
 * 
 * Centralized error and exception handling for the application.
 * Registers handlers for PHP errors, uncaught exceptions, and shutdown errors.
 * All errors are logged to the error log with context.
 */

// Only register handlers once
if (defined('ERROR_HANDLER_REGISTERED')) {
    return;
}
define('ERROR_HANDLER_REGISTERED', true);

/**
 * Converts PHP error levels to human-readable strings.
 */
function errorTypeToString(int $level): string
{
    return match(true) {
        $level === E_ERROR             => 'Error',
        $level === E_WARNING           => 'Warning',
        $level === E_PARSE             => 'Parse error',
        $level === E_NOTICE            => 'Notice',
        $level === E_CORE_ERROR        => 'Core error',
        $level === E_CORE_WARNING      => 'Core warning',
        $level === E_COMPILE_ERROR     => 'Compile error',
        $level === E_COMPILE_WARNING   => 'Compile warning',
        $level === E_USER_ERROR        => 'User error',
        $level === E_USER_WARNING      => 'User warning',
        $level === E_USER_NOTICE       => 'User notice',
        $level === E_RECOVERABLE_ERROR => 'Recoverable error',
        $level === E_DEPRECATED        => 'Deprecated',
        $level === E_USER_DEPRECATED   => 'User deprecated',
        default                        => 'Unknown error (' . $level . ')',
    };
}

/**
 * Formats an error message with context for logging.
 */
function formatErrorMessage(string $type, string $message, ?string $file = null, ?int $line = null): string
{
    $context = [];
    if ($file !== null) {
        $context[] = "File: {$file}";
    }
    if ($line !== null) {
        $context[] = "Line: {$line}";
    }
    $context[] = 'Request: ' . ($_SERVER['REQUEST_URI'] ?? 'CLI');
    $context[] = 'IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'CLI');
    
    return sprintf(
        '[%s] %s: %s | %s',
        date('Y-m-d H:i:s'),
        $type,
        $message,
        implode(' | ', $context)
    );
}

/**
 * Custom error handler that converts PHP errors to ErrorException.
 */
function appErrorHandler(int $level, string $message, string $file, int $line): bool
{
    if (!(error_reporting() & $level)) {
        return true;
    }
    
    $type = errorTypeToString($level);
    $formatted = formatErrorMessage($type, $message, $file, $line);
    
    // Log the error
    error_log($formatted);
    
    // Convert to exception for consistent handling
    throw new ErrorException($message, 0, $level, $file, $line);
}

/**
 * Custom exception handler that logs uncaught exceptions and shows a user-friendly page.
 */
function appExceptionHandler(Throwable $e): void
{
    $message = $e->getMessage();
    $file = $e->getFile();
    $line = $e->getLine();
    $formatted = formatErrorMessage('Uncaught Exception', $message, $file, $line);
    
    error_log($formatted);
    error_log('Stack trace: ' . $e->getTraceAsString());
    
    // If this is an HTTP request, show a user-friendly error page
    if (php_sapi_name() !== 'cli' && !headers_sent()) {
        http_response_code(500);
        
        // Check if session is started and school is set
        $isAdminPage = isset($_SESSION['school_admin_id']) || isset($_SESSION['super_admin_id']);
        
        if ($isAdminPage) {
            requireLoginAndGetSchoolId();
            // This will redirect if not logged in
        }
        
        // Show error page
        $pageTitle = 'Error — EduCore Ratiba';
        include __DIR__ . '/../admin/_header.php';
        ?>
        <div class="card" style="text-align: center; padding: 40px 24px;">
            <div style="font-size: 3rem; margin-bottom: 12px;">⚠️</div>
            <h2 style="margin-bottom: 8px;">Something went wrong</h2>
            <p class="empty" style="margin-bottom: 20px;">
                An unexpected error occurred. The issue has been logged and will be investigated.
            </p>
            <div style="display: flex; gap: 8px; justify-content: center; flex-wrap: wrap;">
                <a href="javascript:history.back()" class="btn btn-secondary">Go Back</a>
                <a href="dashboard.php" class="btn">Go to Dashboard</a>
            </div>
            <div style="margin-top: 30px; text-align: left; background: #fff3cd; border: 1px solid #ffc107; padding: 15px; border-radius: 8px;">
                <h4 style="margin-bottom: 10px;">Error Details</h4>
                <p><strong>Message:</strong> <?php echo htmlspecialchars($message); ?></p>
                <p><strong>File:</strong> <?php echo htmlspecialchars($file); ?></p>
                <p><strong>Line:</strong> <?php echo (int) $line; ?></p>
                <details style="margin-top: 10px;">
                    <summary style="cursor: pointer; font-weight: bold;">Stack Trace</summary>
                    <pre style="background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 5px; overflow-x: auto; font-size: 0.8rem; margin-top: 10px;"><?php echo htmlspecialchars($e->getTraceAsString()); ?></pre>
                </details>
            </div>
        </div>
        <?php
        require __DIR__ . '/../admin/_footer.php';
        exit;
    } else {
        echo "Fatal error: " . htmlspecialchars($message) . "\n";
        echo "File: {$file}, Line: {$line}\n";
        exit(1);
    }
}

/**
 * Handles fatal errors that occur during shutdown.
 */
function appShutdownHandler(): void
{
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $type = errorTypeToString($error['type']);
        $formatted = formatErrorMessage($type, $error['message'], $error['file'], $error['line']);
        error_log($formatted);
        
        if (php_sapi_name() !== 'cli' && !headers_sent()) {
            http_response_code(500);
            echo "<h1>Fatal Error</h1>";
            echo "<p>The application encountered a fatal error and could not continue.</p>";
            echo "<p>Please contact support or try again later.</p>";
            exit;
        }
    }
}

// Register the error and exception handlers
set_error_handler('appErrorHandler');
set_exception_handler('appExceptionHandler');
register_shutdown_function('appShutdownHandler');

// Ensure all errors are reported (will be handled by our custom handler)
error_reporting(E_ALL);
