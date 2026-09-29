<?php
/**
 * M-Pesa Payment Integration for Kenyan Schools
 * Integrates with Safaricom Daraja API for subscription payments
 */

class MpesaIntegration {
    private $consumerKey;
    private $consumerSecret;
    private $passkey;
    private $shortcode;
    private $environment; // 'sandbox' or 'production'
    private $authToken;
    
    public function __construct($config = []) {
        $this->consumerKey = $config['consumer_key'] ?? '';
        $this->consumerSecret = $config['consumer_secret'] ?? '';
        $this->passkey = $config['passkey'] ?? '';
        $this->shortcode = $config['shortcode'] ?? '';
        $this->environment = $config['environment'] ?? 'sandbox';
    }
    
    /**
     * Get authentication token from Safaricom
     */
    public function getAuthToken() {
        if ($this->authToken) {
            return $this->authToken;
        }
        
        $url = $this->environment === 'production' 
            ? 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials'
            : 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
        
        $credentials = base64_encode($this->consumerKey . ':' . $this->consumerSecret);
        
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Authorization: Basic ' . $credentials]);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        
        if ($httpCode === 200) {
            $result = json_decode($response, true);
            $this->authToken = $result['access_token'] ?? null;
            return $this->authToken;
        }
        
        return null;
    }
    
    /**
     * Initiate STK Push for mobile payment
     */
    public function initiateSTKPush($phoneNumber, $amount, $reference, $description) {
        $token = $this->getAuthToken();
        if (!$token) {
            return ['success' => false, 'message' => 'Failed to get authentication token'];
        }
        
        $url = $this->environment === 'production'
            ? 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest'
            : 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
        
        $timestamp = date('YmdHis');
        $password = base64_encode($this->shortcode . $this->passkey . $timestamp);
        
        $payload = [
            'BusinessShortCode' => $this->shortcode,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => $amount,
            'PartyA' => $phoneNumber,
            'PartyB' => $this->shortcode,
            'PhoneNumber' => $phoneNumber,
            'CallBackURL' => $this->getCallbackUrl(),
            'AccountReference' => $reference,
            'TransactionDesc' => $description,
            'PartyB' => $this->shortcode
        ];
        
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ]);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        
        if ($httpCode === 200) {
            $result = json_decode($response, true);
            if (isset($result['ResponseCode']) && $result['ResponseCode'] === '0') {
                return [
                    'success' => true,
                    'merchantRequestID' => $result['MerchantRequestID'],
                    'checkoutRequestID' => $result['CheckoutRequestID'],
                    'responseCode' => $result['ResponseCode'],
                    'responseDescription' => $result['ResponseDescription']
                ];
            }
        }
        
        return ['success' => false, 'message' => 'STK Push failed', 'response' => $response];
    }
    
    /**
     * Process M-Pesa callback
     */
    public function processCallback($callbackData) {
        $resultCode = $callbackData['Body']['stkCallback']['ResultCode'] ?? null;
        $merchantRequestID = $callbackData['Body']['stkCallback']['MerchantRequestID'] ?? null;
        $checkoutRequestID = $callbackData['Body']['stkCallback']['CheckoutRequestID'] ?? null;
        
        if ($resultCode === '0') {
            // Payment successful
            $amount = $callbackData['Body']['stkCallback']['CallbackMetadata']['Item'][0]['Value'] ?? 0;
            $mpesaReceipt = $callbackData['Body']['stkCallback']['CallbackMetadata']['Item'][1]['Value'] ?? '';
            $transactionDate = $callbackData['Body']['stkCallback']['CallbackMetadata']['Item'][3]['Value'] ?? '';
            $phoneNumber = $callbackData['Body']['stkCallback']['CallbackMetadata']['Item'][4]['Value'] ?? '';
            
            return [
                'success' => true,
                'amount' => $amount,
                'mpesaReceipt' => $mpesaReceipt,
                'transactionDate' => $transactionDate,
                'phoneNumber' => $phoneNumber,
                'merchantRequestID' => $merchantRequestID,
                'checkoutRequestID' => $checkoutRequestID
            ];
        }
        
        return [
            'success' => false,
            'resultCode' => $resultCode,
            'resultDesc' => $callbackData['Body']['stkCallback']['ResultDesc'] ?? 'Payment failed',
            'merchantRequestID' => $merchantRequestID
        ];
    }
    
    /**
     * Query transaction status
     */
    public function queryTransactionStatus($checkoutRequestID) {
        $token = $this->getAuthToken();
        if (!$token) {
            return ['success' => false, 'message' => 'Failed to get authentication token'];
        }
        
        $url = $this->environment === 'production'
            ? 'https://api.safaricom.co.ke/mpesa/stkpushquery/v1/query'
            : 'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query';
        
        $timestamp = date('YmdHis');
        $password = base64_encode($this->shortcode . $this->passkey . $timestamp);
        
        $payload = [
            'BusinessShortCode' => $this->shortcode,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'CheckoutRequestID' => $checkoutRequestID,
            'PartyA' => $this->shortcode
        ];
        
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ]);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        
        if ($httpCode === 200) {
            $result = json_decode($response, true);
            return [
                'success' => true,
                'data' => $result
            ];
        }
        
        return ['success' => false, 'message' => 'Query failed'];
    }
    
    /**
     * Get callback URL (should be configured to point to your server)
     */
    private function getCallbackUrl() {
        // This should be configured to point to your server's callback endpoint
        // For example: https://yourdomain.com/mpesa_callback.php
        return 'https://yourdomain.com/mpesa_callback.php';
    }
    
    /**
     * Validate phone number (ensure it starts with 254 for Kenya)
     */
    public function validatePhoneNumber($phoneNumber) {
        // Remove any spaces, dashes, or plus signs
        $phone = preg_replace('/[^0-9]/', '', $phoneNumber);
        
        // If starts with 0, replace with 254
        if (strpos($phone, '0') === 0) {
            $phone = '254' . substr($phone, 1);
        }
        
        // If starts with 7, add 254
        if (strpos($phone, '7') === 0) {
            $phone = '254' . $phone;
        }
        
        // Validate Kenyan phone number format
        if (preg_match('/^2547[0-9]{8}$/', $phone)) {
            return $phone;
        }
        
        return null;
    }
}

