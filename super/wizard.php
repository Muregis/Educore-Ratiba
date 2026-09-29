<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../db/school_templates.php';
require_once __DIR__ . '/../db/knec_dates.php';

if (!isSuperAdmin()) {
    header('Location: ../login.php');
    exit;
}

// Initialize wizard session
if (!isset($_SESSION['wizard'])) {
    $_SESSION['wizard'] = [
        'step' => 1,
        'data' => []
    ];
}

$wizard = &$_SESSION['wizard'];
$error = null;
$success = null;

// Handle wizard navigation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'next') {
        // Validate and save current step data
        $currentStep = $wizard['step'];
        $stepData = $_POST['data'] ?? [];

        if ($currentStep === 1) {
            // School基本信息
            if (empty($stepData['school_name']) || empty($stepData['admin_username']) || empty($stepData['admin_password'])) {
                $error = 'School name, admin username, and password are required.';
            } elseif (strlen($stepData['admin_password']) < 8) {
                $error = 'Password must be at least 8 characters.';
            } else {
                $wizard['data']['school'] = $stepData;
                $wizard['step'] = 2;
            }
        } elseif ($currentStep === 2) {
            // School classification
            $wizard['data']['classification'] = $stepData;
            $wizard['step'] = 3;
        } elseif ($currentStep === 3) {
            // Template selection
            $wizard['data']['template'] = $stepData;
            $wizard['step'] = 4;
        } elseif ($currentStep === 4) {
            // Calendar setup
            $wizard['data']['calendar'] = $stepData;
            $wizard['step'] = 5;
        } elseif ($currentStep === 5) {
            // Final confirmation and creation
            try {
                db()->beginTransaction();

                // Create school
                $schoolData = $wizard['data']['school'];
                $classData = $wizard['data']['classification'];
                $templateData = $wizard['data']['template'];
                $calendarData = $wizard['data']['calendar'];

                $syncToken = ($schoolData['deployment_type'] ?? 'server-hosted') === 'local-install' 
                    ? bin2hex(random_bytes(32)) : null;
                $educoreRef = !empty($schoolData['educore_school_id']) ? $schoolData['educore_school_id'] : null;
                $countyRef = !empty($classData['county']) ? $classData['county'] : null;
                $moeCodeRef = !empty($classData['ministry_of_education_code']) ? $classData['ministry_of_education_code'] : null;
                $subTypeRef = !empty($classData['school_sub_type']) ? $classData['school_sub_type'] : null;

                $stmt = db()->prepare(
                    'INSERT INTO schools (name, school_type, school_sub_type, county, ministry_of_education_code, deployment_type, sync_token, educore_school_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $schoolData['school_name'],
                    $classData['school_type'],
                    $subTypeRef,
                    $countyRef,
                    $moeCodeRef,
                    $schoolData['deployment_type'] ?? 'server-hosted',
                    $syncToken,
                    $educoreRef
                ]);
                $newSchoolId = (int) db()->lastInsertId();

                // Create admin
                $stmt = db()->prepare(
                    'INSERT INTO school_admins (school_id, username, password_hash) VALUES (?, ?, ?)'
                );
                $stmt->execute([
                    $newSchoolId,
                    $schoolData['admin_username'],
                    password_hash($schoolData['admin_password'], PASSWORD_DEFAULT),
                ]);

                // Apply template if selected
                if (!empty($templateData['template']) && $templateData['template'] !== 'manual') {
                    SchoolTemplates::applyTemplate($newSchoolId, $templateData['template']);
                }

                // Set up calendar if configured
                if (!empty($calendarData['setup_calendar']) && !empty($calendarData['term_name'])) {
                    $currentYear = date('Y');
                    $stmt = db()->prepare(
                        'INSERT INTO school_calendar (school_id, year, term_number, term_name, start_date, end_date, is_current) VALUES (?, ?, ?, ?, ?, ?, TRUE)'
                    );
                    $stmt->execute([
                        $newSchoolId,
                        $currentYear,
                        $calendarData['term_number'] ?? 1,
                        $calendarData['term_name'],
                        $calendarData['start_date'],
                        $calendarData['end_date']
                    ]);

                    // Add KNEC dates if requested
                    if (!empty($calendarData['add_knec_dates'])) {
                        populateKNECDatesForSchool($newSchoolId, $currentYear);
                    }
                }

                db()->commit();

                // Clear wizard session
                unset($_SESSION['wizard']);

                $success = "School \"{$schoolData['school_name']}\" created successfully!";
                if ($syncToken !== null) {
                    $success .= " Sync token: {$syncToken}";
                }

                // Redirect to schools page after delay
                header("refresh:3;url=schools.php");

            } catch (Throwable $e) {
                db()->rollBack();
                $error = 'Could not create school: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'back') {
        if ($wizard['step'] > 1) {
            $wizard['step']--;
        }
    } elseif ($action === 'cancel') {
        unset($_SESSION['wizard']);
        header('Location: schools.php');
        exit;
    }
}

