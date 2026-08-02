<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../db/error_handler.php';

$schoolId = requireLoginAndGetSchoolId();
$stmt = db()->prepare('SELECT * FROM schools WHERE id = ?');
$stmt->execute([$schoolId]);
$school = $stmt->fetch();

$pageTitle = 'Help & Documentation — ' . $school['name'];
require __DIR__ . '/_header.php';
?>

<div class="card">
    <h2>📚 Help & Documentation</h2>
    <p class="empty">Welcome to the EduCore Ratiba help center. Find guides and answers to common questions below.</p>
    
    <div style="display: grid; gap: 20px; margin-top: 20px;">
        
        <div style="border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 20px;">
            <h3 style="margin-bottom: 12px; color: var(--primary);">🚀 Getting Started</h3>
            <p style="margin-bottom: 10px;">Follow these steps to set up your first timetable:</p>
            <ol style="padding-left: 24px; line-height: 1.8;">
                <li><strong>Add Teachers</strong> — Go to <a href="teachers.php">Teachers</a> and add all your teaching staff.</li>
                <li><strong>Add Rooms</strong> — Go to <a href="rooms.php">Rooms</a> and add classrooms, labs, halls, etc.</li>
                <li><strong>Configure Bands</strong> — Go to <a href="bands.php">Bands</a> and set up grade bands (e.g., Grade 1-3, Grade 4-6) with lesson schedules.</li>
                <li><strong>Add Classes</strong> — Go to <a href="classes.php">Classes</a> and create class groups for each band.</li>
                <li><strong>Add Subjects</strong> — Go to <a href="subjects.php">Subjects</a> and assign subjects and teachers to each class.</li>
                <li><strong>Generate Timetable</strong> — Go to <a href="generate.php">Generate</a> and run the FET engine to produce the schedule.</li>
            </ol>
        </div>
        
        <div style="border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 20px;">
            <h3 style="margin-bottom: 12px; color: var(--primary);">📋 Managing Data</h3>
            <p style="margin-bottom: 10px;">Here's how to manage your school data:</p>
            <ul style="padding-left: 24px; line-height: 1.8;">
                <li><strong>Bulk Delete:</strong> Use the checkboxes in any table to select multiple items, then click "Delete Selected" to remove them all at once.</li>
                <li><strong>Search & Filter:</strong> Use the search bar and filters at the top of each page to find specific items quickly.</li>
                <li><strong>Edit Timetable:</strong> After generating a timetable, use <a href="edit-timetable.php">Edit Timetable</a> to make manual adjustments.</li>
                <li><strong>Extra Activities:</strong> Add clubs, parade, assembly, and other non-subject activities via <a href="activities.php">Activities</a>.</li>
                <li><strong>Remedial Sessions:</strong> Add morning/evening remedial lessons via <a href="remedials.php">Remedials</a>.</li>
                <li><strong>Teacher Timetables:</strong> Download individual teacher schedules from <a href="view_timetable.php">View Timetables</a>.</li>
            </ul>
        </div>
        
        <div style="border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 20px;">
            <h3 style="margin-bottom: 12px; color: var(--primary);">⬇ Exporting Data</h3>
            <p style="margin-bottom: 10px;">You can export your data in multiple formats:</p>
            <ul style="padding-left: 24px; line-height: 1.8;">
                <li><strong>CSV Export:</strong> Export any list (teachers, rooms, classes, subjects, etc.) as a CSV file for use in Excel or Google Sheets.</li>
                <li><strong>Excel Export:</strong> Export data as an .xlsx file for better formatting and compatibility.</li>
                <li><strong>PDF Export:</strong> Download the generated timetable as a PDF document from <a href="view_timetable.php">View Timetable</a>.</li>
            </ul>
        </div>
        
        <div style="border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 20px;">
            <h3 style="margin-bottom: 12px; color: var(--primary);">🔒 Security & Audit</h3>
            <p style="margin-bottom: 10px;">Your data is protected:</p>
            <ul style="padding-left: 24px; line-height: 1.8;">
                <li><strong>Audit Log:</strong> All administrative actions (create, update, delete, login, logout) are recorded in the <a href="audit_log.php">Audit Log</a> for security and accountability.</li>
                <li><strong>CSRF Protection:</strong> All forms include CSRF tokens to prevent cross-site request forgery attacks.</li>
                <li><strong>Session Management:</strong> You must be logged in to access any admin pages. Sessions expire after a period of inactivity.</li>
                <li><strong>Multi-Tenant Isolation:</strong> Each school's data is completely isolated from other schools.</li>
            </ul>
        </div>
        
        <div style="border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 20px;">
            <h3 style="margin-bottom: 12px; color: var(--primary);">💡 Tips & Best Practices</h3>
            <ul style="padding-left: 24px; line-height: 1.8;">
                <li><strong>Plan Your Bands First:</strong> Configure bands with the correct number of lessons per day and lesson length before adding classes. MOE/KICD standards: PP1-2 = 5/day, Grade 1-3 = 6/day, Grade 4-6 = 7/day, Grade 7-9 = 8/day, Grade 10-12 = 8/day, Form 3-4 = 8/day.</li>
                <li><strong>Assign Teachers Early:</strong> Make sure all subjects have assigned teachers before generating the timetable.</li>
                <li><strong>Check Conflicts:</strong> After generation, review the timetable for any conflicts or unscheduled activities.</li>
                <li><strong>Use Filters:</strong> The search and filter options help you manage large datasets efficiently.</li>
                <li><strong>Export Regularly:</strong> Export your data periodically as a backup measure.</li>
                <li><strong>Deactivate, Don't Delete:</strong> Consider deactivating classes instead of deleting them to preserve historical data.</li>
            </ul>
        </div>
        
        <div style="border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 20px;">
            <h3 style="margin-bottom: 12px; color: var(--primary);">❓ Troubleshooting</h3>
            <p style="margin-bottom: 10px;">Common issues and solutions:</p>
            <ul style="padding-left: 24px; line-height: 1.8;">
                <li><strong>Timetable generation fails:</strong> Ensure all required data (teachers, rooms, bands, classes, subjects) is added and there are no conflicting constraints.</li>
                <li><strong>Can't delete a teacher:</strong> The teacher may be assigned to subjects. Remove the teacher from subjects first, or reassign those subjects.</li>
                <li><strong>Can't delete a band:</strong> The band may have classes assigned. Deactivate the band or reassign the classes first.</li>
                <li><strong>Export not working:</strong> Ensure the ZipArchive PHP extension is installed for Excel exports. CSV export works without any additional extensions.</li>
                <li><strong>Login issues:</strong> If you've forgotten your password, contact your system administrator. After 5 failed login attempts, your account will be temporarily locked for 60 seconds.</li>
            </ul>
        </div>
        
        <div style="border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 20px;">
            <h3 style="margin-bottom: 12px; color: var(--primary);">📞 Getting Support</h3>
            <p>If you need further assistance, please contact your system administrator or the EduCore Ratiba support team. You can also check the <a href="audit_log.php">Audit Log</a> to review recent activity and identify any issues.</p>
        </div>
        
    </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