/**
 * Record payment transaction in database
 */
function recordMpesaTransaction($schoolId, $transactionData) {
    require_once __DIR__ . '/db.php';
    
    try {
        $stmt = db()->prepare(
            'INSERT INTO mpesa_transactions (school_id, phone_number, amount, mpesa_receipt, transaction_date, merchant_request_id, checkout_request_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $schoolId,
            $transactionData['phoneNumber'],
            $transactionData['amount'],
            $transactionData['mpesaReceipt'],
            $transactionData['transactionDate'],
            $transactionData['merchantRequestID'],
            $transactionData['checkoutRequestID'],
            'completed'
        ]);
        
        return true;
    } catch (Throwable $e) {
        error_log('M-Pesa transaction recording failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Create M-Pesa transactions table if it doesn't exist
 */
function createMpesaTransactionsTable() {
    require_once __DIR__ . '/db.php';
    
    $sql = "
    CREATE TABLE IF NOT EXISTS mpesa_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        school_id INT NOT NULL,
        phone_number VARCHAR(15) NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        mpesa_receipt VARCHAR(50) NULL,
        transaction_date VARCHAR(50) NULL,
        merchant_request_id VARCHAR(50) NULL,
        checkout_request_id VARCHAR(50) NULL,
        status ENUM('pending', 'completed', 'failed') NOT NULL DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
        INDEX idx_school_status (school_id, status),
        INDEX idx_phone (phone_number)
    ) ENGINE=InnoDB;
    ";
    
    try {
        db()->exec($sql);
        return true;
    } catch (Throwable $e) {
        error_log('M-Pesa table creation failed: ' . $e->getMessage());
        return false;
    }
}