// Get templates for selection
$templates = SchoolTemplates::getAllTemplates();
$currentYear = date('Y');

$pageTitle = 'School Setup Wizard — Super Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo htmlspecialchars($pageTitle); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    :root {
        --primary: #6366f1;
        --border: #e5e7eb;
        --text: #1f2937;
        --text-muted: #6b7280;
        --bg-light: #f9fafb;
        --bg-white: #ffffff;
        --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        background: var(--bg-light);
        color: var(--text);
        min-height: 100vh;
    }
    header {
        background: var(--bg-white);
        border-bottom: 1px solid var(--border);
        padding: 12px 32px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .logo { font-weight: 700; color: var(--primary); }
    header a { color: var(--primary); text-decoration: none; font-size: 0.875rem; font-weight: 600; }
    main { max-width: 800px; margin: 32px auto; padding: 0 24px; }
    .wizard-container {
        background: var(--bg-white);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 32px;
        box-shadow: var(--shadow);
    }
    .progress-bar {
        display: flex;
        justify-content: space-between;
        margin-bottom: 32px;
        position: relative;
    }
    .progress-bar::before {
        content: '';
        position: absolute;
        top: 12px;
        left: 0;
        right: 0;
        height: 2px;
        background: var(--border);
        z-index: 0;
    }
    .step {
        display: flex;
        flex-direction: column;
        align-items: center;
        z-index: 1;
        background: var(--bg-white);
        padding: 0 8px;
    }
    .step-number {
        width: 24px;
        height: 24px;
        border-radius: 50%;
        background: var(--border);
        color: var(--text-muted);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.75rem;
        font-weight: 600;
        margin-bottom: 4px;
    }
    .step.active .step-number {
        background: var(--primary);
        color: white;
    }
    .step.completed .step-number {
        background: #10b981;
        color: white;
    }
    .step-label {
        font-size: 0.75rem;
        color: var(--text-muted);
    }
    .step.active .step-label {
        color: var(--primary);
        font-weight: 600;
    }
    .step-content {
        display: none;
    }
    .step-content.active {
        display: block;
    }
    h2 { font-size: 1.5rem; font-weight: 600; margin-bottom: 8px; }
    p { color: var(--text-muted); margin-bottom: 24px; }
    label { display: block; font-size: 0.875rem; font-weight: 500; margin: 16px 0 8px; }
    input, select {
        width: 100%; padding: 10px 14px; border: 1px solid var(--border);
        border-radius: 8px; font-size: 0.9rem; font-family: inherit;
    }
    .template-card {
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 16px;
        margin-bottom: 12px;
        cursor: pointer;
        transition: all 0.2s;
    }
    .template-card:hover {
        border-color: var(--primary);
        box-shadow: var(--shadow);
    }
    .template-card.selected {
        border-color: var(--primary);
        background: #f0f9ff;
    }
    .template-card h4 { margin: 0 0 4px; font-size: 1rem; }
    .template-card p { margin: 0; font-size: 0.875rem; color: var(--text-muted); }
    .wizard-buttons {
        display: flex;
        justify-content: space-between;
        margin-top: 32px;
        gap: 12px;
    }
    button {
        padding: 10px 20px; background: var(--primary); color: #fff;
        border: none; border-radius: 8px; font-weight: 600; cursor: pointer;
    }
    .btn-secondary {
        background: var(--border);
        color: var(--text);
    }
    .error { background: #fef2f2; color: #b91c1c; padding: 12px; border-radius: 8px; margin-bottom: 16px; }
    .success { background: #ecfdf5; color: #047857; padding: 12px; border-radius: 8px; margin-bottom: 16px; }
</style>
</head>
<body>
<header>
    <span class="logo">EduCore Ratiba — Setup Wizard</span>
    <a href="schools.php">Cancel</a>
</header>
<main>
    <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <?php if (!$success): ?>
    <div class="wizard-container">
        <div class="progress-bar">
            <div class="step <?php echo $wizard['step'] >= 1 ? 'active' : ''; ?> <?php echo $wizard['step'] > 1 ? 'completed' : ''; ?>">
                <div class="step-number">1</div>
                <div class="step-label">Basic Info</div>
            </div>
            <div class="step <?php echo $wizard['step'] >= 2 ? 'active' : ''; ?> <?php echo $wizard['step'] > 2 ? 'completed' : ''; ?>">
                <div class="step-number">2</div>
                <div class="step-label">Classification</div>
            </div>
            <div class="step <?php echo $wizard['step'] >= 3 ? 'active' : ''; ?> <?php echo $wizard['step'] > 3 ? 'completed' : ''; ?>">
                <div class="step-number">3</div>
                <div class="step-label">Template</div>
            </div>
            <div class="step <?php echo $wizard['step'] >= 4 ? 'active' : ''; ?> <?php echo $wizard['step'] > 4 ? 'completed' : ''; ?>">
                <div class="step-number">4</div>
                <div class="step-label">Calendar</div>
            </div>
            <div class="step <?php echo $wizard['step'] >= 5 ? 'active' : ''; ?> <?php echo $wizard['step'] > 5 ? 'completed' : ''; ?>">
                <div class="step-number">5</div>
                <div class="step-label">Confirm</div>
            </div>
        </div>

        <!-- Step 1: Basic Information -->
        <div class="step-content <?php echo $wizard['step'] === 1 ? 'active' : ''; ?>">
            <h2>Basic School Information</h2>
            <p>Enter the basic details for the new school.</p>
            <form method="post">
                <input type="hidden" name="action" value="next">
                <label for="school_name">School Name</label>
                <input type="text" id="school_name" name="data[school_name]" required 
                       value="<?php echo htmlspecialchars($wizard['data']['school']['school_name'] ?? ''); ?>" placeholder="Enter school name">

                <label for="admin_username">Admin Username</label>
                <input type="text" id="admin_username" name="data[admin_username]" required
                       value="<?php echo htmlspecialchars($wizard['data']['school']['admin_username'] ?? ''); ?>" placeholder="Enter admin username">

                <label for="admin_password">Admin Password</label>
                <input type="password" id="admin_password" name="data[admin_password]" minlength="8" required
                       placeholder="Minimum 8 characters">

                <label for="deployment_type">Deployment Type</label>
                <select id="deployment_type" name="data[deployment_type]">
                    <option value="server-hosted" <?php echo ($wizard['data']['school']['deployment_type'] ?? '') === 'server-hosted' ? 'selected' : ''; ?>>Server-hosted</option>
                    <option value="local-install" <?php echo ($wizard['data']['school']['deployment_type'] ?? '') === 'local-install' ? 'selected' : ''; ?>>Local-install (offline)</option>
                </select>

                <label for="educore_school_id">EduCore School ID (optional)</label>
                <input type="text" id="educore_school_id" name="data[educore_school_id]"
                       value="<?php echo htmlspecialchars($wizard['data']['school']['educore_school_id'] ?? ''); ?>" placeholder="ID from EduCore SMS">

                <div class="wizard-buttons">
                    <button type="button" class="btn-secondary" onclick="submitForm('cancel')">Cancel</button>
                    <button type="submit">Next →</button>
                </div>
            </form>
        </div>

        <!-- Step 2: School Classification -->
        <div class="step-content <?php echo $wizard['step'] === 2 ? 'active' : ''; ?>">
            <h2>School Classification</h2>
            <p>Classify the school type for Kenyan education system alignment.</p>
            <form method="post">
                <input type="hidden" name="action" value="next">
                <label for="school_type">School Type</label>
                <select id="school_type" name="data[school_type]" required onchange="updateSubTypeOptions()">
                    <option value="">-- Select --</option>
                    <option value="primary" <?php echo ($wizard['data']['classification']['school_type'] ?? '') === 'primary' ? 'selected' : ''; ?>>Primary School</option>
                    <option value="secondary" <?php echo ($wizard['data']['classification']['school_type'] ?? '') === 'secondary' ? 'selected' : ''; ?>>Secondary School</option>
                    <option value="tvet" <?php echo ($wizard['data']['classification']['school_type'] ?? '') === 'tvet' ? 'selected' : ''; ?>>TVET College</option>
                    <option value="private_academy" <?php echo ($wizard['data']['classification']['school_type'] ?? '') === 'private_academy' ? 'selected' : ''; ?>>Private Academy</option>
                    <option value="international" <?php echo ($wizard['data']['classification']['school_type'] ?? '') === 'international' ? 'selected' : ''; ?>>International School</option>
                </select>

                <label for="school_sub_type">School Sub-type (optional)</label>
                <select id="school_sub_type" name="data[school_sub_type]">
                    <option value="">-- Optional --</option>
                </select>

                <label for="county">County (optional)</label>
                <input type="text" id="county" name="data[county]"
                       value="<?php echo htmlspecialchars($wizard['data']['classification']['county'] ?? ''); ?>" placeholder="e.g. Nairobi, Mombasa, Kisumu">

                <label for="ministry_of_education_code">Ministry of Education Code (optional)</label>
                <input type="text" id="ministry_of_education_code" name="data[ministry_of_education_code]"
                       value="<?php echo htmlspecialchars($wizard['data']['classification']['ministry_of_education_code'] ?? ''); ?>" placeholder="e.g. 12345678">

                <div class="wizard-buttons">
                    <button type="submit" name="action" value="back" class="btn-secondary">← Back</button>
                    <button type="submit">Next →</button>
                </div>
            </form>
        </div>

        <!-- Step 3: Template Selection -->
        <div class="step-content <?php echo $wizard['step'] === 3 ? 'active' : ''; ?>">
            <h2>Choose School Template</h2>
            <p>Select a pre-configured template or choose manual setup.</p>
            <form method="post">
                <input type="hidden" name="action" value="next">
                <div class="template-card <?php echo ($wizard['data']['template']['template'] ?? '') === 'manual' ? 'selected' : ''; ?>">
                    <h4>Manual Setup</h4>
                    <p>Configure everything manually - maximum flexibility</p>
                    <input type="radio" name="data[template]" value="manual" 
                           <?php echo ($wizard['data']['template']['template'] ?? '') === 'manual' ? 'checked' : ''; ?>>
                </div>

                <?php foreach ($templates as $key => $template): ?>
                <div class="template-card <?php echo ($wizard['data']['template']['template'] ?? '') === $key ? 'selected' : ''; ?>">
                    <h4><?php echo htmlspecialchars($template['name']); ?></h4>
                    <p><?php echo htmlspecialchars($template['description']); ?></p>
                    <input type="radio" name="data[template]" value="<?php echo $key; ?>"
                           <?php echo ($wizard['data']['template']['template'] ?? '') === $key ? 'checked' : ''; ?>>
                </div>
                <?php endforeach; ?>

                <div class="wizard-buttons">
                    <button type="submit" name="action" value="back" class="btn-secondary">← Back</button>
                    <button type="submit">Next →</button>
                </div>
            </form>
        </div>

        <!-- Step 4: Calendar Setup -->
        <div class="step-content <?php echo $wizard['step'] === 4 ? 'active' : ''; ?>">
            <h2>Calendar Setup</h2>
            <p>Configure the first academic term for this school.</p>
            <form method="post">
                <input type="hidden" name="action" value="next">
                <label style="display: flex; align-items: center; gap: 8px; margin: 16px 0;">
                    <input type="checkbox" id="setup_calendar" name="data[setup_calendar]" checked>
                    <span>Set up first term now</span>
                </label>

                <label for="term_name">Term Name</label>
                <input type="text" id="term_name" name="data[term_name]" placeholder="e.g. Term 1 2025">

                <label for="term_number">Term Number</label>
                <select id="term_number" name="data[term_number]">
                    <option value="1">Term 1</option>
                    <option value="2">Term 2</option>
                    <option value="3">Term 3</option>
                </select>

                <label for="start_date">Start Date</label>
                <input type="date" id="start_date" name="data[start_date]">

                <label for="end_date">End Date</label>
                <input type="date" id="end_date" name="data[end_date]">

                <label style="display: flex; align-items: center; gap: 8px; margin: 16px 0;">
                    <input type="checkbox" id="add_knec_dates" name="data[add_knec_dates]" checked>
                    <span>Add KNEC exam dates for <?php echo $currentYear; ?></span>
                </label>

                <div class="wizard-buttons">
                    <button type="submit" name="action" value="back" class="btn-secondary">← Back</button>
                    <button type="submit">Next →</button>
                </div>
            </form>
        </div>

        <!-- Step 5: Confirmation -->
        <div class="step-content <?php echo $wizard['step'] === 5 ? 'active' : ''; ?>">
            <h2>Confirm School Setup</h2>
            <p>Review the school configuration before creating.</p>
            
            <div style="background: var(--bg-light); padding: 20px; border-radius: 8px; margin-bottom: 24px;">
                <h3 style="margin: 0 0 16px;">School Summary</h3>
                <p><strong>Name:</strong> <?php echo htmlspecialchars($wizard['data']['school']['school_name'] ?? ''); ?></p>
                <p><strong>Type:</strong> <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $wizard['data']['classification']['school_type'] ?? ''))); ?></p>
                <p><strong>Sub-type:</strong> <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $wizard['data']['classification']['school_sub_type'] ?? 'N/A'))); ?></p>
                <p><strong>County:</strong> <?php echo htmlspecialchars($wizard['data']['classification']['county'] ?? 'N/A'); ?></p>
                <p><strong>MoE Code:</strong> <?php echo htmlspecialchars($wizard['data']['classification']['ministry_of_education_code'] ?? 'N/A'); ?></p>
                <p><strong>Template:</strong> <?php echo htmlspecialchars($wizard['data']['template']['template'] ?? 'Manual'); ?></p>
                <p><strong>Deployment:</strong> <?php echo htmlspecialchars($wizard['data']['school']['deployment_type'] ?? 'server-hosted'); ?></p>
                <?php if (!empty($wizard['data']['calendar']['setup_calendar'])): ?>
                <p><strong>First Term:</strong> <?php echo htmlspecialchars($wizard['data']['calendar']['term_name'] ?? ''); ?></p>
                <p><strong>KNEC Dates:</strong> <?php echo !empty($wizard['data']['calendar']['add_knec_dates']) ? 'Yes' : 'No'; ?></p>
                <?php endif; ?>
            </div>

            <form method="post">
                <input type="hidden" name="action" value="next">
                <div class="wizard-buttons">
                    <button type="submit" name="action" value="back" class="btn-secondary">← Back</button>
                    <button type="submit">Create School</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</main>

