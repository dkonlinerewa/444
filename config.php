<?php
// ============================================
// CONFIG.PHP - D K Associates Configuration
// ============================================

require_once __DIR__ . '/sitedefaults.php';

if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Error logging
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/error.log');
if (!file_exists(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0755, true);
}

// Base URL
if (!defined('BASE_URL')) {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    define('BASE_URL', $proto . '://' . $host . $dir);
}

// Timezone
date_default_timezone_set(DEFAULT_TIMEZONE);

// Database
define('DB_PATH', __DIR__ . '/dk_associates.db');

// Upload directories
define('UPLOAD_DIR', 'uploads/');
define('PHOTO_DIR', UPLOAD_DIR . 'photos/');
define('DOCUMENT_DIR', UPLOAD_DIR . 'documents/');
define('QR_DIR', UPLOAD_DIR . 'qr/');
define('RESUME_DIR', UPLOAD_DIR . 'resumes/');

// Create directories
foreach ([UPLOAD_DIR, PHOTO_DIR, DOCUMENT_DIR, QR_DIR, RESUME_DIR, __DIR__ . '/logs', __DIR__ . '/exports'] as $dir) {
    if (!file_exists($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Security
define('SESSION_TIMEOUT', 3600);
define('RATE_LIMIT', 60);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_TIME', 900);

// Email
define('SMTP_HOST', DEFAULT_SMTP_HOST);
define('SMTP_PORT', DEFAULT_SMTP_PORT);
define('SMTP_USER', DEFAULT_SMTP_USER);
define('SMTP_PASS', DEFAULT_SMTP_PASS);
define('FROM_EMAIL', DEFAULT_FROM_EMAIL);
define('FROM_NAME', DEFAULT_FROM_NAME);

function logError($message) {
    error_log(date('[Y-m-d H:i:s] ') . $message . PHP_EOL, 3, __DIR__ . '/logs/error.log');
}

function db() {
    global $db;
    return $db;
}

function logActivity($action, $details = '') {
    $db = db();
    if (!$db) return;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $user_id = $_SESSION['admin_id'] ?? 0;
    try {
        $stmt = $db->prepare("INSERT INTO activity_log (user_id, action, details, ip_address, user_agent) VALUES (:user_id, :action, :details, :ip, :user_agent)");
        $stmt->bindValue(':user_id', $user_id);
        $stmt->bindValue(':action', $action);
        $stmt->bindValue(':details', $details);
        $stmt->bindValue(':ip', $ip);
        $stmt->bindValue(':user_agent', $user_agent);
        $stmt->execute();
    } catch (Exception $e) {
        logError("Activity log error: " . $e->getMessage());
    }
}

function logAudit($db, $user_id, $action, $entity_type, $entity_id, $old_data, $new_data, $description) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $stmt = $db->prepare("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_data, new_data, description, ip_address, user_agent) VALUES (:user_id, :action, :entity_type, :entity_id, :old_data, :new_data, :description, :ip, :user_agent)");
        $stmt->bindValue(':user_id', $user_id);
        $stmt->bindValue(':action', $action);
        $stmt->bindValue(':entity_type', $entity_type);
        $stmt->bindValue(':entity_id', $entity_id);
        $stmt->bindValue(':old_data', is_string($old_data) ? $old_data : json_encode($old_data));
        $stmt->bindValue(':new_data', is_string($new_data) ? $new_data : json_encode($new_data));
        $stmt->bindValue(':description', $description);
        $stmt->bindValue(':ip', $ip);
        $stmt->bindValue(':user_agent', $user_agent);
        $stmt->execute();
    } catch (Exception $e) {
        logError("Audit log error: " . $e->getMessage());
    }
}

function sendNotification($user_id, $type, $title, $message, $action_url = '') {
    $db = db();
    if (!$db) return;
    try {
        $stmt = $db->prepare("INSERT INTO notifications (user_id, type, title, message, action_url) VALUES (:user_id, :type, :title, :message, :action_url)");
        $stmt->bindValue(':user_id', $user_id);
        $stmt->bindValue(':type', $type);
        $stmt->bindValue(':title', $title);
        $stmt->bindValue(':message', $message);
        $stmt->bindValue(':action_url', $action_url);
        $stmt->execute();
    } catch (Exception $e) {
        logError("Notification error: " . $e->getMessage());
    }
}

function getSetting($db, $key, $default = '') {
    try {
        $stmt = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
        if (!$stmt) return $default;
        $stmt->bindValue(1, $key);
        $result = $stmt->execute();
        if (!$result) return $default;
        $row = $result->fetchArray(SQLITE3_ASSOC);
        return $row ? $row['setting_value'] : $default;
    } catch (Exception $e) {
        logError("Get setting error for $key: " . $e->getMessage());
        return $default;
    }
}

function checkRateLimit($key, $limit = RATE_LIMIT, $period = 60) {
    $cache_dir = sys_get_temp_dir() . '/ratelimit/';
    if (!file_exists($cache_dir)) mkdir($cache_dir, 0755, true);
    $cache_file = $cache_dir . md5($key) . '.json';
    $data = [];
    if (file_exists($cache_file)) {
        $content = file_get_contents($cache_file);
        $data = json_decode($content, true) ?: [];
        $data = array_filter($data, function($time) use ($period) { return $time > time() - $period; });
    }
    if (count($data) >= $limit) return false;
    $data[] = time();
    file_put_contents($cache_file, json_encode($data));
    return true;
}

function validateCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function initializeDatabaseDefaults($db) {
    global $DEFAULT_SITE_SETTINGS, $DEFAULT_TEAM_MEMBERS, $DEFAULT_JOB_OPENINGS;
    try {
        foreach ($DEFAULT_SITE_SETTINGS as $setting) {
            $stmt = $db->prepare("INSERT OR IGNORE INTO site_settings (setting_key, setting_value, setting_type, setting_group, is_public) VALUES (?, ?, ?, ?, ?)");
            $stmt->bindValue(1, $setting[0]);
            $stmt->bindValue(2, $setting[1]);
            $stmt->bindValue(3, $setting[2]);
            $stmt->bindValue(4, $setting[3] ?? 'general');
            $stmt->bindValue(5, $setting[4] ?? 1);
            $stmt->execute();
        }
        $count = $db->querySingle("SELECT COUNT(*) FROM team_members");
        if ($count == 0 && isset($DEFAULT_TEAM_MEMBERS)) {
            foreach ($DEFAULT_TEAM_MEMBERS as $member) {
                $stmt = $db->prepare("INSERT INTO team_members (name, position, bio, photo_url, display_order, is_active) VALUES (?, ?, ?, ?, ?, 1)");
                $stmt->bindValue(1, $member[0]);
                $stmt->bindValue(2, $member[1]);
                $stmt->bindValue(3, $member[2]);
                $stmt->bindValue(4, $member[3]);
                $stmt->bindValue(5, $member[4]);
                $stmt->execute();
            }
        }
        $count = $db->querySingle("SELECT COUNT(*) FROM open_positions");
        if ($count == 0 && isset($DEFAULT_JOB_OPENINGS)) {
            foreach ($DEFAULT_JOB_OPENINGS as $job) {
                $stmt = $db->prepare("INSERT INTO open_positions (title, location, type, salary, description, requirements, urgent, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
                $stmt->bindValue(1, $job[0]);
                $stmt->bindValue(2, $job[1]);
                $stmt->bindValue(3, $job[2]);
                $stmt->bindValue(4, $job[3]);
                $stmt->bindValue(5, $job[4]);
                $stmt->bindValue(6, $job[5]);
                $stmt->bindValue(7, $job[6]);
                $stmt->execute();
            }
        }
        $count = $db->querySingle("SELECT COUNT(*) FROM admin_users");
        if ($count == 0) {
            $default_password = password_hash('admin123', PASSWORD_DEFAULT);
            $staff_id = 'ADMIN-' . date('Y') . '-' . rand(1000, 9999);
            $db->exec("INSERT INTO admin_users (username, password_hash, full_name, email, role, staff_id, is_active) VALUES ('admin', '$default_password', 'Administrator', 'admin@hidk.in', 'admin', '$staff_id', 1)");
        }
    } catch (Exception $e) {
        logError("Database initialization error: " . $e->getMessage());
    }
}

function ensureDatabaseSchema($db) {
    // ----------------------------------------------------------------
    // STEP 1 — Column migrations run OUTSIDE any transaction.
    // SQLite does not reliably allow ALTER TABLE inside BEGIN IMMEDIATE,
    // especially in WAL mode. We run these first, idempotently.
    // ----------------------------------------------------------------
    $columnMigrations = [
        "admin_users" => [
            'is_online'                => 'INTEGER DEFAULT 0',
            'last_activity'            => 'DATETIME',
            'current_chat_session'     => 'TEXT',
            'active_chats'             => 'INTEGER DEFAULT 0',
            'max_concurrent_chats'     => 'INTEGER DEFAULT 2',
            'care_permission'          => 'INTEGER DEFAULT 0',
            'hr_permission'            => 'INTEGER DEFAULT 0',
            'access_blocked'           => 'INTEGER DEFAULT 0',
            'id_card_verified'         => 'INTEGER DEFAULT 0',
            'tech_permission'          => 'INTEGER DEFAULT 0',
            'admin_permission'         => 'INTEGER DEFAULT 0',
            'supervisor_id'            => 'INTEGER DEFAULT 0',
            'reporting_head'           => 'INTEGER DEFAULT 0',
            'approval_status'          => "TEXT DEFAULT 'approved'",
            'designation'              => 'TEXT',
            'phone_primary'            => 'TEXT',
            'phone_secondary'          => 'TEXT',
            'address'                  => 'TEXT',
            'city'                     => 'TEXT',
            'state'                    => 'TEXT',
            'pincode'                  => 'TEXT',
            'date_of_birth'            => 'DATE',
            'date_of_joining'          => 'DATE',
            'blood_group'              => 'TEXT',
            'emergency_contact_name'   => 'TEXT',
            'emergency_contact_phone'  => 'TEXT',
            'last_login'               => 'DATETIME',
            'profile_photo'            => 'TEXT',
            'staff_id'                 => 'TEXT',
            'worker_id'                => 'TEXT',
        ],
        "tasks" => [
            'is_archived'  => 'INTEGER DEFAULT 0',
            'cancelled_at' => 'DATETIME',
        ],
        "chat_messages" => [
            'chat_type'   => "TEXT DEFAULT 'guest'",
            'sender_name' => 'TEXT',
        ],
        "chat_sessions" => [
            'assigned_at' => 'DATETIME',
        ],
        "applications" => [
            'resume_path'  => 'TEXT',
            'admin_status' => "TEXT DEFAULT 'pending'",
            'is_archived'  => 'INTEGER DEFAULT 0',
            'assigned_to'  => 'INTEGER',
            'notes'        => 'TEXT',
        ],
        "service_enquiries" => [
            'admin_status' => "TEXT DEFAULT 'pending'",
            'is_archived'  => 'INTEGER DEFAULT 0',
            'assigned_to'  => 'INTEGER',
            'notes'        => 'TEXT',
        ],
        "general_contacts" => [
            'admin_status' => "TEXT DEFAULT 'pending'",
            'is_archived'  => 'INTEGER DEFAULT 0',
            'assigned_to'  => 'INTEGER',
            'notes'        => 'TEXT',
        ],
    ];

    foreach ($columnMigrations as $table => $cols_to_add) {
        // Only migrate tables that already exist
        $tbl_exists = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'");
        if (!$tbl_exists) continue;

        $res = $db->query("PRAGMA table_info($table)");
        $existing_cols = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $existing_cols[] = $row['name'];
        }
        foreach ($cols_to_add as $col => $type) {
            if (!in_array($col, $existing_cols)) {
                try {
                    $db->exec("ALTER TABLE $table ADD COLUMN $col $type");
                } catch (Exception $e) {
                    // Column may already exist under a different check — safe to ignore
                    logError("Migration note [$table.$col]: " . $e->getMessage());
                }
            }
        }
    }

    // ----------------------------------------------------------------
    // STEP 2 — CREATE TABLE IF NOT EXISTS + indexes inside transaction.
    // These are safe inside a transaction because they are DDL that
    // SQLite handles atomically at schema level (no row data changes).
    // ----------------------------------------------------------------
    try {
        $db->exec("BEGIN IMMEDIATE TRANSACTION");

        $tables = [
            "admin_users" => "CREATE TABLE IF NOT EXISTS admin_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE,
                password_hash TEXT,
                email TEXT,
                full_name TEXT,
                role TEXT DEFAULT 'staff',
                designation TEXT,
                department TEXT,
                team_id INTEGER,
                manager_id INTEGER,
                phone_primary TEXT,
                phone_secondary TEXT,
                address TEXT,
                city TEXT,
                state TEXT,
                pincode TEXT,
                date_of_birth DATE,
                date_of_joining DATE,
                blood_group TEXT,
                emergency_contact_name TEXT,
                emergency_contact_phone TEXT,
                staff_id TEXT UNIQUE,
                worker_id TEXT UNIQUE,
                employee_code TEXT,
                profile_photo TEXT,
                qr_code TEXT,
                is_active INTEGER DEFAULT 1,
                is_online INTEGER DEFAULT 0,
                last_activity DATETIME,
                last_login DATETIME,
                current_chat_session TEXT,
                active_chats INTEGER DEFAULT 0,
                max_concurrent_chats INTEGER DEFAULT 2,
                care_permission INTEGER DEFAULT 0,
                hr_permission INTEGER DEFAULT 0,
                access_blocked INTEGER DEFAULT 0,
                id_card_verified INTEGER DEFAULT 0,
                tech_permission INTEGER DEFAULT 0,
                admin_permission INTEGER DEFAULT 0,
                supervisor_id INTEGER DEFAULT 0,
                reporting_head INTEGER DEFAULT 0,
                reporting_office TEXT,
                approval_status TEXT DEFAULT 'approved',
                failed_login_count INTEGER DEFAULT 0,
                last_failed_login DATETIME,
                remember_token TEXT,
                two_factor_secret TEXT,
                ip_whitelist TEXT,
                geo_override_lat REAL,
                geo_override_lng REAL,
                geo_override_radius INTEGER,
                monthly_salary REAL DEFAULT 0,
                daily_rate REAL DEFAULT 0,
                salary_basic REAL DEFAULT 0,
                salary_allowance REAL DEFAULT 0,
                salary_deductions REAL DEFAULT 0,
                bank_name TEXT,
                account_number TEXT,
                ifsc_code TEXT,
                upi_id TEXT,
                offer_letter TEXT,
                joining_letter TEXT,
                id_proof TEXT,
                banking_details TEXT,
                device_id TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME
            )",

            "contacts" => "CREATE TABLE IF NOT EXISTS contacts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                business_name TEXT,
                profession TEXT,
                locality TEXT,
                contact_number TEXT,
                notes TEXT,
                is_archived INTEGER DEFAULT 0,
                created_by INTEGER,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME
            )",

            "workers" => "CREATE TABLE IF NOT EXISTS workers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                worker_id TEXT UNIQUE,
                worker_code TEXT,
                name TEXT,
                father_name TEXT,
                dob DATE,
                gender TEXT,
                phone TEXT,
                email TEXT,
                address TEXT,
                skills TEXT,
                experience TEXT,
                qualification TEXT,
                photo_url TEXT,
                id_card_url TEXT,
                qr_code TEXT,
                documents TEXT,
                status TEXT DEFAULT 'active',
                assigned_manager INTEGER,
                rating REAL DEFAULT 0,
                blood_group TEXT,
                supervisor TEXT,
                reporting_head INTEGER DEFAULT 0,
                bank_details TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",

            "tasks" => "CREATE TABLE IF NOT EXISTS tasks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT,
                description TEXT,
                assigned_to INTEGER,
                assigned_by INTEGER,
                status TEXT DEFAULT 'pending',
                priority TEXT DEFAULT 'medium',
                due_date DATE,
                completed_at DATETIME,
                cancelled_at DATETIME,
                attachments TEXT,
                notes TEXT,
                is_archived INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME
            )",

            "task_comments" => "CREATE TABLE IF NOT EXISTS task_comments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                task_id INTEGER,
                user_id INTEGER,
                comment TEXT,
                change_type TEXT DEFAULT 'comment',
                old_value TEXT,
                new_value TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",

            "profile_update_requests" => "CREATE TABLE IF NOT EXISTS profile_update_requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                requested_changes TEXT,
                reason TEXT,
                status TEXT DEFAULT 'pending',
                reviewed_by INTEGER,
                reviewed_at DATETIME,
                review_notes TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",

            "chat_messages" => "CREATE TABLE IF NOT EXISTS chat_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id TEXT,
                sender_type TEXT,
                sender_name TEXT,
                sender_id INTEGER DEFAULT 0,
                receiver_id INTEGER DEFAULT 0,
                receiver_type TEXT,
                message TEXT,
                attachments TEXT,
                is_read INTEGER DEFAULT 0,
                chat_type TEXT DEFAULT 'guest',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                device_id TEXT,
                chat_stage TEXT,
                chat_data TEXT,
                is_queued INTEGER DEFAULT 0
            )",

            "chat_sessions" => "CREATE TABLE IF NOT EXISTS chat_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id TEXT UNIQUE,
                guest_name TEXT,
                guest_email TEXT,
                guest_phone TEXT,
                contact_reason TEXT,
                device_id TEXT,
                status TEXT DEFAULT 'active',
                assigned_to INTEGER DEFAULT 0,
                assigned_at DATETIME,
                last_activity DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",

            "applications" => "CREATE TABLE IF NOT EXISTS applications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                form_type TEXT,
                name TEXT,
                father_husband_name TEXT,
                dob TEXT,
                gender TEXT,
                marital_status TEXT,
                phone TEXT,
                email TEXT,
                current_address TEXT,
                permanent_address TEXT,
                qualification TEXT,
                experience TEXT,
                post_applied TEXT,
                computer_skills TEXT,
                availability TEXT,
                communication_skills TEXT,
                business_name TEXT,
                work_locality TEXT,
                skill_description TEXT,
                desired_location TEXT,
                desired_job_profile TEXT,
                current_job_role TEXT,
                notice_period TEXT,
                current_ctc TEXT,
                expected_ctc TEXT,
                resume_path TEXT,
                admin_status TEXT DEFAULT 'pending',
                is_archived INTEGER DEFAULT 0,
                assigned_to INTEGER,
                notes TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",

            "service_enquiries" => "CREATE TABLE IF NOT EXISTS service_enquiries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                phone TEXT,
                email TEXT,
                service_date TEXT,
                service_category TEXT,
                service_description TEXT,
                address TEXT,
                admin_status TEXT DEFAULT 'pending',
                is_archived INTEGER DEFAULT 0,
                assigned_to INTEGER,
                notes TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",

            "general_contacts" => "CREATE TABLE IF NOT EXISTS general_contacts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                email TEXT,
                phone TEXT,
                subject TEXT,
                message TEXT,
                admin_status TEXT DEFAULT 'pending',
                is_archived INTEGER DEFAULT 0,
                assigned_to INTEGER,
                notes TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",

            "open_positions" => "CREATE TABLE IF NOT EXISTS open_positions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT,
                location TEXT,
                type TEXT,
                salary TEXT,
                urgent INTEGER DEFAULT 0,
                description TEXT,
                requirements TEXT,
                is_active INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME
            )",

            "team_members" => "CREATE TABLE IF NOT EXISTS team_members (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                position TEXT,
                bio TEXT,
                photo_url TEXT,
                display_order INTEGER DEFAULT 0,
                is_active INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME
            )",

            "site_settings" => "CREATE TABLE IF NOT EXISTS site_settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                setting_key TEXT UNIQUE,
                setting_value TEXT,
                setting_type TEXT DEFAULT 'text',
                setting_group TEXT DEFAULT 'general',
                is_public INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME
            )",

            "audit_logs" => "CREATE TABLE IF NOT EXISTS audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                action TEXT,
                entity_type TEXT,
                entity_id INTEGER,
                old_data TEXT,
                new_data TEXT,
                description TEXT,
                ip_address TEXT,
                user_agent TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",

            "activity_log" => "CREATE TABLE IF NOT EXISTS activity_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                action TEXT,
                details TEXT,
                ip_address TEXT,
                user_agent TEXT,
                device_id TEXT,
                success INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",

            "notifications" => "CREATE TABLE IF NOT EXISTS notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                type TEXT,
                title TEXT,
                message TEXT,
                is_read INTEGER DEFAULT 0,
                action_url TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
        ];

        foreach ($tables as $name => $sql) {
            $db->exec($sql);
        }

        $indexes = [
            "CREATE INDEX IF NOT EXISTS idx_contacts_profession ON contacts(profession)",
            "CREATE INDEX IF NOT EXISTS idx_contacts_archived ON contacts(is_archived)",
            "CREATE INDEX IF NOT EXISTS idx_tasks_assigned ON tasks(assigned_to, status)",
            "CREATE INDEX IF NOT EXISTS idx_tasks_archived ON tasks(is_archived)",
            "CREATE INDEX IF NOT EXISTS idx_task_comments_task ON task_comments(task_id)",
            "CREATE INDEX IF NOT EXISTS idx_chat_messages_session ON chat_messages(session_id, created_at)",
            "CREATE INDEX IF NOT EXISTS idx_chat_sessions_status ON chat_sessions(status, assigned_to)",
            "CREATE INDEX IF NOT EXISTS idx_audit_logs ON audit_logs(created_at, entity_type)",
            "CREATE INDEX IF NOT EXISTS idx_applications_phone ON applications(phone)",
            "CREATE INDEX IF NOT EXISTS idx_service_enquiries_status ON service_enquiries(admin_status, is_archived)",
            "CREATE INDEX IF NOT EXISTS idx_general_contacts_status ON general_contacts(admin_status, is_archived)",
            "CREATE INDEX IF NOT EXISTS idx_open_positions_active ON open_positions(is_active, urgent)",
            "CREATE INDEX IF NOT EXISTS idx_profile_requests ON profile_update_requests(status)",
        ];

        foreach ($indexes as $sql) {
            $db->exec($sql);
        }

        initializeDatabaseDefaults($db);
        $db->exec("COMMIT TRANSACTION");

    } catch (Exception $e) {
        try { $db->exec("ROLLBACK TRANSACTION"); } catch (Exception $re) {}
        logError("Database schema error: " . $e->getMessage());
    }
}
try {
    $dataDir = dirname(DB_PATH);
    if (!file_exists($dataDir)) mkdir($dataDir, 0755, true);
    
    $db = new SQLite3(DB_PATH);
    $db->enableExceptions(true);
    $db->busyTimeout(5000);
    $db->exec("PRAGMA journal_mode = WAL");
    $db->exec("PRAGMA foreign_keys = ON");
    
    ensureDatabaseSchema($db);
    
} catch (Exception $e) {
    logError("Database connection error: " . $e->getMessage());
    die("Fatal Error: Could not connect to SQLite database. Please check error logs.");
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['device_id'])) {
    if (isset($_COOKIE['device_id'])) {
        $_SESSION['device_id'] = $_COOKIE['device_id'];
    } else {
        $_SESSION['device_id'] = bin2hex(random_bytes(16));
        setcookie('device_id', $_SESSION['device_id'], time() + 86400 * 30, '/', '', true, true);
    }
}
?>