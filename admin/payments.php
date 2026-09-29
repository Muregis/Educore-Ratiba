<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../db/mpesa_integration.php';

$schoolId = requireLoginAndGetSchoolId();
$stmt = db()->prepare('SELECT * FROM schools WHERE id = ?');
$stmt->execute([$schoolId]);
$school = $stmt->fetch();

// Create M-Pesa table if it doesn't exist
createMpesaTransactionsTable();

$action = $_GET['action'] ?? '';
$error = null;
$success = null;

// Handle payment initiation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'initiate_payment')) {
    $phoneNumber = trim($_POST['phone_number'] ?? '');
    $amount = (float) ($_POST['amount'] ?? 0);
    $reference = 'SCHOOL_' . $schoolId . '_' . time();
    $description = 'Subscription payment for ' . $school['name'];
    
    if ($phoneNumber === '' || $amount <= 0) {
        $error = 'Phone number and amount are required.';
    } else {
        // Validate phone number
        $mpesa = new MpesaIntegration([
            'consumer_key' => getenv('MPESA_CONSUMER_KEY') ?? '',
            'consumer_secret' => getenv('MPESA_CONSUMER_SECRET') ?? '',
            'passkey' => getenv('MPESA_PASSKEY') ?? '',
            'shortcode' => getenv('MPESA_SHORTCODE') ?? '',
            'environment' => getenv('MPESA_ENVIRONMENT') ?? 'sandbox'
        ]);
        
        $validatedPhone = $mpesa->validatePhoneNumber($phoneNumber);
        if (!$validatedPhone) {
            $error = 'Invalid phone number. Please use Kenyan format (e.g. 07XXXXXXXX or 2547XXXXXXXX).';
        } else {
            $result = $mpesa->initiateSTKPush($validatedPhone, $amount, $reference, $description);
            
            if ($result['success']) {
                // Record pending transaction
                try {
                    $stmt = db()->prepare(
                        'INSERT INTO mpesa_transactions (school_id, phone_number, amount, merchant_request_id, checkout_request_id, status) VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([
                        $schoolId,
                        $validatedPhone,
                        $amount,
                        $result['merchantRequestID'],
                        $result['checkoutRequestID'],
                        'pending'
                    ]);
                    
                    $success = 'Payment initiated successfully. Please check your phone to complete the payment.';
                } catch (Throwable $e) {
                    $error = 'Payment initiated but failed to record transaction: ' . $e->getMessage();
                }
            } else {
                $error = 'Failed to initiate payment: ' . $result['message'];
            }
        }
    }
}

// Get recent transactions
$stmt = db()->prepare('SELECT * FROM mpesa_transactions WHERE school_id = ? ORDER BY created_at DESC LIMIT 10');
$stmt->execute([$schoolId]);
$transactions = $stmt->fetchAll();

$pageTitle = 'M-Pesa Payments — ' . $school['name'];
require __DIR__ . '/_header.php';
?>

<div class="card">
    <h2>M-Pesa Payment Integration</h2>
    <p class="empty">Accept subscription payments via M-Pesa for Kenyan schools.</p>
    
    <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    
    <div style="background: #fef3c7; border: 1px solid #fde68a; border-radius: 8px; padding: 16px; margin-bottom: 24px;">
        <h4 style="margin: 0 0 8px; color: #92400e;">⚠️ Configuration Required</h4>
        <p style="margin: 0; font-size: 0.875rem; color: #92400e;">
            M-Pesa integration requires Safaricom Daraja API credentials. Set these environment variables:
            <code>MPESA_CONSUMER_KEY</code>, <code>MPESA_CONSUMER_SECRET</code>, <code>MPESA_PASSKEY</code>, <code>MPESA_SHORTCODE</code>, <code>MPESA_ENVIRONMENT</code>
        </p>
    </div>
    
    <form method="post" action="payments.php?action=initiate_payment">
        <label for="phone_number">Phone Number</label>
        <input type="tel" id="phone_number" name="phone_number" required placeholder="e.g. 0712345678 or 254712345678">
        
        <label for="amount">Amount (KES)</label>
        <input type="number" id="amount" name="amount" min="10" step="10" required placeholder="e.g. 1000">
        
        <button type="submit">Initiate Payment</button>
    </form>
</div>

<div class="card">
    <h2>Recent Transactions</h2>
    <?php if (empty($transactions)): ?>
        <p class="empty">No transactions yet.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Phone Number</th>
                    <th>Amount (KES)</th>
                    <th>M-Pesa Receipt</th>
                    <th>Status</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($transactions as $transaction): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($transaction['phone_number']); ?></td>
                        <td><?php echo number_format($transaction['amount'], 2); ?></td>
                        <td><?php echo htmlspecialchars($transaction['mpesa_receipt'] ?? '—'); ?></td>
                        <td>
                            <?php 
                            $statusColor = 'var(--text-muted)';
                            if ($transaction['status'] === 'completed') {
                                $statusColor = '#10b981';
                            } elseif ($transaction['status'] === 'failed') {
                                $statusColor = '#dc2626';
                            } elseif ($transaction['status'] === 'pending') {
                                $statusColor = '#f59e0b';
                            }
                            ?>
                            <span style="color: <?php echo $statusColor; ?>; font-weight: 600;">
                                <?php echo ucfirst($transaction['status']); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars(date('j M Y H:i', strtotime($transaction['created_at']))); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Payment Plans</h2>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
        <div style="border: 1px solid var(--border); border-radius: 8px; padding: 16px;">
            <h4 style="margin: 0 0 8px;">Basic Plan</h4>
            <div style="font-size: 1.5rem; font-weight: 700; color: var(--primary); margin-bottom: 8px;">KES 1,000<span style="font-size: 0.875rem; font-weight: 400; color: var(--text-muted);">/month</span></div>
            <p style="margin: 0; font-size: 0.875rem; color: var(--text-muted);">Up to 5 teachers, basic features</p>
        </div>
        <div style="border: 1px solid var(--border); border-radius: 8px; padding: 16px;">
            <h4 style="margin: 0 0 8px;">Standard Plan</h4>
            <div style="font-size: 1.5rem; font-weight: 700; color: var(--primary); margin-bottom: 8px;">KES 2,500<span style="font-size: 0.875rem; font-weight: 400; color: var(--text-muted);">/month</span></div>
            <p style="margin: 0; font-size: 0.875rem; color: var(--text-muted);">Up to 20 teachers, all features</p>
        </div>
        <div style="border: 1px solid var(--border); border-radius: 8px; padding: 16px;">
            <h4 style="margin: 0 0 8px;">Premium Plan</h4>
            <div style="font-size: 1.5rem; font-weight: 700; color: var(--primary); margin-bottom: 8px;">KES 5,000<span style="font-size: 0.875rem; font-weight: 400; color: var(--text-muted);">/month</span></div>
            <p style="margin: 0; font-size: 0.875rem; color: var(--text-muted);">Unlimited teachers, priority support</p>
        </div>
    </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
