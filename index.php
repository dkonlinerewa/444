<?php
// ============================================
// INDEX.PHP - D K Associates Main Website
// Presentation Layer Only - All DB logic in config.php
// ============================================

// Check if config file exists
if (!file_exists('config.php')) {
    die('Configuration file not found.');
}

// Load configuration (which includes database initialization)
require_once 'config.php';

// Now database is initialized and connected via $db global
global $db;

// Helper function to get setting with fallback to defined constants
function getSiteSetting($key, $defaultConstant = null) {
    global $db;
    
    // Try to get from database first
    $value = getSetting($db, $key);
    
    // If not found in database and default constant provided, use that
    if ($value === '' && $defaultConstant && defined($defaultConstant)) {
        return constant($defaultConstant);
    }
    
    // Return database value or empty string
    return $value;
}

// Get site settings with fallbacks to defaults
$site_logo = getSiteSetting('site_logo', 'DEFAULT_SITE_LOGO');
$site_favicon = getSiteSetting('site_favicon', 'DEFAULT_SITE_FAVICON');
$site_title = getSiteSetting('site_title', 'DEFAULT_SITE_TITLE');
$site_tagline = getSiteSetting('site_tagline', 'DEFAULT_SITE_TAGLINE');
$company_phone = getSiteSetting('company_phone', 'DEFAULT_COMPANY_PHONE');
$company_whatsapp = getSiteSetting('company_whatsapp', 'DEFAULT_COMPANY_WHATSAPP');
$company_email = getSiteSetting('company_email', 'DEFAULT_COMPANY_EMAIL');
$company_address = getSiteSetting('company_address', 'DEFAULT_COMPANY_ADDRESS');
$company_hours = getSiteSetting('company_hours', 'DEFAULT_COMPANY_HOURS');
$founded_year = getSiteSetting('founded_year', 'DEFAULT_FOUNDED_YEAR');

// Social media settings
$facebook_url = getSiteSetting('facebook_url', 'DEFAULT_FACEBOOK_URL');
$twitter_url = getSiteSetting('twitter_url', 'DEFAULT_TWITTER_URL');
$instagram_url = getSiteSetting('instagram_url', 'DEFAULT_INSTAGRAM_URL');
$linkedin_url = getSiteSetting('linkedin_url', 'DEFAULT_LINKEDIN_URL');
$youtube_url = getSiteSetting('youtube_url', 'DEFAULT_YOUTUBE_URL');
$enable_social_links = getSiteSetting('enable_social_links', 'DEFAULT_ENABLE_SOCIAL_LINKS');

// Check if staff with care permission are online for chat
$staff_online = false;
try {
    $staff_online = $db->querySingle("SELECT COUNT(*) FROM admin_users 
                                      WHERE care_permission = 1 
                                      AND is_online = 1 
                                      AND last_activity > datetime('now', '-5 minutes')") > 0;
} catch (Exception $e) {
    $staff_online = false;
}

// Visitor Counter Class
class VisitorCounter {
    private $dataFile = 'data/visitor_data.json';
    private $onlineFile = 'data/online_users.json';
    private $onlineTimeout = 300; // 5 minutes
    private $ip;

    public function __construct() {
        $this->ip = $this->getVisitorIP();
        $this->initializeFiles();
    }

    private function getVisitorIP() {
        $ip = '';
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) $ip = $_SERVER['HTTP_CLIENT_IP'];
        elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        else $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        return $ip;
    }

    private function initializeFiles() {
        $defaultData = ['total' => 0, 'today' => 0, 'total_visits' => 0, 'today_visits' => 0, 'date' => date('Y-m-d'), 'unique_ips' => []];
        
        if (!file_exists($this->dataFile)) {
            if (!is_dir('data')) mkdir('data', 0755, true);
            file_put_contents($this->dataFile, json_encode($defaultData));
        }
        
        if (!file_exists($this->onlineFile)) {
            if (!is_dir('data')) mkdir('data', 0755, true);
            file_put_contents($this->onlineFile, json_encode(['users' => []]));
        }
    }

    public function updateCounters() {
        if (!file_exists($this->dataFile)) return;
        
        $data = json_decode(file_get_contents($this->dataFile), true);
        $today = date('Y-m-d');

        if ($data['date'] !== $today) {
            $data['today'] = 0;
            $data['today_visits'] = 0;
            $data['date'] = $today;
            $data['unique_ips'] = [];
        }

        $data['total_visits']++;
        $data['today_visits']++;

        if (!in_array($this->ip, $data['unique_ips'])) {
            $data['unique_ips'][] = $this->ip;
            $data['today']++;
            $data['total']++;
        }

        file_put_contents($this->dataFile, json_encode($data));
        $this->updateOnlineUsers();
    }

    private function updateOnlineUsers() {
        if (!file_exists($this->onlineFile)) return;
        
        $data = json_decode(file_get_contents($this->onlineFile), true);
        $currentTime = time();
        $validUsers = [];

        foreach ($data['users'] as $user) {
            if (($currentTime - $user['last_seen']) < $this->onlineTimeout) {
                $validUsers[] = $user;
            }
        }

        $userFound = false;
        foreach ($validUsers as &$user) {
            if ($user['ip'] === $this->ip) {
                $user['last_seen'] = $currentTime;
                $userFound = true;
                break;
            }
        }

        if (!$userFound) {
            $validUsers[] = [
                'ip' => $this->ip, 
                'last_seen' => $currentTime, 
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            ];
        }

        $data['users'] = $validUsers;
        file_put_contents($this->onlineFile, json_encode($data));
    }

    public function getCounterData() {
        $data = ['total' => 0, 'today' => 0, 'total_visits' => 0, 'today_visits' => 0];
        $onlineCount = 0;
        
        if (file_exists($this->dataFile)) {
            $data = json_decode(file_get_contents($this->dataFile), true);
        }
        
        if (file_exists($this->onlineFile)) {
            $onlineData = json_decode(file_get_contents($this->onlineFile), true);
            $currentTime = time();
            
            foreach ($onlineData['users'] as $user) {
                if (($currentTime - $user['last_seen']) < $this->onlineTimeout) $onlineCount++;
            }
        }

        return [
            'total' => number_format($data['total']),
            'today' => number_format($data['today']),
            'online' => number_format($onlineCount),
            'total_visits' => number_format($data['total_visits']),
            'today_visits' => number_format($data['today_visits'])
        ];
    }
}

$visitorCounter = new VisitorCounter();
$visitorCounter->updateCounters();
$counterData = $visitorCounter->getCounterData();

// CSRF token is already set in config.php

// Handle form submissions
$form_submitted = false;
$form_success = false;
$form_message = '';
$form_type = '';