<script>
const subTypeOptions = {
    primary: [
        {value: 'day', label: 'Day Primary'},
        {value: 'boarding', label: 'Boarding Primary'},
        {value: 'day_boarding', label: 'Day & Boarding Primary'},
        {value: 'private', label: 'Private Primary'}
    ],
    secondary: [
        {value: 'national', label: 'National School'},
        {value: 'extra_county', label: 'Extra-County School'},
        {value: 'county', label: 'County School'},
        {value: 'sub_county', label: 'Sub-County School'},
        {value: 'private', label: 'Private Secondary'}
    ],
    tvet: [
        {value: 'national', label: 'National TVET'},
        {value: 'county', label: 'County TVET'},
        {value: 'private', label: 'Private TVET'}
    ],
    private_academy: [
        {value: 'day', label: 'Day Academy'},
        {value: 'boarding', label: 'Boarding Academy'},
        {value: 'day_boarding', label: 'Day & Boarding Academy'}
    ],
    international: [
        {value: 'day', label: 'Day International'},
        {value: 'boarding', label: 'Boarding International'},
        {value: 'day_boarding', label: 'Day & Boarding International'}
    ]
};

function updateSubTypeOptions() {
    const schoolType = document.getElementById('school_type').value;
    const subTypeSelect = document.getElementById('school_sub_type');
    subTypeSelect.innerHTML = '<option value="">-- Optional --</option>';

    if (subTypeOptions[schoolType]) {
        subTypeOptions[schoolType].forEach(option => {
            const opt = document.createElement('option');
            opt.value = option.value;
            opt.textContent = option.label;
            subTypeSelect.appendChild(opt);
        });
    }
}

// Template card selection
document.querySelectorAll('.template-card').forEach(card => {
    card.addEventListener('click', function() {
        const radio = this.querySelector('input[type="radio"]');
        if (radio) {
            radio.checked = true;
            document.querySelectorAll('.template-card').forEach(c => c.classList.remove('selected'));
            this.classList.add('selected');
        }
    });
});

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    updateSubTypeOptions();
    
    // Set initial template selection
    const selectedTemplate = document.querySelector('input[name="data[template]"]:checked');
    if (selectedTemplate) {
        selectedTemplate.closest('.template-card').classList.add('selected');
    }
});

function submitForm(action) {
    const form = document.querySelector('form');
    const actionInput = form.querySelector('input[name="action"]');
    actionInput.value = action;
    form.submit();
}
</script>
</body>
</html>