// Rate limiting for form submissions
$rate_limit_key = 'form_' . $_SERVER['REMOTE_ADDR'];
if (!checkRateLimit($rate_limit_key, 10, 60)) { // 10 submissions per minute max
    $form_submitted = true;
    $form_message = "Too many submissions. Please try again later.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && validateCsrfToken($_POST['csrf_token'])) {
    $form_submitted = true;
    $form_type = $_POST['form_type'] ?? '';

    try {
        // Handle file upload for resume
        $resume_path = null;
        if (isset($_FILES['resume']) && $_FILES['resume']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
            $file_type = $_FILES['resume']['type'];
            
            if (in_array($file_type, $allowed_types)) {
                $max_size = 5 * 1024 * 1024; // 5MB
                if ($_FILES['resume']['size'] <= $max_size) {
                    $ext = pathinfo($_FILES['resume']['name'], PATHINFO_EXTENSION);
                    $filename = 'resume_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    $upload_path = RESUME_DIR . $filename;
                    
                    if (move_uploaded_file($_FILES['resume']['tmp_name'], $upload_path)) {
                        $resume_path = $upload_path;
                    }
                } else {
                    throw new Exception("File size exceeds 5MB limit");
                }
            } else {
                throw new Exception("Invalid file type. Only PDF, DOC, DOCX allowed");
            }
        }

        if ($form_type === 'permanent') {
            $name = htmlspecialchars(trim($_POST['name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $phone = htmlspecialchars(trim($_POST['phone'] ?? ''), ENT_QUOTES, 'UTF-8');
            $post_applied = htmlspecialchars(trim($_POST['post_applied'] ?? ''), ENT_QUOTES, 'UTF-8');
            
            if (empty($name) || empty($phone) || empty($post_applied)) {
                $form_message = "Please fill in all required fields.";
            } elseif (!preg_match('/^[0-9]{10}$/', $phone)) {
                $form_message = "Please enter a valid 10-digit phone number.";
            } else {
                $stmt = $db->prepare("INSERT INTO applications (form_type, name, father_husband_name, dob, gender, marital_status, phone, email, current_address, permanent_address, qualification, experience, post_applied, computer_skills, availability, communication_skills, resume_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bindValue(1, 'permanent');
                $stmt->bindValue(2, $name);
                $stmt->bindValue(3, $_POST['father_husband_name'] ?? '');
                $stmt->bindValue(4, $_POST['dob'] ?? '');
                $stmt->bindValue(5, $_POST['gender'] ?? '');
                $stmt->bindValue(6, $_POST['marital_status'] ?? '');
                $stmt->bindValue(7, $phone);
                $stmt->bindValue(8, $_POST['email'] ?? '');
                $stmt->bindValue(9, $_POST['current_address'] ?? '');
                $stmt->bindValue(10, $_POST['permanent_address'] ?? '');
                $stmt->bindValue(11, $_POST['qualification'] ?? '');
                $stmt->bindValue(12, $_POST['experience'] ?? '');
                $stmt->bindValue(13, $post_applied);
                $stmt->bindValue(14, $_POST['computer_skills'] ?? '');
                $stmt->bindValue(15, $_POST['availability'] ?? '');
                $skills = isset($_POST['communication_skills']) ? implode(',', $_POST['communication_skills']) : '';
                $stmt->bindValue(16, $skills);
                $stmt->bindValue(17, $resume_path);
                $stmt->execute();
                
                $form_success = true;
                $form_message = "Application submitted successfully! We'll contact you soon.";
                if ($resume_path) {
                    $form_message .= " Your resume has been uploaded.";
                }
            }
            
        } elseif ($form_type === 'skilled') {
            $name = htmlspecialchars(trim($_POST['name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $phone = htmlspecialchars(trim($_POST['phone'] ?? ''), ENT_QUOTES, 'UTF-8');
            
            if (empty($name) || empty($phone)) {
                $form_message = "Please fill in all required fields.";
            } elseif (!preg_match('/^[0-9]{10}$/', $phone)) {
                $form_message = "Please enter a valid 10-digit phone number.";
            } else {
                $stmt = $db->prepare("INSERT INTO applications (form_type, name, father_husband_name, dob, gender, marital_status, phone, email, current_address, permanent_address, qualification, experience, business_name, work_locality, skill_description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bindValue(1, 'skilled');
                $stmt->bindValue(2, $name);
                $stmt->bindValue(3, $_POST['father_husband_name'] ?? '');
                $stmt->bindValue(4, $_POST['dob'] ?? '');
                $stmt->bindValue(5, $_POST['gender'] ?? '');
                $stmt->bindValue(6, $_POST['marital_status'] ?? '');
                $stmt->bindValue(7, $phone);
                $stmt->bindValue(8, $_POST['email'] ?? '');
                $stmt->bindValue(9, $_POST['current_address'] ?? '');
                $stmt->bindValue(10, $_POST['permanent_address'] ?? '');
                $stmt->bindValue(11, $_POST['qualification'] ?? '');
                $stmt->bindValue(12, $_POST['experience'] ?? '');
                $stmt->bindValue(13, $_POST['business_name'] ?? '');
                $stmt->bindValue(14, $_POST['work_locality'] ?? '');
                $stmt->bindValue(15, $_POST['skill_description'] ?? '');
                $stmt->execute();
                
                $form_success = true;
                $form_message = "Registration submitted successfully! We'll help grow your business.";
            }
            
        } elseif ($form_type === 'placement') {
            $name = htmlspecialchars(trim($_POST['name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $phone = htmlspecialchars(trim($_POST['phone'] ?? ''), ENT_QUOTES, 'UTF-8');
            $email = htmlspecialchars(trim($_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8');
            
            if (empty($name) || empty($phone) || empty($email)) {
                $form_message = "Please fill in all required fields.";
            } elseif (!preg_match('/^[0-9]{10}$/', $phone)) {
                $form_message = "Please enter a valid 10-digit phone number.";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $form_message = "Please enter a valid email address.";
            } else {
                $location = isset($_POST['desired_location']) ? implode(',', $_POST['desired_location']) : '';
                
                $stmt = $db->prepare("INSERT INTO applications (form_type, name, father_husband_name, dob, gender, marital_status, phone, email, current_address, permanent_address, qualification, experience, desired_location, desired_job_profile, current_job_role, notice_period, current_ctc, expected_ctc, resume_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bindValue(1, 'placement');
                $stmt->bindValue(2, $name);
                $stmt->bindValue(3, $_POST['father_husband_name'] ?? '');
                $stmt->bindValue(4, $_POST['dob'] ?? '');
                $stmt->bindValue(5, $_POST['gender'] ?? '');
                $stmt->bindValue(6, $_POST['marital_status'] ?? '');
                $stmt->bindValue(7, $phone);
                $stmt->bindValue(8, $email);
                $stmt->bindValue(9, $_POST['current_address'] ?? '');
                $stmt->bindValue(10, $_POST['permanent_address'] ?? '');
                $stmt->bindValue(11, $_POST['qualification'] ?? '');
                $stmt->bindValue(12, $_POST['experience'] ?? '');
                $stmt->bindValue(13, $location);
                $stmt->bindValue(14, $_POST['desired_job_profile'] ?? '');
                $stmt->bindValue(15, $_POST['current_job_role'] ?? '');
                $stmt->bindValue(16, $_POST['notice_period'] ?? '');
                $stmt->bindValue(17, $_POST['current_ctc'] ?? '');
                $stmt->bindValue(18, $_POST['expected_ctc'] ?? '');
                $stmt->bindValue(19, $resume_path);
                $stmt->execute();
                
                $form_success = true;
                $form_message = "Placement request submitted successfully!";
                if ($resume_path) {
                    $form_message .= " Your resume has been uploaded.";
                }
            }
            
        } elseif (isset($_POST['service_enquiry'])) {
            $name = htmlspecialchars(trim($_POST['name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $phone = htmlspecialchars(trim($_POST['phone'] ?? ''), ENT_QUOTES, 'UTF-8');
            
            if (empty($name)) {
                $form_message = "Please enter your name.";
            } elseif (!preg_match('/^[0-9]{10}$/', $phone)) {
                $form_message = "Please enter a valid 10-digit phone number.";
            } else {
                $stmt = $db->prepare("INSERT INTO service_enquiries (name, phone, email, service_date, service_category, service_description, address) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bindValue(1, $name);
                $stmt->bindValue(2, $phone);
                $stmt->bindValue(3, $_POST['email'] ?? '');
                $stmt->bindValue(4, $_POST['service_date'] ?? '');
                $stmt->bindValue(5, $_POST['service_category'] ?? '');
                $stmt->bindValue(6, $_POST['service_description'] ?? '');
                $stmt->bindValue(7, $_POST['address'] ?? '');
                $stmt->execute();
                
                $form_success = true;
                $form_message = "Service enquiry submitted! We'll contact you within 15 minutes.";
            }
            
        } elseif (isset($_POST['general_contact'])) {
            $name = htmlspecialchars(trim($_POST['name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $email = htmlspecialchars(trim($_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8');
            
            if (empty($name)) {
                $form_message = "Please enter your name.";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $form_message = "Please enter a valid email address.";
            } else {
                $stmt = $db->prepare("INSERT INTO general_contacts (name, email, phone, subject, message) VALUES (?, ?, ?, ?, ?)");
                $stmt->bindValue(1, $name);
                $stmt->bindValue(2, $email);
                $stmt->bindValue(3, $_POST['phone'] ?? '');
                $stmt->bindValue(4, $_POST['subject'] ?? '');
                $stmt->bindValue(5, $_POST['message'] ?? '');
                $stmt->execute();
                
                $form_success = true;
                $form_message = "Message sent! We'll respond within 24 hours.";
            }
        }
        
    } catch (Exception $e) {
        $form_message = "Unable to process your request. Please try again.";
        logError("Form submission error: " . $e->getMessage());
    }

    // Regenerate CSRF token after form submission
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$activeTab = $_GET['tab'] ?? 'home';
$activeBusinessTab = $_GET['sub'] ?? 'features';
$activeJobsTab = $_GET['sub'] ?? 'openings';
$activeContactTab = $_GET['sub'] ?? 'service';
$currentYear = date("Y");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($site_title); ?> - <?php echo htmlspecialchars($site_tagline); ?></title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($site_favicon); ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: linear-gradient(145deg, #f6f9fc 0%, #eef2f6 100%);
            min-height: 100vh;
            color: #1e293b;
            overflow-x: hidden;
            width: 100%;
        }

        :root {
            --primary: #0f3b5e;
            --primary-light: #1a4b73;
            --primary-dark: #0a2c45;
            --secondary: #e67e22;
            --secondary-light: #f39c12;
            --accent: #2ecc71;
            --text-dark: #1e293b;
            --text-light: #64748b;
            --bg-light: #ffffff;
            --bg-offwhite: #f8fafc;
            --shadow-sm: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-md: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            --shadow-lg: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            --gradient-primary: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            --gradient-secondary: linear-gradient(135deg, var(--secondary) 0%, var(--secondary-light) 100%);
            --gradient-accent: linear-gradient(135deg, #3498db 0%, #2ecc71 100%);
            --gradient-gold: linear-gradient(135deg, #f1c40f 0%, #e67e22 100%);
            --gradient-sunset: linear-gradient(135deg, #e74c3c 0%, #f39c12 100%);
        }
        
        .container {
            width: 100%;
            max-width: 1320px;
            margin: 0 auto;
            padding: 0 15px;
            box-sizing: border-box;
        }

        .navbar-custom {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            box-shadow: var(--shadow-sm);
            padding: 1rem 0;
            transition: all 0.3s ease;
            z-index: 1000;
        }

        .navbar-custom.scrolled {
            padding: 0.5rem 0;
            background: rgba(255, 255, 255, 0.98);
        }

        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }
        
        .row {
            display: flex;
            flex-wrap: wrap;
            margin-right: -15px;
            margin-left: -15px;
        }

        .col-md-6, .col-md-4, .col-md-3, .col-12 {
            position: relative;
            width: 100%;
            padding-right: 15px;
            padding-left: 15px;
            box-sizing: border-box;
        }

        @media (min-width: 768px) {
            .col-md-3 { flex: 0 0 25%; max-width: 25%; }
            .col-md-4 { flex: 0 0 33.333333%; max-width: 33.333333%; }
            .col-md-6 { flex: 0 0 50%; max-width: 50%; }
        }

        .col-12 { flex: 0 0 100%; max-width: 100%; }

        .logo-container {
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--gradient-primary);
            border-radius: 12px;
            color: white;
            font-size: 1.8rem;
        }

        .logo-container img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 8px;
        }

        .brand-text {
            font-weight: 800;
            font-size: 1.8rem;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: -0.5px;
            line-height: 1.2;
        }

        .brand-tagline {
            font-size: 0.75rem;
            color: var(--text-light);
            letter-spacing: 1px;
            text-transform: uppercase;
            margin-top: -5px;
            -webkit-text-fill-color: initial;
        }

        .nav-link-custom {
            font-weight: 600;
            color: var(--text-dark) !important;
            padding: 0.5rem 1rem !important;
            margin: 0 0.2rem;
            border-radius: 8px;
            transition: all 0.3s ease;
            position: relative;
        }

        .nav-link-custom:hover,
        .nav-link-custom.active {
            background: var(--gradient-primary);
            color: white !important;
            transform: translateY(-2px);
        }

        .nav-link-custom i {
            margin-right: 6px;
            font-size: 1.1rem;
        }

        .staff-online-badge {
            background: #10b981;
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-left: 10px;
        }

        @media (max-width: 991px) {
            .navbar-collapse {
                max-height: 80vh;
                overflow-y: auto;
                padding: 1rem 0;
            }
            
            .nav-link-custom {
                padding: 0.75rem 1rem !important;
                margin: 0.25rem 0;
            }
            
            .navbar-nav .counter-badge {
                margin-top: 1rem;
                width: 100%;
                text-align: center;
                justify-content: center;
            }
        }

        .counter-badge {
            background: var(--gradient-gold);
            color: white;
            padding: 0.5rem 1.2rem;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.9rem;
            box-shadow: var(--shadow-sm);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .counter-badge i {
            font-size: 1rem;
        }

        .hero-section {
            background: linear-gradient(145deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            padding: 4rem 4px 2rem;
            margin-bottom: 2rem;
            border-radius: 0 0 3rem 3rem;
            position: relative;
            overflow: hidden;
        }

        .hero-section::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            bottom: 0;
            left: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" opacity="0.1"><text x="10" y="20" font-size="12" fill="white">WORKFORCE</text><text x="30" y="50" font-size="16" fill="white">EVERYDAY HELP</text><text x="50" y="80" font-size="20" fill="white">DK</text></svg>') repeat;
            pointer-events: none;
        }

        .hero-title {
            font-size: 3.5rem;
            font-weight: 800;
            line-height: 1.2;
            margin-bottom: 1rem;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }

        .hero-subtitle {
            font-size: 1.25rem;
            opacity: 0.95;
            margin-bottom: 2rem;
        }

        .hero-badge {
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(5px);
            padding: 0.5rem 1.5rem;
            border-radius: 50px;
            display: inline-block;
            margin-bottom: 1.5rem;
            font-weight: 600;
        }

        .card-modern {
            background: white;
            border: none;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }

        .card-modern:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow-lg);
        }

        .card-header-gradient {
            background: var(--gradient-primary);
            padding: 2rem 1.5rem;
            color: white;
            position: relative;
            overflow: hidden;
        }

        .card-header-gradient::after {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.2) 0%, transparent 70%);
            opacity: 0.5;
        }

        .card-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }

        .card-body-modern {
            padding: 1.75rem;
        }

        .service-card {
            background: white;
            border-radius: 20px;
            padding: 2rem 1.5rem;
            text-align: center;
            transition: all 0.3s ease;
            border: 2px solid transparent;
            height: 100%;
        }

        .service-card:hover {
            border-color: var(--primary);
            transform: scale(1.02);
            box-shadow: var(--shadow-md);
        }

        .service-icon-wrapper {
            width: 80px;
            height: 80px;
            background: var(--gradient-primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            color: white;
            font-size: 2rem;
            transition: all 0.3s ease;
        }

        .service-card:hover .service-icon-wrapper {
            transform: rotate(360deg);
        }

        .form-floating-custom {
            margin-bottom: 1.5rem;
        }

        .form-floating-custom input,
        .form-floating-custom select,
        .form-floating-custom textarea {
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            padding: 1rem 1.25rem;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
            width: 100%;
            min-height: 54px;
            box-sizing: border-box;
        }

        .form-floating-custom input:focus,
        .form-floating-custom select:focus,
        .form-floating-custom textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(15, 59, 94, 0.1);
            outline: none;
        }

        .form-floating-custom label {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
            display: block;
        }

        .btn-primary-custom {
            width: 100%;
            background: var(--gradient-primary);
            color: white;
            border: none;
            border-radius: 16px;
            padding: 1rem 2rem;
            font-weight: 700;
            font-size: 1.1rem;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .btn-primary-custom::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }

        .btn-primary-custom:hover::before {
            width: 300px;
            height: 300px;
        }

        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-primary-custom,
        .btn-outline-custom {
            width: 100%;
            min-height: 54px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            white-space: nowrap;
        }

        .btn-outline-custom {
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
            border-radius: 16px;
            padding: 1rem 2rem;
            font-weight: 700;
            font-size: 1.1rem;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .btn-outline-custom:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        @media (max-width: 768px) {
            .btn-primary-custom,
            .btn-outline-custom {
                width: 100%;
                margin-bottom: 10px;
            }
        }

        .job-card {
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            border-left: 6px solid var(--primary);
            transition: all 0.3s ease;
            box-shadow: var(--shadow-sm);
        }

        .job-card:hover {
            transform: translateX(8px);
            box-shadow: var(--shadow-md);
        }

        .urgent-badge {
            background: var(--gradient-sunset);
            color: white;
            padding: 0.25rem 1rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
        }

        .team-card {
            background: white;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            transition: all 0.3s ease;
        }

        .team-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow-lg);
        }

        .team-img {
            width: 100%;
            height: 250px;
            object-fit: cover;
            transition: all 0.5s ease;
        }

        .team-card:hover .team-img {
            transform: scale(1.05);
        }

        .team-info {
            padding: 1.5rem;
            text-align: center;
            background: linear-gradient(to top, white, #f8fafc);
        }

        .team-name {
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 0.25rem;
        }

        .team-position {
            color: var(--secondary);
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 1rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .chat-widget {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 380px;
            max-height: calc(100vh - 60px);
            background: white;
            border-radius: 24px;
            box-shadow: var(--shadow-lg);
            display: none;
            z-index: 9999;
            overflow: hidden;
            flex-direction: column;
        }

        .chat-widget.show {
            display: flex;
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .chat-header {
            background: var(--gradient-primary);
            color: white;
            padding: 1rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .chat-header h5 {
            margin: 0;
            font-weight: 700;
            font-size: 1rem;
        }

        .chat-header .online-indicator {
            width: 8px;
            height: 8px;
            background: #10b981;
            border-radius: 50%;
            display: inline-block;
            margin-left: 5px;
        }

        .chat-messages-container {
            flex: 1;
            overflow-y: auto;
            padding: 1.5rem;
            background: #f8fafc;
            max-height: 350px;
        }

        .chat-message {
            margin-bottom: 1rem;
            display: flex;
            flex-direction: column;
        }

        .chat-message.guest {
            align-items: flex-end;
        }

        .chat-message.admin {
            align-items: flex-start;
        }

        .message-bubble {
            max-width: 80%;
            padding: 0.75rem 1rem;
            border-radius: 20px;
            position: relative;
            word-wrap: break-word;
        }

        .guest .message-bubble {
            background: var(--gradient-primary);
            color: white;
            border-bottom-right-radius: 4px;
        }

        .admin .message-bubble {
            background: white;
            color: var(--text-dark);
            border-bottom-left-radius: 4px;
            box-shadow: var(--shadow-sm);
        }

        .message-time {
            font-size: 0.7rem;
            color: var(--text-light);
            margin-top: 4px;
        }

        .chat-input-area {
            padding: 1rem;
            border-top: 2px solid #e2e8f0;
            display: flex;
            gap: 0.5rem;
            background: white;
        }

        .chat-input-area input {
            flex: 1;
            border: 2px solid #e2e8f0;
            border-radius: 30px;
            padding: 0.75rem 1.25rem;
            outline: none;
        }

        .chat-input-area input:focus {
            border-color: var(--primary);
        }

        .chat-send-btn {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--gradient-primary);
            color: white;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            transition: all 0.3s ease;
        }

        .chat-send-btn:hover {
            transform: scale(1.1);
        }

        .chat-toggle {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 65px;
            height: 65px;
            border-radius: 50%;
            background: var(--gradient-gold);
            color: white;
            border: none;
            box-shadow: var(--shadow-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            cursor: pointer;
            transition: all 0.3s ease;
            z-index: 9998;
        }

        .chat-toggle:hover {
            transform: scale(1.1);
        }

        .chat-toggle .online-badge {
            position: absolute;
            top: 0;
            right: 0;
            width: 15px;
            height: 15px;
            border-radius: 50%;
            background: #10b981;
            border: 2px solid white;
        }

        .chat-init-form {
            padding: 1.5rem;
        }

        .quick-contact-popup {
            position: fixed;
            bottom: 110px;
            right: 30px;
            width: 300px;
            background: white;
            border-radius: 20px;
            box-shadow: var(--shadow-lg);
            display: none;
            z-index: 9997;
            overflow: hidden;
        }

        .quick-contact-popup.show {
            display: block;
            animation: slideUp 0.3s ease;
        }

        .quick-contact-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.5rem;
            transition: all 0.3s ease;
            text-decoration: none;
            color: var(--text-dark);
            border-bottom: 1px solid #e2e8f0;
        }

        .quick-contact-item:hover {
            background: #f8fafc;
            transform: translateX(8px);
        }

        .quick-contact-item i {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.25rem;
        }

        .footer {
            background: var(--primary-dark);
            color: white;
            padding: 4rem 0 2rem;
            margin-top: 4rem;
            border-radius: 3rem 3rem 0 0;
        }

        .footer-logo-container {
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            margin-bottom: 1rem;
        }

        .footer-logo-container img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 8px;
        }

        .footer-logo-container span {
            font-size: 2rem;
            color: white;
        }

        .footer h5 {
            font-weight: 700;
            margin-bottom: 1.5rem;
            color: white;
        }

        .footer-links {
            list-style: none;
            padding: 0;
        }

        .footer-links li {
            margin-bottom: 0.75rem;
        }

        .footer-links a {
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .footer-links a:hover {
            color: white;
            transform: translateX(5px);
            display: inline-block;
        }

        .social-links {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .social-links a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            color: white;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .social-links a:hover {
            background: var(--secondary);
            transform: translateY(-3px);
        }

        .section-title {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 1rem;
            position: relative;
            display: inline-block;
        }

        .section-title::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 0;
            width: 80px;
            height: 4px;
            background: var(--gradient-secondary);
            border-radius: 2px;
        }

        .tab-pane {
            height: 0;
            overflow: hidden;
            opacity: 0;
            transition: opacity 0.3s ease-in-out;
            pointer-events: none;
        }

        .tab-pane.active {
            height: auto;
            overflow: visible;
            opacity: 1;
            pointer-events: auto;
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .custom-tab-nav {
            margin-bottom: 1.5rem;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .custom-tab-nav .btn {
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            border-radius: 50px;
        }

        .chat-disabled-overlay {
            background: #f1f5f9;
            padding: 1rem;
            text-align: center;
            border-radius: 8px;
            margin: 1rem;
        }

        .resume-upload {
            border: 2px dashed #e2e8f0;
            border-radius: 16px;
            padding: 1.5rem;
            text-align: center;
            background: #f8fafc;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .resume-upload:hover {
            border-color: var(--primary);
            background: #f1f5f9;
        }

        .resume-upload i {
            font-size: 2rem;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .resume-upload small {
            color: var(--text-light);
            display: block;
            margin-top: 0.5rem;
        }

        .file-info {
            display: none;
            background: #e6f7ff;
            border: 1px solid #91d5ff;
            border-radius: 8px;
            padding: 0.5rem 1rem;
            margin-top: 0.5rem;
            font-size: 0.9rem;
        }

        .file-info.active {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .remove-file {
            color: #ff4d4f;
            cursor: pointer;
            font-size: 1.2rem;
        }

        @media (max-width: 768px) {
            .hero-title {
                font-size: 2.5rem;
            }

            .section-title {
                font-size: 2rem;
            }

            .chat-widget {
                width: 90%;
                right: 5%;
                left: 5%;
            }
            
            .btn-outline-custom,
            .btn-primary-custom {
                margin-bottom: 10px;
            }
            
            .custom-tab-nav .btn {
                width: 100%;
            }
        }

        .hours-badge {
            background: var(--gradient-accent);
            color: white;
            padding: 0.5rem 1.5rem;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .stat-card {
            text-align: center;
            padding: 2rem;
            background: white;
            border-radius: 20px;
            box-shadow: var(--shadow-sm);
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
        }

        .stat-number {
            font-size: 3rem;
            font-weight: 800;
            color: var(--primary);
            line-height: 1;
        }

        .stat-label {
            color: var(--text-light);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 0.9rem;
        }
        
        .why-choose-us-section {
            margin-top: 2rem !important;
            position: relative !important;
            z-index: 1 !important;
            clear: both !important;
            background: #f8fafc;
            padding: 1.5rem;
            border-radius: 16px;
        }

        .form-check-input {
            cursor: pointer;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-custom fixed-top" id="mainNav">
        <div class="container">
            <a class="navbar-brand" href="#" onclick="switchTab('home')">
                <div class="logo-container">
                    <?php if (!empty($site_logo) && $site_logo !== '🏢'): ?>
                        <img src="<?php echo htmlspecialchars($site_logo); ?>" alt="Logo" onerror="this.style.display='none'; this.parentElement.innerHTML='🏢';">
                    <?php else: ?>
                        <span>🏢</span>
                    <?php endif; ?>
                </div>
                <div class="brand-text">
                    <?php echo htmlspecialchars($site_title); ?>
                    <div class="brand-tagline"><?php echo htmlspecialchars($site_tagline); ?></div>
                </div>
            </a>
            <?php if ($staff_online): ?>
            <span class="staff-online-badge">
                <i class="bi bi-person-check-fill"></i> Support Online
            </span>
            <?php endif; ?>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item">
                        <a class="nav-link nav-link-custom <?php echo $activeTab === 'home' ? 'active' : ''; ?>" href="#" onclick="switchTab('home'); closeNavbar();">
                            <i class="bi bi-house-door"></i> Home
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link nav-link-custom <?php echo $activeTab === 'services' ? 'active' : ''; ?>" href="#" onclick="switchTab('services'); closeNavbar();">
                            <i class="bi bi-grid"></i> Services
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link nav-link-custom <?php echo $activeTab === 'business' ? 'active' : ''; ?>" href="#" onclick="switchTab('business'); closeNavbar();">
                            <i class="bi bi-graph-up"></i> Business
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link nav-link-custom <?php echo $activeTab === 'jobs' ? 'active' : ''; ?>" href="#" onclick="switchTab('jobs'); closeNavbar();">
                            <i class="bi bi-briefcase"></i> Jobs
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link nav-link-custom <?php echo $activeTab === 'placement' ? 'active' : ''; ?>" href="#" onclick="switchTab('placement'); closeNavbar();">
                            <i class="bi bi-person-plus"></i> Placement
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link nav-link-custom <?php echo $activeTab === 'team' ? 'active' : ''; ?>" href="#" onclick="switchTab('team'); closeNavbar();">
                            <i class="bi bi-people"></i> Team
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link nav-link-custom <?php echo $activeTab === 'contact' ? 'active' : ''; ?>" href="#" onclick="switchTab('contact'); closeNavbar();">
                            <i class="bi bi-envelope"></i> Contact
                        </a>
                    </li>
                    <li class="nav-item ms-lg-3">
                        <span class="counter-badge">
                            <i class="bi bi-people"></i> <?php echo $counterData['online']; ?> Online
                        </span>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <?php if ($form_submitted && !empty($form_message)): ?>
    <div class="container mt-5 pt-5">
        <div class="alert <?php echo $form_success ? 'alert-success' : 'alert-danger'; ?> alert-dismissible fade show" role="alert">
            <i class="bi <?php echo $form_success ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'; ?> me-2"></i>
            <?php echo htmlspecialchars($form_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    </div>
    <?php endif; ?>

    <main class="mt-5 pt-4">
        <!-- Home Tab -->
        <div class="tab-pane <?php echo $activeTab === 'home' ? 'active' : ''; ?>" id="home-tab">
            <section class="hero-section">
                <div class="container">
                    <div class="row align-items-center min-vh-50">
                        <div class="col-lg-8" data-aos="fade-up">
                            <span class="hero-badge">
                                <i class="bi bi-star-fill me-2"></i> Rewa's #1 Workforce Solutions
                            </span>
                            <h1 class="hero-title">Your Trusted Partner for<br><span style="color: var(--secondary);">Everyday Help</span></h1>
                            <p class="hero-subtitle">Connecting skilled local professionals with individuals, families, and businesses since <?php echo htmlspecialchars($founded_year); ?>.</p>
                            <div class="d-flex gap-3 flex-wrap">
                                <button class="btn btn-primary-custom" onclick="switchTab('services')" style="width: auto;">
                                    <i class="bi bi-grid me-2"></i> Explore Services
                                </button>
                                <button class="btn btn-outline-custom" onclick="switchTab('contact')" style="width: auto;">
                                    <i class="bi bi-telephone me-2"></i> Contact Us
                                </button>
                            </div>
                            <div class="mt-4 d-flex gap-4">
                                <div>
                                    <i class="bi bi-clock text-white fs-4"></i>
                                    <span class="ms-2"><?php echo htmlspecialchars($company_hours); ?></span>
                                </div>
                                <div>
                                    <i class="bi bi-people text-white fs-4"></i>
                                    <span class="ms-2">100+ Professionals</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <div class="container mt-5">
                <div class="row g-4">
                    <div class="col-md-3 col-6" data-aos="fade-up" data-aos-delay="100">
                        <div class="stat-card">
                            <div class="stat-number">100+</div>
                            <div class="stat-label">Professionals</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6" data-aos="fade-up" data-aos-delay="200">
                        <div class="stat-card">
                            <div class="stat-number">50+</div>
                            <div class="stat-label">Services</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6" data-aos="fade-up" data-aos-delay="300">
                        <div class="stat-card">
                            <div class="stat-number">15min</div>
                            <div class="stat-label">Response</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6" data-aos="fade-up" data-aos-delay="400">
                        <div class="stat-card">
                            <div class="stat-number">98%</div>
                            <div class="stat-label">Satisfaction</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="container mt-5">
                <h2 class="section-title" data-aos="fade-right">Our Services</h2>
                <div class="row g-4 mt-3">
                    <div class="col-md-4" data-aos="fade-up" data-aos-delay="100">
                        <div class="service-card">
                            <div class="service-icon-wrapper">
                                <i class="bi bi-brush"></i>
                            </div>
                            <h4>Cleaning & Housekeeping</h4>
                            <p class="text-muted">Professional cleaning services for homes and offices</p>
                        </div>
                    </div>
                    <div class="col-md-4" data-aos="fade-up" data-aos-delay="200">
                        <div class="service-card">
                            <div class="service-icon-wrapper">
                                <i class="bi bi-tools"></i>
                            </div>
                            <h4>Repair & Maintenance</h4>
                            <p class="text-muted">Electrical, plumbing, carpentry and more</p>
                        </div>
                    </div>
                    <div class="col-md-4" data-aos="fade-up" data-aos-delay="300">
                        <div class="service-card">
                            <div class="service-icon-wrapper">
                                <i class="bi bi-mortarboard"></i>
                            </div>
                            <h4>Teaching & Training</h4>
                            <p class="text-muted">Home tutoring, computer skills, language training</p>
                        </div>
                    </div>
                </div>
                <div class="text-center mt-4">
                    <button class="btn btn-outline-custom" onclick="switchTab('services')" style="width: auto;">View All Services <i class="bi bi-arrow-right ms-2"></i></button>
                </div>
            </div>

            <div class="container mt-5">
                <div class="card-modern" data-aos="fade-up">
                    <div class="card-header-gradient">
                        <i class="bi bi-headset card-icon"></i>
                        <h3 class="h2">Need Help? We're Here 24/7</h3>
                        <p>Get service within 15 minutes - Call us now!</p>
                    </div>
                    <div class="card-body-modern">
                        <div class="row">
                            <div class="col-md-4 mb-3 mb-md-0">
                                <a href="tel:<?php echo preg_replace('/[^0-9]/', '', $company_phone); ?>" class="btn btn-outline-custom w-100">
                                    <i class="bi bi-telephone me-2"></i> <?php echo htmlspecialchars($company_phone); ?>
                                </a>
                            </div>
                            <div class="col-md-4 mb-3 mb-md-0">
                                <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $company_whatsapp); ?>" class="btn btn-outline-custom w-100" target="_blank">
                                    <i class="bi bi-whatsapp me-2"></i> WhatsApp
                                </a>
                            </div>
                            <div class="col-md-4">
                                <a href="mailto:<?php echo htmlspecialchars($company_email); ?>" class="btn btn-outline-custom w-100">
                                    <i class="bi bi-envelope me-2"></i> Email Us
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="why-choose-us-section mt-4" data-aos="fade-up">
                    <div class="bg-light p-4 rounded-4">
                        <h5 class="fw-bold text-primary mb-3">Why Choose Us?</h5>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <p><i class="bi bi-clock text-warning me-2"></i> <strong>15-Minute Processing:</strong> Fastest service activation in Rewa</p>
                            </div>
                            <div class="col-md-6">
                                <p><i class="bi bi-people text-warning me-2"></i> <strong>Local Professionals:</strong> Verified skilled workers from Rewa</p>
                            </div>
                            <div class="col-md-6">
                                <p><i class="bi bi-currency-rupee text-warning me-2"></i> <strong>Transparent Pricing:</strong> No hidden charges</p>
                            </div>
                            <div class="col-md-6">
                                <p><i class="bi bi-shield-check text-warning me-2"></i> <strong>Quality Guarantee:</strong> Satisfaction guaranteed</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Services Tab -->
        <div class="tab-pane <?php echo $activeTab === 'services' ? 'active' : ''; ?>" id="services-tab">
            <div class="container py-5">
                <h2 class="section-title" data-aos="fade-right">Our Comprehensive Services</h2>
                <p class="text-muted mb-5" data-aos="fade-right" data-aos-delay="100">Choose from our wide range of professional workforce solutions</p>

                <div class="row g-4">
                    <?php
                    $services = [
                        ['Cleaning & Housekeeping', 'bi-brush', ['Deep Cleaning', 'Regular Housekeeping', 'Laundry & Ironing', 'Pest Control']],
                        ['Repair & Maintenance', 'bi-tools', ['Electrical Repairs', 'Plumbing', 'Carpentry', 'Painting']],
                        ['Teaching & Training', 'bi-mortarboard', ['Academic Tutoring', 'Language Training', 'Computer Skills', 'Yoga & Fitness']],
                        ['Personal Assistance', 'bi-person-badge', ['Errand Running', 'Virtual Assistant', 'Driver on Hire', 'Event Assistance']],
                        ['Creative & Crafting', 'bi-palette', ['Custom Furniture', 'Graphic Design', 'Invitations', 'Social Media Creatives']],
                        ['Office & Professional Support', 'bi-briefcase', ['Data Entry', 'Social Media Management', 'Website Design', 'HR Support']],
                        ['Specialized Services', 'bi-star', ['CCTV Installation', 'Property Services', 'Photography', 'Astrology']]
                    ];

                    foreach ($services as $index => $service):
                    ?>
                    <div class="col-lg-4 col-md-6" data-aos="fade-up" data-aos-delay="<?php echo $index * 100; ?>">
                        <div class="card-modern">
                            <div class="card-header-gradient text-center">
                                <i class="bi <?php echo $service[1]; ?> card-icon"></i>
                                <h4><?php echo $service[0]; ?></h4>
                            </div>
                            <div class="card-body-modern">
                                <ul class="list-unstyled">
                                    <?php foreach ($service[2] as $item): ?>
                                    <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i><?php echo $item; ?></li>
                                    <?php endforeach; ?>
                                </ul>
                                <button class="btn btn-primary-custom mt-3" onclick="switchTab('contact')">Enquire Now</button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <h2 class="section-title mt-5" data-aos="fade-right">Promoted Services</h2>
                <div class="row g-4 mt-3">
                    <div class="col-md-3" data-aos="fade-up" data-aos-delay="100">
                        <div class="service-card" style="border-top: 4px solid var(--secondary);">
                            <div class="service-icon-wrapper" style="background: var(--gradient-secondary);">
                                <i class="bi bi-house-heart"></i>
                            </div>
                            <h5>Complete Home Maintenance</h5>
                            <p class="text-muted small">Monthly subscription package</p>
                            <h4 class="text-secondary">₹7,999/mo</h4>
                        </div>
                    </div>
                    <div class="col-md-3" data-aos="fade-up" data-aos-delay="200">
                        <div class="service-card" style="border-top: 4px solid var(--secondary);">
                            <div class="service-icon-wrapper" style="background: var(--gradient-secondary);">
                                <i class="bi bi-building"></i>
                            </div>
                            <h5>Office Support Package</h5>
                            <p class="text-muted small">For small businesses</p>
                            <h4 class="text-secondary">₹14,999/mo</h4>
                        </div>
                    </div>
                    <div class="col-md-3" data-aos="fade-up" data-aos-delay="300">
                        <div class="service-card" style="border-top: 4px solid var(--secondary);">
                            <div class="service-icon-wrapper" style="background: var(--gradient-secondary);">
                                <i class="bi bi-person-workspace"></i>
                            </div>
                            <h5>Home Tutoring Package</h5>
                            <p class="text-muted small">3 subjects + computer basics</p>
                            <h4 class="text-secondary">₹4,999/mo</h4>
                        </div>
                    </div>
                    <div class="col-md-3" data-aos="fade-up" data-aos-delay="400">
                        <div class="service-card" style="border-top: 4px solid var(--secondary);">
                            <div class="service-icon-wrapper" style="background: var(--gradient-secondary);">
                                <i class="bi bi-car-front"></i>
                            </div>
                            <h5>Driver-on-Hire</h5>
                            <p class="text-muted small">4/8/12 hours daily</p>
                            <h4 class="text-secondary">₹11,999/mo</h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Business Tab -->
        <div class="tab-pane <?php echo $activeTab === 'business' ? 'active' : ''; ?>" id="business-tab">
            <div class="container py-5">
                <h2 class="section-title" data-aos="fade-right">Business Upgrade for Skilled Workers</h2>
                <p class="text-muted mb-5" data-aos="fade-right" data-aos-delay="100">Boost your independent service business with our professional support</p>

                <div class="custom-tab-nav mb-4">
                    <button class="btn <?php echo $activeBusinessTab === 'features' ? 'btn-primary' : 'btn-outline-primary'; ?>" onclick="switchBusinessSubTab('features')">
                        <i class="bi bi-stars me-2"></i>Features
                    </button>
                    <button class="btn <?php echo $activeBusinessTab === 'registration' ? 'btn-primary' : 'btn-outline-primary'; ?>" onclick="switchBusinessSubTab('registration')">
                        <i class="bi bi-pencil-square me-2"></i>Business Registration
                    </button>
                </div>

                <div class="business-tab-content">
                    <?php if ($activeBusinessTab === 'features'): ?>
                    <div id="features-content">
                        <div class="row g-4">
                            <div class="col-md-4" data-aos="fade-up" data-aos-delay="100">
                                <div class="card-modern">
                                    <div class="card-body-modern text-center">
                                        <i class="bi bi-person-badge fs-1 text-primary mb-3"></i>
                                        <h5>Client Referral Network</h5>
                                        <p class="text-muted">Get connected to a steady stream of clients through our established network.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4" data-aos="fade-up" data-aos-delay="200">
                                <div class="card-modern">
                                    <div class="card-body-modern text-center">
                                        <i class="bi bi-graph-up fs-1 text-primary mb-3"></i>
                                        <h5>Business Growth Support</h5>
                                        <p class="text-muted">Guidance on pricing, service expansion, and customer management.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4" data-aos="fade-up" data-aos-delay="300">
                                <div class="card-modern">
                                    <div class="card-body-modern text-center">
                                        <i class="bi bi-diagram-3 fs-1 text-primary mb-3"></i>
                                        <h5>Partnership Opportunities</h5>
                                        <p class="text-muted">Collaborate on larger projects with us handling client acquisition.</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="bg-light p-4 rounded-4 mt-4">
                            <h5 class="fw-bold text-primary mb-3">Perfect for:</h5>
                            <div class="row g-2">
                                <div class="col-6 col-md-3"><span class="badge bg-primary text-white p-2 w-100">Electricians</span></div>
                                <div class="col-6 col-md-3"><span class="badge bg-primary text-white p-2 w-100">Plumbers</span></div>
                                <div class="col-6 col-md-3"><span class="badge bg-primary text-white p-2 w-100">Carpenters</span></div>
                                <div class="col-6 col-md-3"><span class="badge bg-primary text-white p-2 w-100">Painters</span></div>
                                <div class="col-6 col-md-3"><span class="badge bg-primary text-white p-2 w-100">Home Tutors</span></div>
                                <div class="col-6 col-md-3"><span class="badge bg-primary text-white p-2 w-100">Drivers</span></div>
                                <div class="col-6 col-md-3"><span class="badge bg-primary text-white p-2 w-100">Cooks</span></div>
                                <div class="col-6 col-md-3"><span class="badge bg-primary text-white p-2 w-100">Photographers</span></div>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div id="registration-content">
                        <div class="card-modern">
                            <div class="card-body-modern">
                                <form method="POST" id="skilled-form" enctype="multipart/form-data">
                                    <input type="hidden" name="form_type" value="skilled">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                                    <div class="row g-4">
                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Your Primary Skill/Service *</label>
                                                <select class="form-control" name="desired_post" required>
                                                    <option value="">Select Your Main Service</option>
                                                    <optgroup label="Home Services">
                                                        <option value="House help (Maids)">House help (Maids)</option>
                                                        <option value="Deep Cleaning">Deep Cleaning</option>
                                                        <option value="Laundry & Drycleaner">Laundry & Drycleaner</option>
                                                        <option value="Errand Runner">Errand Runner</option>
                                                    </optgroup>
                                                    <optgroup label="Technical Services">
                                                        <option value="Electrician">Electrician</option>
                                                        <option value="Plumber">Plumber</option>
                                                        <option value="Carpenter">Carpenter</option>
                                                        <option value="Painter">Painter</option>
                                                        <option value="CCTV Installation">CCTV Installation</option>
                                                    </optgroup>
                                                    <optgroup label="Professional Services">
                                                        <option value="Driver">Driver</option>
                                                        <option value="Office Attendant">Office Attendant</option>
                                                        <option value="Cook">Cook</option>
                                                        <option value="Waiter">Waiter</option>
                                                        <option value="Insurance Advisor">Insurance Advisor</option>
                                                        <option value="Property Brokers">Property Brokers</option>
                                                    </optgroup>
                                                    <optgroup label="Creative Services">
                                                        <option value="Decoration (flowers)">Decoration (flowers)</option>
                                                        <option value="Interior Decoration">Interior Decoration</option>
                                                        <option value="Photography & Videography">Photography & Videography</option>
                                                        <option value="Beautician">Beautician</option>
                                                    </optgroup>
                                                    <optgroup label="Education & Training">
                                                        <option value="Home Tutor">Home Tutor</option>
                                                        <option value="Language Trainer">Language Trainer</option>
                                                        <option value="Fitness Coach">Fitness Coach</option>
                                                        <option value="Dance Tutor">Dance Tutor</option>
                                                    </optgroup>
                                                    <optgroup label="Digital Services">
                                                        <option value="Data Entry">Data Entry</option>
                                                        <option value="Web Design">Web Design</option>
                                                        <option value="SEO">SEO</option>
                                                        <option value="UI/UX Support">UI/UX Support</option>
                                                        <option value="Social Media Creatives">Social Media Creatives</option>
                                                        <option value="Online Services">Online Services</option>
                                                    </optgroup>
                                                    <option value="Other">Other (Please specify)</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="col-md-6" id="other_post_container" style="display: none;">
                                            <div class="form-floating-custom">
                                                <label>Please specify your skill/service</label>
                                                <input type="text" class="form-control" name="other_post">
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Business/Professional Name</label>
                                                <input type="text" class="form-control" name="business_name" placeholder="e.g., Rajesh Electricals">
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Full Name *</label>
                                                <input type="text" class="form-control" name="name" required>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Father/Husband Name *</label>
                                                <input type="text" class="form-control" name="father_husband_name" required>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Date of Birth *</label>
                                                <input type="date" class="form-control" name="dob" required>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Gender *</label>
                                                <select class="form-control" name="gender" required>
                                                    <option value="">Select Gender</option>
                                                    <option value="Male">Male</option>
                                                    <option value="Female">Female</option>
                                                    <option value="Other">Other</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Marital Status *</label>
                                                <select class="form-control" name="marital_status" required>
                                                    <option value="">Select Marital Status</option>
                                                    <option value="Unmarried">Unmarried</option>
                                                    <option value="Married">Married</option>
                                                    <option value="Other">Other</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Phone Number *</label>
                                                <input type="tel" class="form-control" name="phone" required>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Service Area/Locality *</label>
                                                <input type="text" class="form-control" name="work_locality" placeholder="e.g., Rewa City Center" required>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Email Address</label>
                                                <input type="email" class="form-control" name="email">
                                            </div>
                                        </div>

                                        <div class="col-12">
                                            <div class="form-floating-custom">
                                                <label>Current Address *</label>
                                                <textarea class="form-control" name="current_address" rows="2" required></textarea>
                                            </div>
                                        </div>

                                        <div class="col-12">
                                            <div class="form-floating-custom">
                                                <label>Permanent Address *</label>
                                                <textarea class="form-control" name="permanent_address" rows="2" required></textarea>
                                                <div class="form-check mt-2">
                                                    <input class="form-check-input" type="checkbox" id="copyAddress2" onchange="copyAddress('2')">
                                                    <label class="form-check-label" for="copyAddress2">Same as Current Address</label>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Highest Qualification *</label>
                                                <select class="form-control" name="qualification" required>
                                                    <option value="">Select Qualification</option>
                                                    <option value="Below 12th">Below 12th</option>
                                                    <option value="Higher Secondary">Higher Secondary</option>
                                                    <option value="Graduate & Above">Graduate & Above</option>
                                                    <option value="Others">Diploma and ITI</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Years of Experience *</label>
                                                <select class="form-control" name="experience" required>
                                                    <option value="">Select Experience</option>
                                                    <option value="Less than 1 year">Less than 1 year</option>
                                                    <option value="1-3 years">1-3 years</option>
                                                    <option value="3-5 years">3-5 years</option>
                                                    <option value="5-10 years">5-10 years</option>
                                                    <option value="10+ years">10+ years</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="col-12">
                                            <div class="form-floating-custom">
                                                <label>Service Description & Specializations *</label>
                                                <textarea class="form-control" name="skill_description" rows="4" placeholder="Describe your services, specialties, types of work you handle, etc." required></textarea>
                                            </div>
                                        </div>

                                        <div class="col-12">
                                            <button type="submit" class="btn btn-primary-custom">Register for Business Upgrade</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Jobs Tab -->
        <div class="tab-pane <?php echo $activeTab === 'jobs' ? 'active' : ''; ?>" id="jobs-tab">
            <div class="container py-5">
                <h2 class="section-title" data-aos="fade-right">Job Vacancies</h2>

                <div class="custom-tab-nav mb-4">
                    <button class="btn <?php echo $activeJobsTab === 'openings' ? 'btn-primary' : 'btn-outline-primary'; ?>" onclick="switchJobsSubTab('openings')">
                        <i class="bi bi-list-ul me-2"></i>Open Positions
                    </button>
                    <button class="btn <?php echo $activeJobsTab === 'apply' ? 'btn-primary' : 'btn-outline-primary'; ?>" onclick="switchJobsSubTab('apply')">
                        <i class="bi bi-pencil-square me-2"></i>Apply Now
                    </button>
                </div>

                <div class="jobs-tab-content">
                    <?php if ($activeJobsTab === 'openings'): ?>
                    <div id="openings-content">
                        <?php
                        try {
                            $openPositions = $db->query("SELECT * FROM open_positions WHERE is_active = 1 ORDER BY urgent DESC, created_at DESC");
                            if ($openPositions) {
                                $found = false;
                                while ($job = $openPositions->fetchArray(SQLITE3_ASSOC)):
                                    $found = true;
                        ?>
                        <div class="job-card" data-aos="fade-up">
                            <div class="d-flex justify-content-between align-items-start flex-wrap">
                                <h4 class="fw-bold text-primary"><?php echo htmlspecialchars($job['title']); ?></h4>
                                <?php if ($job['urgent']): ?>
                                <span class="urgent-badge"><i class="bi bi-exclamation-triangle me-1"></i>Urgent</span>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex gap-3 mb-3 flex-wrap">
                                <span class="badge bg-light text-dark p-2"><i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($job['location']); ?></span>
                                <span class="badge bg-light text-dark p-2"><i class="bi bi-clock me-1"></i><?php echo htmlspecialchars($job['type']); ?></span>
                                <span class="badge bg-light text-dark p-2"><i class="bi bi-currency-rupee me-1"></i><?php echo htmlspecialchars($job['salary']); ?></span>
                            </div>
                            <p><strong>Description:</strong> <?php echo htmlspecialchars($job['description']); ?></p>
                            <p><strong>Requirements:</strong> <?php echo htmlspecialchars($job['requirements']); ?></p>
                            <button class="btn btn-primary-custom" onclick="switchJobsSubTab('apply');" style="width: auto;">
                                Apply Now <i class="bi bi-arrow-right ms-2"></i>
                            </button>
                        </div>
                        <?php
                                endwhile;
                                if (!$found) {
                                    echo '<p class="text-muted">No open positions at the moment. Please check back later.</p>';
                                }
                            }
                        } catch (Exception $e) {
                            echo '<p class="text-muted">Unable to load positions at this time.</p>';
                        }
                        ?>
                    </div>
                    <?php else: ?>
                    <div id="apply-content">
                        <div class="card-modern">
                            <div class="card-body-modern">
                                <form method="POST" id="permanent-form" enctype="multipart/form-data">
                                    <input type="hidden" name="form_type" value="permanent">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                                    <div class="row g-4">
                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Position Applied For *</label>
                                                <select class="form-control" name="post_applied" id="post_applied" required>
                                                    <option value="">Select Position</option>
                                                    <?php
                                                    try {
                                                        $jobTitles = $db->query("SELECT DISTINCT title FROM open_positions WHERE is_active = 1");
                                                        while ($jobTitle = $jobTitles->fetchArray(SQLITE3_ASSOC)) {
                                                            echo '<option value="' . htmlspecialchars($jobTitle['title']) . '">' . htmlspecialchars($jobTitle['title']) . '</option>';
                                                        }
                                                    } catch (Exception $e) {
                                                    }
                                                    ?>
                                                    <option value="Other">Other</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="col-md-6" id="other_position_container" style="display: none;">
                                            <div class="form-floating-custom">
                                                <label>Please specify position</label>
                                                <input type="text" class="form-control" name="other_position">
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Full Name *</label>
                                                <input type="text" class="form-control" name="name" required>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Father/Husband Name *</label>
                                                <input type="text" class="form-control" name="father_husband_name" required>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Date of Birth *</label>
                                                <input type="date" class="form-control" name="dob" required>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Gender *</label>
                                                <select class="form-control" name="gender" required>
                                                    <option value="">Select Gender</option>
                                                    <option value="Male">Male</option>
                                                    <option value="Female">Female</option>
                                                    <option value="Other">Other</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Marital Status *</label>
                                                <select class="form-control" name="marital_status" required>
                                                    <option value="">Select Marital Status</option>
                                                    <option value="Unmarried">Unmarried</option>
                                                    <option value="Married">Married</option>
                                                    <option value="Other">Other</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Phone Number *</label>
                                                <input type="tel" class="form-control" name="phone" required>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Email Address</label>
                                                <input type="email" class="form-control" name="email">
                                            </div>
                                        </div>

                                        <div class="col-12">
                                            <div class="form-floating-custom">
                                                <label>Current Address *</label>
                                                <textarea class="form-control" name="current_address" rows="2" required></textarea>
                                            </div>
                                        </div>

                                        <div class="col-12">
                                            <div class="form-floating-custom">
                                                <label>Permanent Address *</label>
                                                <textarea class="form-control" name="permanent_address" rows="2" required></textarea>
                                                <div class="form-check mt-2">
                                                    <input class="form-check-input" type="checkbox" id="copyAddress" onchange="copyAddress('')">
                                                    <label class="form-check-label" for="copyAddress">Same as Current Address</label>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Highest Qualification *</label>
                                                <select class="form-control" name="qualification" required>
                                                    <option value="">Select Qualification</option>
                                                    <option value="Below 12th">Below 12th</option>
                                                    <option value="Higher Secondary">Higher Secondary</option>
                                                    <option value="Graduate & Above">Graduate & Above</option>
                                                    <option value="Others">Diploma and ITI</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Years of Experience</label>
                                                <select class="form-control" name="experience">
                                                    <option value="">Select Experience</option>
                                                    <option value="Less than 1 year">Less than 1 year</option>
                                                    <option value="1-3 years">1-3 years</option>
                                                    <option value="3-5 years">3-5 years</option>
                                                    <option value="5-10 years">5-10 years</option>
                                                    <option value="10+ years">10+ years</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Computer Skills</label>
                                                <select class="form-control" name="computer_skills">
                                                    <option value="">Select</option>
                                                    <option value="Basic">Basic</option>
                                                    <option value="Intermediate">Intermediate</option>
                                                    <option value="Advanced">Advanced</option>
                                                    <option value="None">None</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Availability</label>
                                                <select class="form-control" name="availability">
                                                    <option value="">Select</option>
                                                    <option value="Immediate">Immediate</option>
                                                    <option value="Within 15 days">Within 15 days</option>
                                                    <option value="Within 30 days">Within 30 days</option>
                                                    <option value="After notice period">After notice period</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="col-12">
                                            <label class="fw-semibold mb-2">Communication Skills</label>
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" name="communication_skills[]" value="Hindi">
                                                        <label class="form-check-label">Hindi</label>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" name="communication_skills[]" value="English">
                                                        <label class="form-check-label">English</label>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" name="communication_skills[]" value="Other Regional">
                                                        <label class="form-check-label">Other Regional</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Resume Upload Section -->
                                        <div class="col-12">
                                            <label class="fw-semibold mb-2">Resume/CV (Optional)</label>
                                            <div class="resume-upload" onclick="document.getElementById('resume').click();">
                                                <i class="bi bi-cloud-upload"></i>
                                                <p class="mb-1">Click to upload or drag and drop</p>
                                                <small>PDF, DOC, DOCX (Max 5MB)</small>
                                            </div>
                                            <input type="file" class="d-none" id="resume" name="resume" accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
                                            <div class="file-info" id="fileInfo">
                                                <span id="fileName"></span>
                                                <span class="remove-file" onclick="removeFile()">✕</span>
                                            </div>
                                        </div>

                                        <div class="col-12">
                                            <button type="submit" class="btn btn-primary-custom">Submit Application</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Placement Tab -->
        <div class="tab-pane <?php echo $activeTab === 'placement' ? 'active' : ''; ?>" id="placement-tab">
            <div class="container py-5">
                <h2 class="section-title" data-aos="fade-right">Placement Help</h2>
                <p class="text-muted mb-5" data-aos="fade-right" data-aos-delay="100">Get assistance finding the right job for your skills and experience</p>

                <div class="card-modern" data-aos="fade-up">
                    <div class="card-body-modern">
                        <form method="POST" id="placement-form" enctype="multipart/form-data">
                            <input type="hidden" name="form_type" value="placement">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                            <div class="row g-4">
                                <div class="col-md-6">
                                    <div class="form-floating-custom">
                                        <label>Full Name *</label>
                                        <input type="text" class="form-control" name="name" required>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-floating-custom">
                                        <label>Father/Husband Name *</label>
                                        <input type="text" class="form-control" name="father_husband_name" required>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-floating-custom">
                                        <label>Date of Birth *</label>
                                        <input type="date" class="form-control" name="dob" required>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-floating-custom">
                                        <label>Gender *</label>
                                        <select class="form-control" name="gender" required>
                                            <option value="">Select Gender</option>
                                            <option value="Male">Male</option>
                                            <option value="Female">Female</option>
                                            <option value="Other">Other</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-floating-custom">
                                        <label>Marital Status *</label>
                                        <select class="form-control" name="marital_status" required>
                                            <option value="">Select Marital Status</option>
                                            <option value="Unmarried">Unmarried</option>
                                            <option value="Married">Married</option>
                                            <option value="Other">Other</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-floating-custom">
                                        <label>Phone Number *</label>
                                        <input type="tel" class="form-control" name="phone" required>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-floating-custom">
                                        <label>Email *</label>
                                        <input type="email" class="form-control" name="email" required>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-floating-custom">
                                        <label>Current Address *</label>
                                        <textarea class="form-control" name="current_address" rows="2" required></textarea>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-floating-custom">
                                        <label>Permanent Address *</label>
                                        <textarea class="form-control" name="permanent_address" rows="2" required></textarea>
                                        <div class="form-check mt-2">
                                            <input class="form-check-input" type="checkbox" id="copyAddress3" onchange="copyAddress('3')">
                                            <label class="form-check-label" for="copyAddress3">Same as Current Address</label>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-floating-custom">
                                        <label>Highest Qualification *</label>
                                        <select class="form-control" name="qualification" required>
                                            <option value="">Select Qualification</option>
                                            <option value="Below 12th">Below 12th</option>
                                            <option value="Higher Secondary">Higher Secondary</option>
                                            <option value="Graduate & Above">Graduate & Above</option>
                                            <option value="Others">Diploma and ITI</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-floating-custom">
                                        <label>Work Experience</label>
                                        <textarea class="form-control" name="experience" rows="2" placeholder="Describe your work experience"></textarea>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-floating-custom">
                                        <label>Desired Job Profile *</label>
                                        <input type="text" class="form-control" name="desired_job_profile" required placeholder="e.g., Software Developer, Marketing Manager">
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-floating-custom">
                                        <label>Current Job Role</label>
                                        <input type="text" class="form-control" name="current_job_role" placeholder="e.g., Senior Developer, Unemployed">
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-floating-custom">
                                        <label>Notice Period *</label>
                                        <select class="form-control" name="notice_period" required>
                                            <option value="">Select Notice Period</option>
                                            <option value="Immediate">Immediate Joining</option>
                                            <option value="15 Days">15 Days</option>
                                            <option value="30 Days">30 Days</option>
                                            <option value="45 Days">45 Days</option>
                                            <option value="60 Days">60 Days</option>
                                            <option value="90 Days">90 Days</option>
                                            <option value="Serving Notice">Serving Notice</option>
                                            <option value="Unemployed">Currently Unemployed</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-floating-custom">
                                        <label>Current/Last Salary (₹)</label>
                                        <input type="text" class="form-control" name="current_ctc" placeholder="e.g., 5,00,000 per annum">
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-floating-custom">
                                        <label>Expected Salary (₹)</label>
                                        <input type="text" class="form-control" name="expected_ctc" placeholder="e.g., 7,00,000 per annum">
                                    </div>
                                </div>

                                <div class="col-12">
                                    <label class="fw-semibold mb-2">Preferred Job Location *</label>
                                    <div class="row g-2">
                                        <div class="col-md-3 col-6">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="desired_location[]" value="Rewa" id="loc_rewa">
                                                <label class="form-check-label" for="loc_rewa">Rewa</label>
                                            </div>
                                        </div>
                                        <div class="col-md-3 col-6">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="desired_location[]" value="Satna" id="loc_satna">
                                                <label class="form-check-label" for="loc_satna">Satna</label>
                                            </div>
                                        </div>
                                        <div class="col-md-3 col-6">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="desired_location[]" value="Jabalpur" id="loc_jabalpur">
                                                <label class="form-check-label" for="loc_jabalpur">Jabalpur</label>
                                            </div>
                                        </div>
                                        <div class="col-md-3 col-6">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="desired_location[]" value="Bhopal" id="loc_bhopal">
                                                <label class="form-check-label" for="loc_bhopal">Bhopal</label>
                                            </div>
                                        </div>
                                        <div class="col-md-3 col-6">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="desired_location[]" value="Indore" id="loc_indore">
                                                <label class="form-check-label" for="loc_indore">Indore</label>
                                            </div>
                                        </div>
                                        <div class="col-md-3 col-6">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="desired_location[]" value="Other" id="loc_other">
                                                <label class="form-check-label" for="loc_other">Other</label>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Resume Upload Section -->
                                <div class="col-12">
                                    <label class="fw-semibold mb-2">Resume/CV (Optional)</label>
                                    <div class="resume-upload" onclick="document.getElementById('placement_resume').click();">
                                        <i class="bi bi-cloud-upload"></i>
                                        <p class="mb-1">Click to upload or drag and drop</p>
                                        <small>PDF, DOC, DOCX (Max 5MB)</small>
                                    </div>
                                    <input type="file" class="d-none" id="placement_resume" name="resume" accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
                                    <div class="file-info" id="placementFileInfo">
                                        <span id="placementFileName"></span>
                                        <span class="remove-file" onclick="removePlacementFile()">✕</span>
                                    </div>
                                </div>

                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary-custom">Submit for Placement Help</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Team Tab -->
        <div class="tab-pane <?php echo $activeTab === 'team' ? 'active' : ''; ?>" id="team-tab">
            <div class="container py-5">
                <h2 class="section-title" data-aos="fade-right">Our Leadership Team</h2>
                <p class="text-muted mb-5" data-aos="fade-right" data-aos-delay="100">Meet the experts behind our success</p>

                <div class="row g-4">
                    <?php
                    try {
                        $teamMembers = $db->query("SELECT * FROM team_members WHERE is_active = 1 ORDER BY display_order ASC");
                        $hasMembers = false;
                        if ($teamMembers) {
                            while ($member = $teamMembers->fetchArray(SQLITE3_ASSOC)):
                                $hasMembers = true;
                    ?>
                    <div class="col-lg-4 col-md-6" data-aos="fade-up" data-aos-delay="<?php echo ($member['display_order'] ?? 0) * 100; ?>">
                        <div class="team-card">
                            <?php
                            $initials = strtoupper(substr($member['name'], 0, 1));
                            $initial2 = isset(explode(' ', $member['name'])[1]) ? strtoupper(substr(explode(' ', $member['name'])[1], 0, 1)) : '';
                            $initials2 = $initials . $initial2;
                            if (!empty($member['photo_url'])): ?>
                            <img src="<?php echo htmlspecialchars($member['photo_url']); ?>"
                                 alt="<?php echo htmlspecialchars($member['name']); ?>"
                                 class="team-img"
                                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                            <?php endif; ?>
                            <div class="team-img" style="display:<?php echo empty($member['photo_url']) ? 'flex' : 'none'; ?>;align-items:center;justify-content:center;background:linear-gradient(135deg,#0f3b5e22,#0f3b5e44);">
                                <svg width="100" height="100" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
                                    <circle cx="50" cy="50" r="44" fill="#0f3b5e"/>
                                    <circle cx="50" cy="38" r="14" fill="rgba(255,255,255,0.9)"/>
                                    <ellipse cx="50" cy="72" rx="22" ry="15" fill="rgba(255,255,255,0.8)"/>
                                    <text x="50" y="43" text-anchor="middle" dominant-baseline="middle" font-family="Arial,sans-serif" font-size="13" font-weight="800" fill="#0f3b5e"><?php echo htmlspecialchars($initials2); ?></text>
                                </svg>
                            </div>
                            <div class="team-info">
                                <h4 class="team-name"><?php echo htmlspecialchars($member['name']); ?></h4>
                                <p class="team-position"><?php echo htmlspecialchars($member['position']); ?></p>
                                <p class="text-muted small"><?php echo htmlspecialchars($member['bio']); ?></p>
                            </div>
                        </div>
                    </div>
                    <?php
                            endwhile;
                        }
                        if (!$hasMembers) {
                            throw new Exception("No team members found");
                        }
                    } catch (Exception $e) {
                        // Fallback team with animated SVG avatars (no external image dependency)
                        $fallback_team = [
                            ['Jyoti Mishra',   'Owner & CEO',             '#7c3aed', 'JM', 'Visionary leader driving company growth and excellence'],
                            ['Deepak Mishra',  'Tech & Operations Head',   '#0f3b5e', 'DM', 'Technology strategist and operations expert'],
                            ['Puneet Tiwari',  'Operations Manager',       '#059669', 'PT', 'Ensuring smooth day-to-day operations'],
                            ['Pankaj Tiwari',  'Recruitment Head',         '#d97706', 'PK', 'Connecting the right talent with opportunities'],
                            ['Diksha Mishra',  'Business Development',     '#dc2626', 'DI', 'Driving growth and building new partnerships'],
                            ['Rahul Sharma',   'Advisor & Consultant',     '#0891b2', 'RS', 'Providing strategic guidance and expertise'],
                        ];
                        foreach ($fallback_team as $fi => $m): ?>
                        <div class="col-lg-4 col-md-6" data-aos="fade-up" data-aos-delay="<?php echo ($fi + 1) * 100; ?>">
                            <div class="team-card">
                                <div class="team-img" style="display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,<?php echo $m[2]; ?>22,<?php echo $m[2]; ?>44);position:relative;overflow:hidden;">
                                    <svg width="120" height="120" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg">
                                        <defs>
                                            <linearGradient id="avatarGrad<?php echo $fi; ?>" x1="0%" y1="0%" x2="100%" y2="100%">
                                                <stop offset="0%" style="stop-color:<?php echo $m[2]; ?>;stop-opacity:0.9"/>
                                                <stop offset="100%" style="stop-color:<?php echo $m[2]; ?>;stop-opacity:0.6"/>
                                            </linearGradient>
                                            <clipPath id="clip<?php echo $fi; ?>">
                                                <circle cx="60" cy="60" r="52"/>
                                            </clipPath>
                                        </defs>
                                        <circle cx="60" cy="60" r="52" fill="url(#avatarGrad<?php echo $fi; ?>)">
                                            <animate attributeName="r" values="52;54;52" dur="3s" repeatCount="indefinite"/>
                                        </circle>
                                        <circle cx="60" cy="60" r="56" fill="none" stroke="<?php echo $m[2]; ?>" stroke-width="1.5" stroke-dasharray="4 4" opacity="0.4">
                                            <animateTransform attributeName="transform" type="rotate" from="0 60 60" to="360 60 60" dur="12s" repeatCount="indefinite"/>
                                        </circle>
                                        <circle cx="60" cy="46" r="16" fill="rgba(255,255,255,0.92)" clip-path="url(#clip<?php echo $fi; ?>)"/>
                                        <ellipse cx="60" cy="85" rx="26" ry="18" fill="rgba(255,255,255,0.85)" clip-path="url(#clip<?php echo $fi; ?>)"/>
                                        <text x="60" y="52" text-anchor="middle" dominant-baseline="middle" font-family="'Plus Jakarta Sans',Arial,sans-serif" font-size="13" font-weight="800" fill="<?php echo $m[2]; ?>"><?php echo htmlspecialchars($m[3]); ?></text>
                                    </svg>
                                    <div style="position:absolute;inset:0;background:linear-gradient(135deg,rgba(255,255,255,0.1) 0%,transparent 60%);pointer-events:none;"></div>
                                </div>
                                <div class="team-info">
                                    <h4 class="team-name"><?php echo htmlspecialchars($m[0]); ?></h4>
                                    <p class="team-position"><?php echo htmlspecialchars($m[1]); ?></p>
                                    <p class="text-muted small"><?php echo htmlspecialchars($m[4]); ?></p>
                                </div>
                            </div>
                        </div>
                        <?php endforeach;
                    } ?>
                </div>
            </div>
        </div>

        <!-- Contact Tab -->
        <div class="tab-pane <?php echo $activeTab === 'contact' ? 'active' : ''; ?>" id="contact-tab">
            <div class="container py-5">
                <h2 class="section-title" data-aos="fade-right">Contact Us</h2>
                <p class="text-primary fw-bold mb-4" data-aos="fade-right" data-aos-delay="100"><i class="bi bi-bolt me-2"></i>Get service in just 15 minutes - Call us now!</p>

                <div class="row g-4">
                    <div class="col-lg-5" data-aos="fade-up">
                        <div class="card-modern h-100" style="background: var(--gradient-primary); color: white;">
                            <div class="card-body-modern">
                                <h4 class="text-white mb-4"><i class="bi bi-info-circle me-2"></i>Contact Information</h4>

                                <div class="d-flex gap-3 mb-4">
                                    <i class="bi bi-telephone fs-3"></i>
                                    <div>
                                        <p class="mb-1 fw-bold">Office Phone:</p>
                                        <a href="tel:<?php echo preg_replace('/[^0-9]/', '', $company_phone); ?>" class="text-white text-decoration-none"><?php echo htmlspecialchars($company_phone); ?></a>
                                    </div>
                                </div>

                                <div class="d-flex gap-3 mb-4">
                                    <i class="bi bi-whatsapp fs-3"></i>
                                    <div>
                                        <p class="mb-1 fw-bold">WhatsApp Business:</p>
                                        <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $company_whatsapp); ?>" class="text-white text-decoration-none" target="_blank"><?php echo htmlspecialchars($company_whatsapp); ?></a>
                                    </div>
                                </div>

                                <div class="d-flex gap-3 mb-4">
                                    <i class="bi bi-envelope fs-3"></i>
                                    <div>
                                        <p class="mb-1 fw-bold">Service Email:</p>
                                        <a href="mailto:<?php echo htmlspecialchars($company_email); ?>" class="text-white text-decoration-none"><?php echo htmlspecialchars($company_email); ?></a>
                                    </div>
                                </div>

                                <div class="d-flex gap-3 mb-4">
                                    <i class="bi bi-geo-alt fs-3"></i>
                                    <div>
                                        <p class="mb-1 fw-bold">Headquarters:</p>
                                        <p class="mb-1">Rewa City, MP - 486001</p>
                                        <p class="small opacity-75"><?php echo htmlspecialchars($company_address); ?></p>
                                    </div>
                                </div>

                                <div class="bg-white bg-opacity-10 p-3 rounded-4">
                                    <h5 class="text-white mb-2">Business Hours</h5>
                                    <p class="mb-1"><i class="bi bi-clock me-2"></i><?php echo htmlspecialchars($company_hours); ?></p>
                                    <p class="mb-0"><i class="bi bi-exclamation-circle me-2"></i>Emergency services available 24/7</p>
                                </div>

                                <button class="btn btn-light w-100 mt-4" id="downloadVCF">
                                    <i class="bi bi-card-heading me-2"></i>Download Contact Card
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-7" data-aos="fade-up" data-aos-delay="200">
                        <div class="custom-tab-nav mb-4">
                            <button class="btn <?php echo $activeContactTab === 'service' ? 'btn-primary' : 'btn-outline-primary'; ?>" onclick="switchContactSubTab('service')">
                                <i class="bi bi-tools me-2"></i>Service Enquiry
                            </button>
                            <button class="btn <?php echo $activeContactTab === 'general' ? 'btn-primary' : 'btn-outline-primary'; ?>" onclick="switchContactSubTab('general')">
                                <i class="bi bi-envelope me-2"></i>General Contact
                            </button>
                        </div>

                        <div class="contact-tab-content">
                            <?php if ($activeContactTab === 'service'): ?>
                            <div id="service-content">
                                <div class="card-modern">
                                    <div class="card-body-modern">
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="service_enquiry" value="1">

                                            <div class="row g-4">
                                                <div class="col-md-6">
                                                    <div class="form-floating-custom">
                                                        <label>Full Name *</label>
                                                        <input type="text" class="form-control" name="name" required>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-floating-custom">
                                                        <label>Phone Number *</label>
                                                        <input type="tel" class="form-control" name="phone" required>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-floating-custom">
                                                        <label>Email Address</label>
                                                        <input type="email" class="form-control" name="email">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-floating-custom">
                                                        <label>Preferred Service Date</label>
                                                        <input type="date" class="form-control" name="service_date">
                                                    </div>
                                                </div>
                                                <div class="col-12">
                                                    <div class="form-floating-custom">
                                                        <label>Service Category *</label>
                                                        <select class="form-control" name="service_category" required>
                                                            <option value="">Select a service category</option>
                                                            <option value="cleaning">Cleaning & Housekeeping</option>
                                                            <option value="repair">Repair & Maintenance</option>
                                                            <option value="teaching">Teaching & Training</option>
                                                            <option value="assistance">Personal Assistance</option>
                                                            <option value="mponline">CSC & MP Online Services</option>
                                                            <option value="business">Business Assistance</option>
                                                            <option value="event">Event Assistance</option>
                                                            <option value="property">Property Buy/Sale/Rent Assistance</option>
                                                            <option value="placement">Placement Services</option>
                                                            <option value="other">Other Services</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-12">
                                                    <div class="form-floating-custom">
                                                        <label>Service Description *</label>
                                                        <textarea class="form-control" name="service_description" rows="3" required></textarea>
                                                    </div>
                                                </div>
                                                <div class="col-12">
                                                    <div class="form-floating-custom">
                                                        <label>Address for Service</label>
                                                        <textarea class="form-control" name="address" rows="2"></textarea>
                                                    </div>
                                                </div>
                                                <div class="col-12">
                                                    <button type="submit" class="btn btn-primary-custom">Submit Service Request</button>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php else: ?>
                            <div id="general-content">
                                <div class="card-modern">
                                    <div class="card-body-modern">
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="general_contact" value="1">

                                            <div class="row g-4">
                                                <div class="col-md-6">
                                                    <div class="form-floating-custom">
                                                        <label>Full Name *</label>
                                                        <input type="text" class="form-control" name="name" required>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-floating-custom">
                                                        <label>Email Address *</label>
                                                        <input type="email" class="form-control" name="email" required>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-floating-custom">
                                                        <label>Phone Number</label>
                                                        <input type="tel" class="form-control" name="phone">
                                                    </div>
                                                </div>
                                                <div class="col-12">
                                                    <div class="form-floating-custom">
                                                        <label>Subject *</label>
                                                        <select class="form-control" name="subject" required>
                                                            <option value="">Select a subject</option>
                                                            <option value="partnership">Business Partnership</option>
                                                            <option value="feedback">Feedback & Suggestions</option>
                                                            <option value="complaint">Complaint</option>
                                                            <option value="other">Other Inquiry</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-12">
                                                    <div class="form-floating-custom">
                                                        <label>Message *</label>
                                                        <textarea class="form-control" name="message" rows="4" required></textarea>
                                                    </div>
                                                </div>
                                                <div class="col-12">
                                                    <button type="submit" class="btn btn-primary-custom">Send Message</button>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <footer class="footer">
        <div class="container">
            <div class="row g-4">
                <div class="col-lg-4">
                    <div class="footer-logo-container mb-3">
                        <?php if (!empty($site_logo) && $site_logo !== '🏢'): ?>
                            <img src="<?php echo htmlspecialchars($site_logo); ?>" alt="Logo" onerror="this.style.display='none'; this.parentElement.innerHTML='🏢';">
                        <?php else: ?>
                            <span>🏢</span>
                        <?php endif; ?>
                    </div>
                    <h5><?php echo htmlspecialchars($site_title); ?></h5>
                    <p class="text-white-50"><?php echo htmlspecialchars($site_tagline); ?></p>
                </div>
                <div class="col-lg-2">
                    <h5>Quick Links</h5>
                    <ul class="footer-links">
                        <li><a href="#" onclick="switchTab('home'); closeNavbar();">Home</a></li>
                        <li><a href="#" onclick="switchTab('services'); closeNavbar();">Services</a></li>
                        <li><a href="#" onclick="switchTab('jobs'); closeNavbar();">Jobs</a></li>
                        <li><a href="#" onclick="switchTab('team'); closeNavbar();">Team</a></li>
                    </ul>
                </div>
                <div class="col-lg-3">
                    <h5>Services</h5>
                    <ul class="footer-links">
                        <li><a href="#" onclick="switchTab('services'); closeNavbar();">Cleaning & Housekeeping</a></li>
                        <li><a href="#" onclick="switchTab('services'); closeNavbar();">Repair & Maintenance</a></li>
                        <li><a href="#" onclick="switchTab('services'); closeNavbar();">Teaching & Training</a></li>
                        <li><a href="#" onclick="switchTab('services'); closeNavbar();">Personal Assistance</a></li>
                    </ul>
                </div>
                <div class="col-lg-3">
                    <h5>Contact Info</h5>
                    <ul class="footer-links">
                        <li><i class="bi bi-telephone me-2"></i> <?php echo htmlspecialchars($company_phone); ?></li>
                        <li><i class="bi bi-envelope me-2"></i> <?php echo htmlspecialchars($company_email); ?></li>
                        <li><i class="bi bi-geo-alt me-2"></i> Rewa, MP</li>
                    </ul>
                </div>
            </div>
            <hr class="my-4 bg-white opacity-25">
            <div class="row">
                <div class="col-md-6">
                    <p class="small text-white-50 mb-0">&copy; <?php echo $currentYear; ?> <?php echo htmlspecialchars($site_title); ?>. All rights reserved.</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <p class="small text-white-50 mb-0">
                        <i class="bi bi-people me-1"></i> Total Visitors: <?php echo $counterData['total']; ?> |
                        <i class="bi bi-calendar-day me-1"></i> Today: <?php echo $counterData['today']; ?>
                    </p>
                </div>
            </div>
        </div>
    </footer>

    <!-- Quick Contact Popup -->
    <div class="quick-contact-popup" id="quickContactPopup">
        <div class="bg-primary text-white p-3">
            <h6 class="mb-0"><i class="bi bi-headset me-2"></i>Quick Contact</h6>
        </div>
        <a href="#" class="quick-contact-item" id="openLiveChat">
            <i class="bi bi-chat" style="background: var(--primary);"></i>
            <div>
                <strong>Live Chat</strong>
                <?php if ($staff_online): ?>
                <span class="badge bg-success ms-2">Online</span>
                <?php endif; ?>
                <p class="small text-muted mb-0">Chat with our team</p>
            </div>
        </a>
        <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $company_whatsapp); ?>" target="_blank" class="quick-contact-item">
            <i class="bi bi-whatsapp" style="background: #25D366;"></i>
            <div>
                <strong>WhatsApp</strong>
                <p class="small text-muted mb-0"><?php echo htmlspecialchars($company_whatsapp); ?></p>
            </div>
        </a>
        <a href="mailto:<?php echo htmlspecialchars($company_email); ?>" class="quick-contact-item">
            <i class="bi bi-envelope" style="background: #EA4335;"></i>
            <div>
                <strong>Email</strong>
                <p class="small text-muted mb-0"><?php echo htmlspecialchars($company_email); ?></p>
            </div>
        </a>
        <a href="tel:<?php echo preg_replace('/[^0-9]/', '', $company_phone); ?>" class="quick-contact-item">
            <i class="bi bi-telephone" style="background: #34A853;"></i>
            <div>
                <strong>Call</strong>
                <p class="small text-muted mb-0"><?php echo htmlspecialchars($company_phone); ?></p>
            </div>
        </a>
    </div>

    <!-- Chat Widget -->
    <div class="chat-widget" id="chatWidget">
        <div class="chat-header">
            <h5>
                <i class="bi bi-chat-dots me-2"></i>Live Chat
                <?php if ($staff_online): ?>
                <span class="online-indicator"></span>
                <?php endif; ?>
            </h5>
            <div class="d-flex align-items-center gap-2">
                <button class="btn-close btn-close-white" id="closeChat" title="Minimize"></button>
            </div>
        </div>
        
        <?php if ($staff_online): ?>
        <div id="chatInitForm" class="chat-init-form">
            <h6 class="mb-3">Please provide your details to start chat</h6>
            <form id="chatInitFormElement">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <div class="mb-3">
                    <label class="form-label">Your Name *</label>
                    <input type="text" class="form-control" name="guest_name" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Contact Reason *</label>
                    <select class="form-control" name="contact_reason" required>
                        <option value="">Select reason</option>
                        <option value="service_enquiry">Service Enquiry</option>
                        <option value="job_application">Job Application</option>
                        <option value="business_upgrade">Business Upgrade</option>
                        <option value="placement_help">Placement Help</option>
                        <option value="general_query">General Query</option>
                        <option value="feedback">Feedback</option>
                        <option value="complaint">Complaint</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Email Address</label>
                    <input type="email" class="form-control" name="guest_email">
                </div>
                <div class="mb-3">
                    <label class="form-label">Phone Number</label>
                    <input type="tel" class="form-control" name="guest_phone">
                </div>
                <button type="submit" class="btn btn-primary-custom">Start Chat</button>
            </form>
        </div>
        <div id="chatMessagesContainer" style="display:none;">
            <div class="chat-messages-container" id="chatMessages"></div>
            <div class="chat-input-area">
                <input type="text" id="chatInput" placeholder="Type your message..." autocomplete="off">
                <button type="button" id="chatSendBtn" class="chat-send-btn">
                    <i class="bi bi-send"></i>
                </button>
            </div>
        </div>
        <?php else: ?>
        <div class="chat-disabled-overlay">
            <i class="bi bi-clock-history fs-4 mb-2 d-block"></i>
            <p class="mb-2">Our chat support is currently offline.</p>
            <p class="small mb-0">Please use our contact form or call us during business hours.</p>
            <hr>
            <p class="small mb-0"><strong>Business Hours:</strong> <?php echo htmlspecialchars($company_hours); ?></p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Chat Toggle Button -->
    <button class="chat-toggle" id="chatToggle">
        <i class="bi bi-chat-dots"></i>
        <?php if ($staff_online): ?>
        <span class="online-badge"></span>
        <?php endif; ?>
    </button>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>

    <script>
        AOS.init({
            duration: 800,
            once: true
        });

        window.addEventListener('scroll', function() {
            const nav = document.getElementById('mainNav');
            if (window.scrollY > 50) {
                nav.classList.add('scrolled');
            } else {
                nav.classList.remove('scrolled');
            }
        });

        function closeNavbar() {
            const navbarCollapse = document.getElementById('navbarNav');
            if (navbarCollapse && navbarCollapse.classList.contains('show')) {
                const bsCollapse = new bootstrap.Collapse(navbarCollapse);
                bsCollapse.hide();
            }
        }

        function switchTab(tabId) {
            const url = new URL(window.location);
            url.searchParams.set('tab', tabId);
            history.pushState(null, null, url);

            document.querySelectorAll('.tab-pane').forEach(tab => {
                tab.classList.remove('active');
            });

            const selectedTab = document.getElementById(tabId + '-tab');
            if (selectedTab) {
                selectedTab.classList.add('active');
            }

            document.querySelectorAll('.nav-link-custom').forEach(link => {
                link.classList.remove('active');
                if (link.getAttribute('onclick')?.includes(tabId)) {
                    link.classList.add('active');
                }
            });

            window.scrollTo({ top: 0, behavior: 'smooth' });
            setTimeout(() => { 
                if (typeof AOS !== 'undefined') {
                    AOS.refresh(); 
                }
            }, 100);
        }

        function switchBusinessSubTab(subTabId) {
            const url = new URL(window.location);
            url.searchParams.set('tab', 'business');
            url.searchParams.set('sub', subTabId);
            history.pushState(null, null, url);
            location.reload();
        }

        function switchJobsSubTab(subTabId) {
            const url = new URL(window.location);
            url.searchParams.set('tab', 'jobs');
            url.searchParams.set('sub', subTabId);
            history.pushState(null, null, url);
            location.reload();
        }

        function switchContactSubTab(subTabId) {
            const url = new URL(window.location);
            url.searchParams.set('tab', 'contact');
            url.searchParams.set('sub', subTabId);
            history.pushState(null, null, url);
            location.reload();
        }

        function copyAddress(suffix = '') {
            const currentAddress = document.querySelector('textarea[name="current_address"]');
            const permanentAddress = document.querySelector('textarea[name="permanent_address"]');
            if (currentAddress && permanentAddress) {
                permanentAddress.value = currentAddress.value;
            }
        }

        // Resume upload handling
        document.getElementById('resume')?.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                document.getElementById('fileName').textContent = file.name;
                document.getElementById('fileInfo').classList.add('active');
            }
        });

        document.getElementById('placement_resume')?.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                document.getElementById('placementFileName').textContent = file.name;
                document.getElementById('placementFileInfo').classList.add('active');
            }
        });

        function removeFile() {
            document.getElementById('resume').value = '';
            document.getElementById('fileInfo').classList.remove('active');
        }

        function removePlacementFile() {
            document.getElementById('placement_resume').value = '';
            document.getElementById('placementFileInfo').classList.remove('active');
        }

        document.getElementById('downloadVCF')?.addEventListener('click', function() {
            const vcfContent = `BEGIN:VCARD
VERSION:3.0
FN:<?php echo addslashes($site_title); ?>
ORG:<?php echo addslashes($site_title); ?> - <?php echo addslashes($site_tagline); ?>
TEL;TYPE=WORK,VOICE:<?php echo preg_replace('/[^0-9]/', '', $company_phone); ?>
TEL;TYPE=CELL,VOICE:<?php echo preg_replace('/[^0-9]/', '', $company_whatsapp); ?>
EMAIL;TYPE=INTERNET:<?php echo addslashes($company_email); ?>
ADR;TYPE=WORK:<?php echo addslashes($company_address); ?>;Rewa;Madhya Pradesh;486001;India
URL:https://hidk.in/
NOTE:Workforce solutions for everyday help. Working hours: <?php echo addslashes($company_hours); ?>. Emergency services available.
END:VCARD`;

            const blob = new Blob([vcfContent], {type: 'text/vcard'});
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'DK Associates Contact.vcf';
            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(url);
            document.body.removeChild(a);
        });

        // Chat functionality
        // ============================================
// FIXED CHAT FUNCTIONALITY - Direct integration without API
// ============================================
const chatToggle = document.getElementById('chatToggle');
const quickContactPopup = document.getElementById('quickContactPopup');
const chatWidget = document.getElementById('chatWidget');
const closeChat = document.getElementById('closeChat');
const openLiveChat = document.getElementById('openLiveChat');
const chatSendBtn = document.getElementById('chatSendBtn');
const chatInput = document.getElementById('chatInput');
const chatMessages = document.getElementById('chatMessages');
const chatInitForm = document.getElementById('chatInitForm');
const chatMessagesContainer = document.getElementById('chatMessagesContainer');
const chatInitFormElement = document.getElementById('chatInitFormElement');
const startChatBtn = document.getElementById('startChatBtn');

let chatPollInterval = null;
let lastMessageId = 0;
let currentSessionId = null;
let isChatActive = false;

// Toggle chat/quick contact
chatToggle?.addEventListener('click', function() {
    if (chatWidget?.classList.contains('show')) {
        chatWidget.classList.remove('show');
    } else {
        quickContactPopup?.classList.toggle('show');
    }
});

openLiveChat?.addEventListener('click', function(e) {
    e.preventDefault();
    quickContactPopup?.classList.remove('show');
    chatWidget?.classList.add('show');
});

closeChat?.addEventListener('click', function() {
    chatWidget?.classList.remove('show');
    if (chatPollInterval) {
        clearInterval(chatPollInterval);
        chatPollInterval = null;
    }
});

// Initialize chat session
if (chatInitFormElement) {
    chatInitFormElement.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const guestName = document.getElementById('guest_name').value.trim();
        const reason = document.getElementById('contact_reason').value;
        
        if (!guestName || !reason) {
            alert('Please fill in all required fields');
            return;
        }

        // Disable button to prevent double submission
        startChatBtn.disabled = true;
        startChatBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Starting...';

        // Generate a unique session ID
        currentSessionId = 'sess_' + Date.now() + '_' + Math.random().toString(36).substr(2, 8);
        
        // Send to server via AJAX to admin.php
        const formData = new FormData();
        formData.append('api', 'start_chat_session');
        formData.append('session_id', currentSessionId);
        formData.append('guest_name', guestName);
        formData.append('contact_reason', reason);
        formData.append('guest_email', document.getElementById('guest_email').value);
        formData.append('guest_phone', document.getElementById('guest_phone').value);
        formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');

        fetch('admin.php', {
            method: 'POST',
            body: new URLSearchParams(formData)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                isChatActive = true;
                chatInitForm.style.display = 'none';
                chatMessagesContainer.style.display = 'block';

                // Add welcome message
                const welcomeMsg = document.createElement('div');
                welcomeMsg.className = 'chat-message admin';
                welcomeMsg.innerHTML = `
                    <div class="message-bubble">
                        Welcome ${guestName}! How can we help you today?
                    </div>
                    <div class="message-time">Just now</div>
                `;
                chatMessages.appendChild(welcomeMsg);
                
                startChatPolling(currentSessionId);
            } else {
                alert('Failed to start chat: ' + (data.error || 'Unknown error'));
                startChatBtn.disabled = false;
                startChatBtn.innerHTML = 'Start Chat';
            }
        })
        .catch(err => {
            console.error('Chat session error:', err);
            alert('Failed to start chat. Please try again.');
            startChatBtn.disabled = false;
            startChatBtn.innerHTML = 'Start Chat';
        });
    });
}

function startChatPolling(sessionId) {
    if (chatPollInterval) clearInterval(chatPollInterval);
    
    chatPollInterval = setInterval(function() {
        if (!isChatActive) return;
        
        fetch(`admin.php?api=get_chat_messages&session_id=${encodeURIComponent(sessionId)}&since_id=${lastMessageId}`)
            .then(r => r.json())
            .then(data => {
                if (!data.messages) return;
                
                data.messages.forEach(msg => {
                    if (msg.id > lastMessageId) lastMessageId = msg.id;
                    
                    if (msg.sender_type === 'admin' || msg.sender_type === 'system') {
                        const messageDiv = document.createElement('div');
                        messageDiv.className = 'chat-message admin';
                        messageDiv.innerHTML = `
                            <div class="message-bubble">${msg.message.replace(/</g, '&lt;')}</div>
                            <div class="message-time">${msg.time || 'Just now'}</div>
                        `;
                        chatMessages.appendChild(messageDiv);
                        chatMessages.scrollTop = chatMessages.scrollHeight;
                    }
                });
                
                if (data.session_status === 'ended') {
                    clearInterval(chatPollInterval);
                    chatPollInterval = null;
                    isChatActive = false;
                    
                    const messageDiv = document.createElement('div');
                    messageDiv.className = 'chat-message admin';
                    messageDiv.innerHTML = `<div class="message-bubble" style="background:#fee2e2;color:#991b1b;">This chat session has been ended by an agent.</div>`;
                    chatMessages.appendChild(messageDiv);
                    chatMessages.scrollTop = chatMessages.scrollHeight;
                    chatInput.disabled = true;
                    chatSendBtn.disabled = true;
                }
            }).catch(() => {});
    }, 2000);
}

function sendMessage() {
    const msg = chatInput.value.trim();
    if (!msg || !currentSessionId || !isChatActive) return;

    const time = new Date().toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});

    // Add message to UI immediately
    const messageDiv = document.createElement('div');
    messageDiv.className = 'chat-message guest';
    messageDiv.innerHTML = `
        <div class="message-bubble">${msg.replace(/</g, '&lt;')}</div>
        <div class="message-time">${time}</div>
    `;
    chatMessages.appendChild(messageDiv);
    chatInput.value = '';
    chatMessages.scrollTop = chatMessages.scrollHeight;

    // Send to server
    const formData = new FormData();
    formData.append('api', 'send_chat_message');
    formData.append('session_id', currentSessionId);
    formData.append('message', msg);
    formData.append('sender_type', 'guest');

    fetch('admin.php', {
        method: 'POST',
        body: new URLSearchParams(formData)
    }).catch(() => {});
}

if (chatSendBtn) chatSendBtn.addEventListener('click', sendMessage);
if (chatInput) {
    chatInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            sendMessage();
        }
    });
}

// Close popups when clicking outside
document.addEventListener('click', function(e) {
    if (chatToggle && quickContactPopup && chatWidget) {
        if (!chatToggle.contains(e.target) && !quickContactPopup.contains(e.target) && !chatWidget.contains(e.target)) {
            quickContactPopup.classList.remove('show');
        }
    }
});

        const desiredPost = document.querySelector('select[name="desired_post"]');
        if (desiredPost) {
            desiredPost.addEventListener('change', function() {
                const otherContainer = document.getElementById('other_post_container');
                if (otherContainer) {
                    otherContainer.style.display = this.value === 'Other' ? 'block' : 'none';
                }
            });
        }

        const postApplied = document.getElementById('post_applied');
        if (postApplied) {
            postApplied.addEventListener('change', function() {
                const otherContainer = document.getElementById('other_position_container');
                if (otherContainer) {
                    otherContainer.style.display = this.value === 'Other' ? 'block' : 'none';
                }
            });
        }

        const urlParams = new URLSearchParams(window.location.search);
        const tabParam = urlParams.get('tab');
        if (tabParam && document.getElementById(tabParam + '-tab')) {
            switchTab(tabParam);
        } else {
            switchTab('home');
        }
    </script>
</body>
</html>
<?php 
// Close database connection
if (isset($db) && $db instanceof SQLite3) {
    $db->close();
}
?>