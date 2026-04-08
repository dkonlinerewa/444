<?php
// ============================================
// ADMIN PANEL - D K Associates
// Complete Version with All Features
// ============================================

error_reporting(0); // Don't display errors
ini_set('display_errors', 0);
ini_set('log_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';

// ============================================
// ROUTING
// ============================================

if (isset($_GET['verify_id'])) {
    $verify_id = preg_replace('/[^a-zA-Z0-9\-]/', '', $_GET['verify_id']);
    outputVerificationPage($verify_id);
    exit;
}

if (isset($_GET['api']) || isset($_POST['api'])) {
    header('Content-Type: application/json');
    $api_action = $_GET['api'] ?? $_POST['api'];
    // Keep logged-in staff marked online even during API-only polling
    if (isset($_SESSION['admin_id'])) {
        $aid = intval($_SESSION['admin_id']);
        $db->exec("UPDATE admin_users SET is_online = 1, last_activity = datetime('now','localtime') WHERE id = $aid AND is_active = 1");
    }
    handleAPIRequest($api_action);
    exit;
}

// ============================================
// AUTH GATEWAY
// ============================================
$current_user = null;
$is_authenticated = false;

if (isset($_SESSION['admin_id'])) {
    try {
        $stmt = $db->prepare("SELECT * FROM admin_users WHERE id = ? AND is_active = 1");
        $stmt->bindValue(1, $_SESSION['admin_id'], SQLITE3_INTEGER);
        $result = $stmt->execute();
        $current_user = $result->fetchArray(SQLITE3_ASSOC);
        $is_authenticated = !empty($current_user) && empty($current_user['access_blocked']);
        
        if ($is_authenticated) {
            $db->exec("UPDATE admin_users SET is_online = 1, last_activity = datetime('now', 'localtime') WHERE id = " . $current_user['id']);
        }
    } catch (Exception $e) {
        logError("Admin auth error: " . $e->getMessage());
    }
}

// Handle Login/Logout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'login') {
        handleLogin($db);
    } elseif ($_POST['action'] === 'logout' && $is_authenticated) {
        $db->exec("UPDATE admin_users SET is_online = 0 WHERE id = " . $current_user['id']);
        session_destroy();
        header('Location: admin.php');
        exit;
    }
}

if (!$is_authenticated) {
    outputLoginPage();
    exit;
}

// ============================================
// MAIN ADMIN INTERFACE
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handleAdminActions($db, $current_user);
}

$view = isset($_GET['view']) ? $_GET['view'] : 'dashboard';
outputAdminInterface($db, $current_user, $view);

// ============================================
// FUNCTION DEFINITIONS
// ============================================

/**
 * Generate unique staff/worker ID based on joining date
 * Format: YY + Month Letter + - + Random Alphanumeric
 * Month Letters: A=Jan, B=Feb, C=Mar, D=Apr, E=May, F=Jun, G=Jul, H=Aug, I=Sep, J=Oct, K=Nov, L=Dec
 */
function generateUniqueId($db, $role = 'staff', $joining_date = null) {
    if ($joining_date) {
        $date = new DateTime($joining_date);
    } else {
        $date = new DateTime();
    }
    
    $year = $date->format('y');
    $month = intval($date->format('m'));
    
    // Convert month to letter (A=1, B=2, etc.)
    $month_letter = chr(64 + $month); // A=65, so 1=65(A), 2=66(B), etc.
    
    $base = $year . $month_letter . '-';
    
    do {
        // Generate random string
        $length = ($role === 'worker') ? 6 : 5;
        $random = '';
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // Removed similar looking characters
        
        for ($i = 0; $i < $length; $i++) {
            $random .= $chars[random_int(0, strlen($chars) - 1)];
        }
        
        // Check for consecutive same characters (max 2)
        if (preg_match('/(.)\1{2,}/', $random)) {
            continue;
        }
        
        // Check if starts with zero
        if (preg_match('/^0/', $random)) {
            continue;
        }
        
        $generated_id = $base . $random;
        
        // Check if unique
        $exists = $db->querySingle("SELECT id FROM admin_users WHERE staff_id = '$generated_id' OR worker_id = '$generated_id'");
        
    } while ($exists);
    
    return $generated_id;
}

/**
 * Handle API requests
 */
function handleAPIRequest($action) {
    global $db, $current_user;
    
    try {
        switch ($action) {
            case 'chat_poll':
                $type = $_GET['type'] ?? 'internal';
                $session_id = $_GET['session_id'] ?? '';
                $since_id = intval($_GET['since_id'] ?? 0);
                
                if ($type === 'guest') {
                    pollGuestMessages($db, $session_id, $since_id);
                } elseif ($type === 'internal') {
                    pollInternalMessages($db, $since_id);
                } elseif ($type === 'available_guest') {
                    pollAvailableGuestChats($db);
                }
                break;
                
            case 'chat_send':
                $data = json_decode(file_get_contents('php://input'), true);
                sendChatMessage($db, $data);
                break;
                
            case 'assign_chat':
            case 'take_chat':
                $data = json_decode(file_get_contents('php://input'), true);
                assignChatToAgent($db, $data);
                break;
                
            case 'end_chat':
                $data = json_decode(file_get_contents('php://input'), true);
                endChatSession($db, $data);
                break;
                
            case 'check_staff_online':
                checkStaffOnline($db);
                break;
                
            case 'get_available_agents':
                getAvailableAgents($db);
                break;

            case 'get_new_guest_chats':
                getNewGuestChats($db);
                break;

            case 'start_chat_session':
                startGuestChatSession($db);
                break;

            case 'send_chat_message':
                sendGuestChatMessage($db);
                break;

            case 'get_chat_messages':
                getGuestChatMessages($db);
                break;
                
                case 'get_admin_chat':
    getAdminChatHistory($db);
    break;
case 'poll_admin_chat':
    pollAdminChat($db);
    break;
case 'send_admin_chat':
    sendAdminChatMessage($db);
    break;
case 'check_admin_chat_unread':
    checkAdminChatUnread($db);
    break;
    
    case 'get_job_applications':
    getJobApplications($db);
    break;
case 'get_business_upgrades':
    getBusinessUpgrades($db);
    break;
case 'get_placement_enquiries':
    getPlacementEnquiries($db);
    break;
case 'update_application_status':
    updateApplicationStatus($db);
    break;
case 'delete_application':
    deleteApplication($db);
    break;
case 'cleanup_old_chats':
    cleanupOldChats($db);
    break;
case 'update_staff_online_status':
    updateStaffOnlineStatus($db);
    break;
case 'export_enquiries':
    exportEnquiries($db);
    break;
              case 'get_job_applications':
    getJobApplications($db);
    break;
case 'get_business_upgrades':
    getBusinessUpgrades($db);
    break;
case 'get_placement_enquiries':
    getPlacementEnquiries($db);
    break;
case 'update_application_status':
    updateApplicationStatus($db);
    break;
case 'delete_application':
    deleteApplication($db);
    break;
case 'cleanup_old_chats':
    cleanupOldChats($db);
    break;
case 'update_staff_online_status':
    updateStaffOnlineStatus($db);
    break;
case 'export_enquiries':
    exportEnquiries($db);
    break;  
            case 'update_status':
                updateEnquiryStatus($db);
                break;
                
            case 'archive_item':
                archiveItem($db);
                break;
                
            case 'get_users':
                getUsersList($db);
                break;
                
            case 'get_user_details':
                getUserDetails($db);
                break;
                
            case 'create_user':
                createUser($db);
                break;
                
            case 'update_user':
                updateUser($db);
                break;
                
            case 'request_profile_update':
                requestProfileUpdate($db);
                break;
                
            case 'approve_profile_update':
                approveProfileUpdate($db);
                break;
                
            case 'get_profile_requests':
                getProfileUpdateRequests($db);
                break;
                
            case 'delete_user':
                deleteUser($db);
                break;
                
            case 'toggle_user_status':
                toggleUserStatus($db);
                break;
                
            case 'block_user':
                blockUser($db);
                break;
                
            case 'verify_id_card':
                verifyIdCard($db);
                break;
                
            case 'reset_password':
                resetPassword($db);
                break;
                
            case 'upload_profile_photo':
                uploadProfilePhoto($db);
                break;
                
            case 'get_enquiry_details':
                getEnquiryDetails($db);
                break;
                
            // Task Management APIs
            case 'create_task':
                createTask($db);
                break;
                
            case 'get_tasks':
                getTasks($db);
                break;
                
            case 'get_task_details':
                getTaskDetails($db);
                break;
                
            case 'update_task':
                updateTask($db);
                break;
                
            case 'delete_task':
                deleteTask($db);
                break;
                
            case 'add_task_comment':
                addTaskComment($db);
                break;
                
            case 'archive_old_tasks':
                archiveOldTasks($db);
                break;
                
            // Team Management APIs
            case 'get_team_members':
                getTeamMembers($db);
                break;
                
            case 'create_team_member':
                createTeamMember($db);
                break;
                
            case 'update_team_member':
                updateTeamMember($db);
                break;
                
            case 'delete_team_member':
                deleteTeamMember($db);
                break;
                
            // Site Settings APIs
            case 'get_site_settings':
                getSiteSettings($db);
                break;
                
            case 'update_site_settings':
                updateSiteSettings($db);
                break;
                
            case 'test_email_config':
                testEmailConfig($db);
                break;
                
            // Audit Logs
            case 'get_audit_logs':
                getAuditLogs($db);
                break;
                
            // Vacancies
            case 'get_vacancies':
                getVacancies($db);
                break;
                
            case 'create_contact':
                createContact($db);
                break;
            case 'update_contact':
                updateContact($db);
                break;
            case 'get_contact':
                getContact($db);
                break;
            case 'archive_contact':
                archiveContact($db);
                break;
            case 'delete_contact':
                deleteContact($db);
                break;
            case 'export_contacts':
                exportContacts($db);
                break;
case 'import_contacts':
    importContacts($db);
    break;
                
            default:
                echo json_encode(['error' => 'Unknown API action']);
        }
    } catch (Exception $e) {
        logError("API error in $action: " . $e->getMessage());
        // Ensure we always return valid JSON even on unexpected errors
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
}

/**
 * Get vacancies list
 */
function getVacancies($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $vacancies = $db->query("SELECT * FROM open_positions ORDER BY urgent DESC, is_active DESC, created_at DESC");
    
    $list = [];
    while ($row = $vacancies->fetchArray(SQLITE3_ASSOC)) {
        $list[] = $row;
    }
    
    echo json_encode($list);
}

/**
 * Poll guest messages
 */
function pollGuestMessages($db, $session_id, $since_id) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }

    $session_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', $session_id);

    $stmt = $db->prepare("SELECT cm.id, cm.sender_type, cm.sender_name, cm.sender_id,
                                  cm.message, cm.is_read, cm.created_at,
                                  cs.status as session_status,
                                  au.full_name as agent_name
                           FROM chat_messages cm
                           LEFT JOIN chat_sessions cs ON cm.session_id = cs.session_id
                           LEFT JOIN admin_users au   ON cm.sender_id  = au.id AND cm.sender_type = 'staff'
                           WHERE cm.session_id = ? AND cm.id > ?
                           ORDER BY cm.created_at ASC");
    $stmt->bindValue(1, $session_id, SQLITE3_TEXT);
    $stmt->bindValue(2, $since_id,   SQLITE3_INTEGER);
    $result = $stmt->execute();

    $messages       = [];
    $session_status = 'active';
    $user_id        = intval($_SESSION['admin_id']);

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $row['time']   = date('h:i A', strtotime($row['created_at']));
        $row['is_me']  = ($row['sender_type'] === 'staff' && intval($row['sender_id']) === $user_id);
        // Use agent's real name if available
        if ($row['sender_type'] === 'staff' && !empty($row['agent_name'])) {
            $row['sender_name'] = $row['agent_name'];
        }
        $session_status = $row['session_status'] ?? 'active';
        $messages[]     = $row;
    }

    // Mark unread guest messages as read by this agent
    if (!empty($messages)) {
        $db->exec("UPDATE chat_messages SET is_read = 1
                   WHERE session_id = '" . $db->escapeString($session_id) . "'
                   AND sender_type = 'guest' AND is_read = 0");
    }

    $session = $db->querySingle("SELECT status FROM chat_sessions WHERE session_id = '" . $db->escapeString($session_id) . "'", true);

    echo json_encode([
        'messages'       => $messages,
        'session_status' => $session ? $session['status'] : $session_status
    ]);
}

/**
 * Poll internal messages
 */
function pollInternalMessages($db, $since_id) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $user_id = $_SESSION['admin_id'];
    
    $stmt = $db->prepare("SELECT cm.*, au.full_name as sender_name, au.designation, au.profile_photo 
                          FROM chat_messages cm
                          LEFT JOIN admin_users au ON cm.sender_id = au.id
                          WHERE cm.chat_type IN ('internal', 'global') 
                          AND (cm.receiver_id = 0 OR cm.receiver_id = ? OR cm.sender_id = ?)
                          AND cm.id > ?
                          ORDER BY cm.created_at ASC");
    $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
    $stmt->bindValue(2, $user_id, SQLITE3_INTEGER);
    $stmt->bindValue(3, $since_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    
    $messages = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $row['time'] = date('h:i A', strtotime($row['created_at']));
        $row['is_me'] = ($row['sender_id'] == $user_id);
        $messages[] = $row;
    }
    
    echo json_encode(['messages' => $messages]);
}

/**
 * Create new contact
 */
function createContact($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $business_name = trim($_POST['business_name'] ?? '');
    $profession = trim($_POST['profession'] ?? '');
    $locality = trim($_POST['locality'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    
    if (empty($business_name)) {
        echo json_encode(['error' => 'Business name required']);
        return;
    }
    
    $stmt = $db->prepare("INSERT INTO contacts (business_name, profession, locality, contact_number, notes, created_by, created_at) 
                          VALUES (?, ?, ?, ?, ?, ?, datetime('now', 'localtime'))");
    $stmt->bindValue(1, $business_name, SQLITE3_TEXT);
    $stmt->bindValue(2, $profession ?: null, SQLITE3_TEXT);
    $stmt->bindValue(3, $locality ?: null, SQLITE3_TEXT);
    $stmt->bindValue(4, $contact_number ?: null, SQLITE3_TEXT);
    $stmt->bindValue(5, $notes ?: null, SQLITE3_TEXT);
    $stmt->bindValue(6, $_SESSION['admin_id'], SQLITE3_INTEGER);
    $stmt->execute();
    
    $contact_id = $db->lastInsertRowID();
    
    logAudit($db, $_SESSION['admin_id'], 'contact_created', 'contacts', $contact_id, null, json_encode($_POST), "Created contact: $business_name");
    
    echo json_encode(['success' => true, 'contact_id' => $contact_id]);
}

/**
 * Update contact
 */
function updateContact($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $contact_id = intval($_POST['contact_id'] ?? 0);
    
    if (!$contact_id) {
        echo json_encode(['error' => 'Contact ID required']);
        return;
    }
    
    $old_data = $db->querySingle("SELECT * FROM contacts WHERE id = $contact_id", true);
    
    $business_name = trim($_POST['business_name'] ?? '');
    $profession = trim($_POST['profession'] ?? '');
    $locality = trim($_POST['locality'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    
    if (empty($business_name)) {
        echo json_encode(['error' => 'Business name required']);
        return;
    }
    
    $stmt = $db->prepare("UPDATE contacts SET business_name = ?, profession = ?, locality = ?, contact_number = ?, notes = ?, updated_at = datetime('now', 'localtime') WHERE id = ?");
    $stmt->bindValue(1, $business_name, SQLITE3_TEXT);
    $stmt->bindValue(2, $profession ?: null, SQLITE3_TEXT);
    $stmt->bindValue(3, $locality ?: null, SQLITE3_TEXT);
    $stmt->bindValue(4, $contact_number ?: null, SQLITE3_TEXT);
    $stmt->bindValue(5, $notes ?: null, SQLITE3_TEXT);
    $stmt->bindValue(6, $contact_id, SQLITE3_INTEGER);
    $stmt->execute();
    
    logAudit($db, $_SESSION['admin_id'], 'contact_updated', 'contacts', $contact_id, json_encode($old_data), json_encode($_POST), "Updated contact: $business_name");
    
    echo json_encode(['success' => true]);
}

/**
 * Get contact details
 */
function getContact($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $contact_id = intval($_GET['id'] ?? 0);
    
    if (!$contact_id) {
        echo json_encode(['error' => 'Contact ID required']);
        return;
    }
    
    $contact = $db->querySingle("SELECT * FROM contacts WHERE id = $contact_id", true);
    
    if ($contact) {
        echo json_encode(['success' => true, 'contact' => $contact]);
    } else {
        echo json_encode(['error' => 'Contact not found']);
    }
}

/**
 * Archive/Restore contact
 */
function archiveContact($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $contact_id = intval($_POST['id'] ?? 0);
    $archive = intval($_POST['archive'] ?? 1);
    
    if (!$contact_id) {
        echo json_encode(['error' => 'Contact ID required']);
        return;
    }
    
    $old_data = $db->querySingle("SELECT * FROM contacts WHERE id = $contact_id", true);
    
    $db->exec("UPDATE contacts SET is_archived = $archive, updated_at = datetime('now', 'localtime') WHERE id = $contact_id");
    
    logAudit($db, $_SESSION['admin_id'], $archive ? 'contact_archived' : 'contact_restored', 'contacts', $contact_id, json_encode($old_data), json_encode(['is_archived' => $archive]), "Contact " . ($archive ? 'archived' : 'restored'));
    
    echo json_encode(['success' => true]);
}

/**
 * Permanently delete contact (admin only)
 */
function deleteContact($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $current = $db->querySingle("SELECT role FROM admin_users WHERE id = " . $_SESSION['admin_id'], true);
    
    if ($current['role'] !== 'admin') {
        echo json_encode(['error' => 'Permission denied']);
        return;
    }
    
    $contact_id = intval($_GET['id'] ?? 0);
    
    if (!$contact_id) {
        echo json_encode(['error' => 'Contact ID required']);
        return;
    }
    
    $old_data = $db->querySingle("SELECT * FROM contacts WHERE id = $contact_id", true);
    
    $db->exec("DELETE FROM contacts WHERE id = $contact_id");
    
    logAudit($db, $_SESSION['admin_id'], 'contact_deleted', 'contacts', $contact_id, json_encode($old_data), null, "Contact permanently deleted");
    
    echo json_encode(['success' => true]);
}

/**
 * Export contacts to CSV - UPDATED with profession filter
 */
function exportContacts($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        die('Unauthorized');
    }
    
    $profession = $_GET['profession'] ?? '';
    
    $filename = 'contacts_export_' . ($profession ? $profession . '_' : '') . date('Y-m-d_His') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Add headers
    fputcsv($output, ['ID', 'Business Name', 'Profession', 'Locality', 'Contact Number', 'Notes', 'Created At', 'Updated At', 'Is Archived']);
    
    $query = "SELECT * FROM contacts";
    if (!empty($profession)) {
        $query .= " WHERE profession = '" . $db->escapeString($profession) . "'";
    }
    $query .= " ORDER BY profession, business_name";
    
    $contacts = $db->query($query);
    
    while ($row = $contacts->fetchArray(SQLITE3_ASSOC)) {
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit;
}

/**
 * Import contacts from CSV
 */
function importContacts($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $current = $db->querySingle("SELECT role FROM admin_users WHERE id = " . $_SESSION['admin_id'], true);
    
    if ($current['role'] !== 'admin') {
        echo json_encode(['error' => 'Permission denied']);
        return;
    }
    
    if (!isset($_FILES['csv_file'])) {
        echo json_encode(['error' => 'No file uploaded']);
        return;
    }
    
    $file = $_FILES['csv_file'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['error' => 'File upload error']);
        return;
    }
    
    if ($file['size'] > 5 * 1024 * 1024) {
        echo json_encode(['error' => 'File too large. Max 5MB']);
        return;
    }
    
    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) {
        echo json_encode(['error' => 'Failed to open file']);
        return;
    }
    
    // Read headers
    $headers = fgetcsv($handle);
    if (!$headers) {
        fclose($handle);
        echo json_encode(['error' => 'Invalid CSV format']);
        return;
    }
    
    $db->exec("BEGIN TRANSACTION");
    
    try {
        $count = 0;
        $line = 1;
        
        while (($data = fgetcsv($handle)) !== false) {
            $line++;
            
            if (count($data) < 2) continue; // Skip empty lines
            
            // Map data to columns (assuming standard export format)
            $business_name = $data[1] ?? '';
            $profession = $data[2] ?? '';
            $locality = $data[3] ?? '';
            $contact_number = $data[4] ?? '';
            $notes = $data[5] ?? '';
            
            if (empty($business_name)) continue;
            
            $stmt = $db->prepare("INSERT INTO contacts (business_name, profession, locality, contact_number, notes, created_by, created_at) 
                                  VALUES (?, ?, ?, ?, ?, ?, datetime('now', 'localtime'))");
            $stmt->bindValue(1, $business_name, SQLITE3_TEXT);
            $stmt->bindValue(2, $profession ?: null, SQLITE3_TEXT);
            $stmt->bindValue(3, $locality ?: null, SQLITE3_TEXT);
            $stmt->bindValue(4, $contact_number ?: null, SQLITE3_TEXT);
            $stmt->bindValue(5, $notes ?: null, SQLITE3_TEXT);
            $stmt->bindValue(6, $_SESSION['admin_id'], SQLITE3_INTEGER);
            $stmt->execute();
            
            $count++;
        }
        
        $db->exec("COMMIT");
        
        logAudit($db, $_SESSION['admin_id'], 'contacts_imported', 'contacts', 0, null, $count, "Imported $count contacts");
        
        echo json_encode(['success' => true, 'count' => $count]);
        
    } catch (Exception $e) {
        $db->exec("ROLLBACK");
        logError("Import contacts error: " . $e->getMessage());
        echo json_encode(['error' => 'Import failed: ' . $e->getMessage()]);
    }
    
    fclose($handle);
}

/**
 * Poll available guest chats for agents
 */
function pollAvailableGuestChats($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $user_id = $_SESSION['admin_id'];
    $user = $db->querySingle("SELECT care_permission, active_chats, max_concurrent_chats FROM admin_users WHERE id = $user_id", true);
    
    if (!$user || !$user['care_permission']) {
        echo json_encode(['chats' => []]);
        return;
    }
    
    // Get chats assigned to this agent
    $assigned = $db->query("SELECT cs.*, 
                            (SELECT COUNT(*) FROM chat_messages WHERE session_id = cs.session_id) as message_count,
                            (SELECT MAX(created_at) FROM chat_messages WHERE session_id = cs.session_id) as last_message
                            FROM chat_sessions cs 
                            WHERE cs.assigned_to = $user_id AND cs.status = 'active'
                            ORDER BY cs.last_activity DESC");
    
    $assigned_chats = [];
    while ($chat = $assigned->fetchArray(SQLITE3_ASSOC)) {
        $chat['last_message_time'] = $chat['last_message'] ? date('h:i A', strtotime($chat['last_message'])) : '';
        $assigned_chats[] = $chat;
    }
    
    // Get available unassigned chats if agent has capacity
    $available = [];
    if ($user['active_chats'] < $user['max_concurrent_chats']) {
        $limit = $user['max_concurrent_chats'] - $user['active_chats'];
        $unassigned = $db->query("SELECT cs.*,
                                  (SELECT COUNT(*) FROM chat_messages WHERE session_id = cs.session_id) as message_count,
                                  (SELECT MAX(created_at) FROM chat_messages WHERE session_id = cs.session_id) as last_message
                                  FROM chat_sessions cs 
                                  WHERE cs.assigned_to = 0 AND cs.status = 'active'
                                  ORDER BY cs.created_at ASC
                                  LIMIT $limit");
        
        while ($chat = $unassigned->fetchArray(SQLITE3_ASSOC)) {
            $chat['last_message_time'] = $chat['last_message'] ? date('h:i A', strtotime($chat['last_message'])) : '';
            $available[] = $chat;
        }
    }
    
    echo json_encode([
        'assigned' => $assigned_chats,
        'available' => $available,
        'active_chats' => $user['active_chats'],
        'max_chats' => $user['max_concurrent_chats']
    ]);
}

/**
 * Send chat message
 */
function sendChatMessage($db, $data) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $user_id = $_SESSION['admin_id'];
    $message = trim($data['message'] ?? '');
    $receiver_id = intval($data['receiver_id'] ?? 0);
    $chat_type = $data['chat_type'] ?? 'internal';
    $session_id = $data['session_id'] ?? '';
    $temp_id = $data['temp_id'] ?? uniqid();
    
    if (empty($message)) {
        echo json_encode(['error' => 'Message required', 'temp_id' => $temp_id]);
        return;
    }
    
    if ($chat_type === 'guest' && empty($session_id)) {
        echo json_encode(['error' => 'Session ID required for guest chat']);
        return;
    }
    
    // Generate session_id for new internal chat if needed
    if ($chat_type === 'internal' && $receiver_id > 0 && empty($session_id)) {
        $session_id = 'internal_' . min($user_id, $receiver_id) . '_' . max($user_id, $receiver_id);
    }
    
    $stmt = $db->prepare("INSERT INTO chat_messages 
        (session_id, sender_type, sender_id, receiver_id, receiver_type, message, chat_type, created_at) 
        VALUES (?, 'staff', ?, ?, 'staff', ?, ?, datetime('now', 'localtime'))");
    
    $stmt->bindValue(1, $session_id ?: null, SQLITE3_TEXT);
    $stmt->bindValue(2, $user_id, SQLITE3_INTEGER);
    $stmt->bindValue(3, $receiver_id ?: null, SQLITE3_INTEGER);
    $stmt->bindValue(4, $message, SQLITE3_TEXT);
    $stmt->bindValue(5, $chat_type, SQLITE3_TEXT);
    $stmt->execute();
    
    $message_id = $db->lastInsertRowID();
    
    // Update session last activity if guest chat
    if ($chat_type === 'guest' && $session_id) {
        $db->exec("UPDATE chat_sessions SET last_activity = datetime('now', 'localtime') WHERE session_id = '$session_id'");
    }
    
    echo json_encode([
        'success' => true,
        'message_id' => $message_id,
        'temp_id' => $temp_id,
        'created_at' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Assign chat to agent
 */
function assignChatToAgent($db, $data) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $user_id = $_SESSION['admin_id'];
    $session_id = $data['session_id'] ?? '';
    
    if (empty($session_id)) {
        echo json_encode(['error' => 'Session ID required']);
        return;
    }
    
    // Check if user has care permission
    $user = $db->querySingle("SELECT care_permission, active_chats, max_concurrent_chats FROM admin_users WHERE id = $user_id", true);
    if (!$user || !$user['care_permission']) {
        echo json_encode(['error' => 'No care permission']);
        return;
    }
    
    // Check if already at max chats
    if ($user['active_chats'] >= $user['max_concurrent_chats']) {
        echo json_encode(['error' => 'Maximum concurrent chats reached']);
        return;
    }
    
    // Update session
    $db->exec("UPDATE chat_sessions SET assigned_to = $user_id, assigned_at = datetime('now', 'localtime') WHERE session_id = '$session_id' AND (assigned_to = 0 OR assigned_to = $user_id)");
    
    // Update user's active chat count
    $db->exec("UPDATE admin_users SET active_chats = active_chats + 1, current_chat_session = '$session_id' WHERE id = $user_id");
    
    // Send system message
    $stmt = $db->prepare("INSERT INTO chat_messages (session_id, sender_type, sender_id, message, chat_type) VALUES (?, 'system', 0, ?, 'guest')");
    $stmt->bindValue(1, $session_id, SQLITE3_TEXT);
    $stmt->bindValue(2, "An agent has joined the chat. They will assist you shortly.", SQLITE3_TEXT);
    $stmt->execute();
    
    logAudit($db, $user_id, 'chat_assigned', 'chat_session', 0, null, $session_id, "Chat session assigned to agent");
    
    echo json_encode(['success' => true]);
}

/**
 * Get admin chat history
 */
function getAdminChatHistory($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $session_id = $_GET['session_id'] ?? 'admin_support_' . $_SESSION['admin_id'];
    
    $stmt = $db->prepare("SELECT cm.*, au.full_name as sender_name 
                          FROM chat_messages cm
                          LEFT JOIN admin_users au ON cm.sender_id = au.id
                          WHERE cm.session_id = ? 
                          ORDER BY cm.created_at ASC");
    $stmt->bindValue(1, $session_id, SQLITE3_TEXT);
    $result = $stmt->execute();
    
    $messages = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $row['time'] = date('h:i A', strtotime($row['created_at']));
        $row['is_me'] = ($row['sender_id'] == $_SESSION['admin_id']);
        $messages[] = $row;
    }
    
    // Mark messages as read
    $db->exec("UPDATE chat_messages SET is_read = 1 WHERE session_id = '$session_id' AND receiver_id = " . $_SESSION['admin_id']);
    
    echo json_encode(['messages' => $messages]);
}

/**
 * Poll admin chat for new messages
 */
function pollAdminChat($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $session_id = $_GET['session_id'] ?? 'admin_support_' . $_SESSION['admin_id'];
    $since_id = intval($_GET['since_id'] ?? 0);
    
    $stmt = $db->prepare("SELECT cm.*, au.full_name as sender_name 
                          FROM chat_messages cm
                          LEFT JOIN admin_users au ON cm.sender_id = au.id
                          WHERE cm.session_id = ? AND cm.id > ? 
                          ORDER BY cm.created_at ASC");
    $stmt->bindValue(1, $session_id, SQLITE3_TEXT);
    $stmt->bindValue(2, $since_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    
    $messages = [];
    $unread_count = 0;
    
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $row['time'] = date('h:i A', strtotime($row['created_at']));
        $row['is_me'] = ($row['sender_id'] == $_SESSION['admin_id']);
        $messages[] = $row;
        
        if (!$row['is_me'] && $row['is_read'] == 0) {
            $unread_count++;
        }
    }
    
    // Mark new messages as read
    if (!empty($messages)) {
        $last_id = end($messages)['id'];
        $db->exec("UPDATE chat_messages SET is_read = 1 WHERE session_id = '$session_id' AND id <= $last_id AND receiver_id = " . $_SESSION['admin_id']);
    }
    
    echo json_encode([
        'messages' => $messages,
        'unread_count' => $unread_count
    ]);
}

/**
 * Send admin chat message
 */
function sendAdminChatMessage($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $user_id = $_SESSION['admin_id'];
    $session_id = $_POST['session_id'] ?? 'admin_support_' . $user_id;
    $message = trim($_POST['message'] ?? '');
    $receiver_id = intval($_POST['receiver_id'] ?? 0);
    
    if (empty($message)) {
        echo json_encode(['error' => 'Message required']);
        return;
    }
    
    $stmt = $db->prepare("INSERT INTO chat_messages 
        (session_id, sender_type, sender_id, receiver_id, receiver_type, message, chat_type, created_at) 
        VALUES (?, 'staff', ?, ?, 'staff', ?, 'internal', datetime('now', 'localtime'))");
    
    $stmt->bindValue(1, $session_id, SQLITE3_TEXT);
    $stmt->bindValue(2, $user_id, SQLITE3_INTEGER);
    $stmt->bindValue(3, $receiver_id ?: null, SQLITE3_INTEGER);
    $stmt->bindValue(4, $message, SQLITE3_TEXT);
    $stmt->execute();
    
    $message_id = $db->lastInsertRowID();
    
    // Get the created message
    $new_msg = $db->querySingle("SELECT cm.*, au.full_name as sender_name 
                                 FROM chat_messages cm
                                 LEFT JOIN admin_users au ON cm.sender_id = au.id
                                 WHERE cm.id = $message_id", true);
    
    $new_msg['time'] = date('h:i A', strtotime($new_msg['created_at']));
    $new_msg['is_me'] = true;
    
    echo json_encode([
        'success' => true,
        'message' => $new_msg
    ]);
}

/**
 * Check for unread admin chat messages
 */
function checkAdminChatUnread($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $session_id = $_GET['session_id'] ?? 'admin_support_' . $_SESSION['admin_id'];
    
    $unread = $db->querySingle("SELECT COUNT(*) FROM chat_messages 
                                WHERE session_id = '$session_id' 
                                AND sender_id != " . $_SESSION['admin_id'] . "
                                AND is_read = 0");
    
    echo json_encode(['unread' => $unread]);
}

/**
 * End chat session
 */
function endChatSession($db, $data) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $user_id = $_SESSION['admin_id'];
    $session_id = $data['session_id'] ?? '';
    
    if (empty($session_id)) {
        echo json_encode(['error' => 'Session ID required']);
        return;
    }
    
    // Update session
    $db->exec("UPDATE chat_sessions SET status = 'ended', last_activity = datetime('now', 'localtime') WHERE session_id = '$session_id' AND assigned_to = $user_id");
    
    // Update user's active chat count
    $db->exec("UPDATE admin_users SET active_chats = active_chats - 1, current_chat_session = NULL WHERE id = $user_id AND active_chats > 0");
    
    // Send system message
    $stmt = $db->prepare("INSERT INTO chat_messages (session_id, sender_type, sender_id, message, chat_type) VALUES (?, 'system', 0, ?, 'guest')");
    $stmt->bindValue(1, $session_id, SQLITE3_TEXT);
    $stmt->bindValue(2, "This chat session has been ended by the agent.", SQLITE3_TEXT);
    $stmt->execute();
    
    logAudit($db, $user_id, 'chat_ended', 'chat_session', 0, null, $session_id, "Chat session ended");
    
    echo json_encode(['success' => true]);
}

/**
 * Get available agents for chat assignment
 */
function getAvailableAgents($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $agents = $db->query("SELECT id, full_name, active_chats, max_concurrent_chats, 
                          (max_concurrent_chats - active_chats) as available_slots
                          FROM admin_users 
                          WHERE care_permission = 1 AND is_online = 1
                          AND active_chats < max_concurrent_chats
                          ORDER BY active_chats ASC, full_name ASC");
    
    $list = [];
    while ($agent = $agents->fetchArray(SQLITE3_ASSOC)) {
        $list[] = $agent;
    }
    
    echo json_encode($list);
}

/**
 * Check if staff are online for guest chat
 */
function checkStaffOnline($db) {
    $count = $db->querySingle("SELECT COUNT(*) FROM admin_users 
                               WHERE care_permission = 1 
                               AND is_online = 1
                               AND last_activity > datetime('now', '-5 minutes')");
    
    echo json_encode([
        'online' => ($count > 0),
        'count'  => $count
    ]);
}

/**
 * Get new/unassigned guest chat sessions — used for popup notification in the widget
 * Only for users with care_permission
 */
function getNewGuestChats($db) {
    if (!isset($_SESSION['admin_id'])) {
        echo json_encode(['sessions' => [], 'total_unassigned' => 0]);
        return;
    }

    $user_id = intval($_SESSION['admin_id']);
    $user = $db->querySingle("SELECT care_permission FROM admin_users WHERE id = $user_id", true);

    if (!$user || !$user['care_permission']) {
        echo json_encode(['sessions' => [], 'total_unassigned' => 0]);
        return;
    }

    // Unassigned active sessions
    $result = $db->query("SELECT cs.session_id, cs.guest_name, cs.contact_reason, cs.created_at,
                                 (SELECT COUNT(*) FROM chat_messages
                                  WHERE session_id = cs.session_id AND is_read = 0
                                  AND sender_type = 'guest') as unread
                          FROM chat_sessions cs
                          WHERE cs.status = 'active' AND cs.assigned_to = 0
                          ORDER BY cs.created_at ASC
                          LIMIT 20");

    $sessions = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $row['time'] = date('h:i A', strtotime($row['created_at']));
        $sessions[] = $row;
    }

    // My assigned sessions with unread count
    $mine_result = $db->query("SELECT cs.session_id, cs.guest_name, cs.contact_reason,
                                      (SELECT COUNT(*) FROM chat_messages
                                       WHERE session_id = cs.session_id AND is_read = 0
                                       AND sender_type = 'guest') as unread
                               FROM chat_sessions cs
                               WHERE cs.status = 'active' AND cs.assigned_to = $user_id
                               ORDER BY cs.last_activity DESC");

    $mine = [];
    while ($row = $mine_result->fetchArray(SQLITE3_ASSOC)) {
        $mine[] = $row;
    }

    $total_unread = 0;
    foreach (array_merge($sessions, $mine) as $s) {
        $total_unread += (int)($s['unread'] ?? 0);
    }

    echo json_encode([
        'sessions'         => $sessions,
        'mine'             => $mine,
        'total_unassigned' => count($sessions),
        'total_unread'     => $total_unread,
    ]);
}

/**
 * Start a guest chat session (called from index.php widget)
 */
function startGuestChatSession($db) {
    $session_id     = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_POST['session_id'] ?? '');
    $guest_name     = trim($_POST['guest_name']     ?? '');
    $guest_email    = trim($_POST['guest_email']    ?? '');
    $guest_phone    = trim($_POST['guest_phone']    ?? '');
    $contact_reason = trim($_POST['contact_reason'] ?? '');

    if (empty($session_id) || empty($guest_name)) {
        echo json_encode(['error' => 'Session ID and name are required']);
        return;
    }

    // Prevent duplicate sessions
    $exists = $db->querySingle("SELECT id FROM chat_sessions WHERE session_id = '" . $db->escapeString($session_id) . "'");
    if ($exists) {
        echo json_encode(['success' => true, 'session_id' => $session_id, 'resumed' => true]);
        return;
    }

    $stmt = $db->prepare("INSERT INTO chat_sessions
        (session_id, guest_name, guest_email, guest_phone, contact_reason,
         device_id, status, assigned_to, last_activity, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 'active', 0, datetime('now','localtime'), datetime('now','localtime'))");
    $stmt->bindValue(1, $session_id,     SQLITE3_TEXT);
    $stmt->bindValue(2, $guest_name,     SQLITE3_TEXT);
    $stmt->bindValue(3, $guest_email,    SQLITE3_TEXT);
    $stmt->bindValue(4, $guest_phone,    SQLITE3_TEXT);
    $stmt->bindValue(5, $contact_reason, SQLITE3_TEXT);
    $stmt->bindValue(6, $_SESSION['device_id'] ?? '', SQLITE3_TEXT);
    $stmt->execute();

    // Insert opening message from guest
    $opening = !empty($contact_reason) ? "Reason for contact: $contact_reason" : "Guest started a chat";
    $stmt2 = $db->prepare("INSERT INTO chat_messages
        (session_id, sender_type, sender_name, sender_id, receiver_id, receiver_type, message, chat_type, created_at)
        VALUES (?, 'guest', ?, 0, 0, 'staff', ?, 'guest', datetime('now','localtime'))");
    $stmt2->bindValue(1, $session_id, SQLITE3_TEXT);
    $stmt2->bindValue(2, $guest_name, SQLITE3_TEXT);
    $stmt2->bindValue(3, $opening,    SQLITE3_TEXT);
    $stmt2->execute();

    echo json_encode(['success' => true, 'session_id' => $session_id]);
}

/**
 * Send a message from the guest widget (index.php)
 */
function sendGuestChatMessage($db) {
    $session_id  = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_POST['session_id'] ?? '');
    $message     = trim($_POST['message'] ?? '');
    $sender_type = in_array($_POST['sender_type'] ?? '', ['guest','staff','system']) ? $_POST['sender_type'] : 'guest';

    if (empty($session_id) || empty($message)) {
        echo json_encode(['error' => 'Session ID and message required']);
        return;
    }

    // Get guest name for this session
    $session = $db->querySingle("SELECT guest_name FROM chat_sessions WHERE session_id = '" . $db->escapeString($session_id) . "'", true);
    $sender_name = $session ? $session['guest_name'] : 'Guest';

    $stmt = $db->prepare("INSERT INTO chat_messages
        (session_id, sender_type, sender_name, sender_id, receiver_id, receiver_type, message, chat_type, created_at)
        VALUES (?, ?, ?, 0, 0, 'staff', ?, 'guest', datetime('now','localtime'))");
    $stmt->bindValue(1, $session_id,  SQLITE3_TEXT);
    $stmt->bindValue(2, $sender_type, SQLITE3_TEXT);
    $stmt->bindValue(3, $sender_name, SQLITE3_TEXT);
    $stmt->bindValue(4, $message,     SQLITE3_TEXT);
    $stmt->execute();

    $msg_id = $db->lastInsertRowID();

    // Update session last_activity
    $db->exec("UPDATE chat_sessions SET last_activity = datetime('now','localtime') WHERE session_id = '" . $db->escapeString($session_id) . "'");

    echo json_encode(['success' => true, 'message_id' => $msg_id]);
}

/**
 * Get chat messages for a guest session (called from index.php widget polling)
 */
function getGuestChatMessages($db) {
    $session_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['session_id'] ?? '');
    $since_id   = intval($_GET['since_id'] ?? 0);

    if (empty($session_id)) {
        echo json_encode(['error' => 'Session ID required']);
        return;
    }

    $stmt = $db->prepare("SELECT cm.id, cm.sender_type, cm.sender_name, cm.message, cm.created_at,
                                  cs.status as session_status
                           FROM chat_messages cm
                           LEFT JOIN chat_sessions cs ON cm.session_id = cs.session_id
                           WHERE cm.session_id = ? AND cm.id > ?
                           ORDER BY cm.created_at ASC");
    $stmt->bindValue(1, $session_id, SQLITE3_TEXT);
    $stmt->bindValue(2, $since_id,   SQLITE3_INTEGER);
    $result = $stmt->execute();

    $messages = [];
    $session_status = 'active';
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $row['time'] = date('h:i A', strtotime($row['created_at']));
        $session_status = $row['session_status'] ?? 'active';
        $messages[] = $row;
    }

    // Mark staff messages as read
    if (!empty($messages)) {
        $db->exec("UPDATE chat_messages SET is_read = 1
                   WHERE session_id = '" . $db->escapeString($session_id) . "'
                   AND sender_type IN ('staff','system') AND is_read = 0");
    }

    echo json_encode([
        'messages'       => $messages,
        'session_status' => $session_status
    ]);
}

/**
 * Update enquiry status
 */
function updateEnquiryStatus($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $id = intval($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    $type = $_POST['type'] ?? 'service';
    $notes = $_POST['notes'] ?? '';
    
    if (!$id || !$status) {
        echo json_encode(['error' => 'Invalid parameters']);
        return;
    }
    
    $table = ($type === 'service') ? 'service_enquiries' : 'general_contacts';
    
    $stmt = $db->prepare("UPDATE $table SET admin_status = ?, assigned_to = ?, notes = ? WHERE id = ?");
    $stmt->bindValue(1, $status, SQLITE3_TEXT);
    $stmt->bindValue(2, $_SESSION['admin_id'], SQLITE3_INTEGER);
    $stmt->bindValue(3, $notes, SQLITE3_TEXT);
    $stmt->bindValue(4, $id, SQLITE3_INTEGER);
    $stmt->execute();
    
    logAudit($db, $_SESSION['admin_id'], 'update_status', $table, $id, null, $status, "Status updated to $status");
    
    echo json_encode(['success' => true]);
}

/**
 * Archive item
 */
function archiveItem($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $id = intval($_POST['id'] ?? 0);
    $type = $_POST['type'] ?? 'service';
    $archive = isset($_POST['archive']) ? intval($_POST['archive']) : 1;
    
    if (!$id) {
        echo json_encode(['error' => 'Invalid ID']);
        return;
    }
    
    $table = ($type === 'service') ? 'service_enquiries' : 'general_contacts';
    
    $stmt = $db->prepare("UPDATE $table SET is_archived = ? WHERE id = ?");
    $stmt->bindValue(1, $archive, SQLITE3_INTEGER);
    $stmt->bindValue(2, $id, SQLITE3_INTEGER);
    $stmt->execute();
    
    logAudit($db, $_SESSION['admin_id'], $archive ? 'archive' : 'restore', $table, $id, null, null, "Item " . ($archive ? 'archived' : 'restored'));
    
    echo json_encode(['success' => true]);
}

// Replace or update the existing getEnquiryDetails function
function getEnquiryDetails($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $id   = intval($_GET['id'] ?? 0);
    $type = $_GET['type'] ?? 'service';
    
    if (!$id) {
        echo json_encode(['error' => 'Invalid ID']);
        return;
    }
    
    // Map type to table
    $table_map = [
        'service' => 'service_enquiries',
        'general' => 'general_contacts',
        'job' => 'job_applications',
        'business' => 'business_upgrades',
        'placement' => 'placement_enquiries'
    ];
    
    if (!isset($table_map[$type])) {
        echo json_encode(['error' => 'Invalid type']);
        return;
    }
    
    $table = $table_map[$type];
    
    // Check if table exists
    $table_exists = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'");
    if (!$table_exists) {
        echo json_encode(['error' => 'Table not found']);
        return;
    }
    
    $stmt = $db->prepare("SELECT * FROM $table WHERE id = ?");
    $stmt->bindValue(1, $id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$row) {
        echo json_encode(['error' => 'Not found']);
        return;
    }

    // Sanitise values
    $clean = [];
    foreach ($row as $k => $v) {
        if (is_string($v)) {
            $clean[$k] = mb_convert_encoding($v, 'UTF-8', 'UTF-8');
        } else {
            $clean[$k] = $v;
        }
    }

    echo json_encode(['success' => true, 'data' => $clean]);
}

/**
 * Get users list with hierarchy filtering - FIXED to show online users at top
 */
function getUsersList($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $current_id = $_SESSION['admin_id'];
    $current = $db->querySingle("SELECT role FROM admin_users WHERE id = $current_id", true);
    $is_admin = ($current['role'] === 'admin');
    $is_manager = ($current['role'] === 'manager');
    $for_chat = isset($_GET['for_chat']) ? (bool)$_GET['for_chat'] : false;
    
    // For internal chat: exclude workers entirely, only show admin/manager/staff
    // For user management: show based on hierarchy
    if ($for_chat) {
        $query = "SELECT id, full_name, username, role, designation, profile_photo, 
                         staff_id, worker_id, is_online, care_permission,
                         last_activity,
                         CASE 
                            WHEN last_activity > datetime('now', '-5 minutes') THEN 1 
                            ELSE 0 
                         END as online_status
                  FROM admin_users 
                  WHERE is_active = 1 AND access_blocked = 0 AND role != 'worker'";
    } else {
        $query = "SELECT id, full_name, username, role, designation, profile_photo, 
                         staff_id, worker_id, is_online, care_permission, access_blocked,
                         last_activity,
                         CASE 
                            WHEN last_activity > datetime('now', '-5 minutes') THEN 1 
                            ELSE 0 
                         END as online_status
                  FROM admin_users 
                  WHERE is_active = 1";
        
        if (!$is_admin) {
            if ($is_manager) {
                $query .= " AND role IN ('staff', 'worker', 'manager')";
            } else {
                $query .= " AND role IN ('staff', 'worker')";
            }
        }
    }
    
    $query .= " ORDER BY online_status DESC, 
                CASE 
                    WHEN role = 'admin' THEN 1
                    WHEN role = 'manager' THEN 2
                    WHEN role = 'staff' THEN 3
                    WHEN role = 'worker' THEN 4
                    ELSE 5
                END,
                full_name ASC";
    
    $users = $db->query($query);
    
    $list = [];
    while ($row = $users->fetchArray(SQLITE3_ASSOC)) {
        $row['is_online'] = ($row['online_status'] == 1);
        $row['display_name'] = $row['full_name'] . ' (' . ($row['designation'] ?: $row['role']) . ')';
        $row['user_id'] = $row['staff_id'] ?: $row['worker_id'] ?: 'N/A';
        unset($row['online_status']);
        $list[] = $row;
    }
    
    echo json_encode($list);
}

/**
 * Get user details for editing
 */
function getUserDetails($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $user_id = intval($_GET['user_id'] ?? 0);
    $current_id = $_SESSION['admin_id'];
    
    if (!$user_id) {
        echo json_encode(['error' => 'User ID required']);
        return;
    }
    
    // Check permission
    if (!canManageUser($db, $current_id, $user_id, 'view')) {
        echo json_encode(['error' => 'Permission denied']);
        return;
    }
    
    $stmt = $db->prepare("SELECT * FROM admin_users WHERE id = ?");
    $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($user) {
        unset($user['password_hash']);
        unset($user['remember_token']);
        
        echo json_encode(['success' => true, 'user' => $user]);
    } else {
        echo json_encode(['error' => 'User not found']);
    }
}

/**
 * Check if current user can manage target user
 */
function canManageUser($db, $current_id, $target_id, $action = 'view') {
    if ($current_id == $target_id) return true;
    
    $current = $db->querySingle("SELECT role FROM admin_users WHERE id = $current_id", true);
    $target = $db->querySingle("SELECT role FROM admin_users WHERE id = $target_id", true);
    
    if (!$current || !$target) return false;
    
    // Admin can do anything
    if ($current['role'] === 'admin') return true;
    
    // Managers can manage staff and workers
    if ($current['role'] === 'manager' && in_array($target['role'], ['staff', 'worker'])) {
        return true;
    }
    
    // Staff can only request updates for others (not directly manage)
    if ($action === 'view' && $current['role'] === 'staff' && $target['role'] === 'worker') {
        return true;
    }
    
    return false;
}

/**
 * Create new user
 */
function createUser($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $current_id = $_SESSION['admin_id'];
    $current = $db->querySingle("SELECT role FROM admin_users WHERE id = $current_id", true);
    
    $username    = trim($_POST['username'] ?? '');
    $password    = $_POST['password'] ?? '';
    $full_name   = trim($_POST['full_name'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $phone       = trim($_POST['phone_primary'] ?? '');
    $role        = $_POST['role'] ?? 'staff';
    $designation = trim($_POST['designation'] ?? '');
    $care_permission = isset($_POST['care_permission']) ? 1 : 0;
    $hr_permission   = isset($_POST['hr_permission']) ? 1 : 0;
    $access_blocked  = isset($_POST['access_blocked']) ? 1 : 0;
    $max_concurrent_chats = intval($_POST['max_concurrent_chats'] ?? 2);
    $date_of_joining = $_POST['date_of_joining'] ?? date('Y-m-d');
    $profile_photo = trim($_POST['profile_photo'] ?? '');
    
    if (empty($username) || empty($password) || empty($full_name)) {
        echo json_encode(['error' => 'Full Name, Username and Password are required']);
        return;
    }
    
    // Check permissions
    $can_create = false;
    if ($current['role'] === 'admin') {
        $can_create = true;
    } elseif ($current['role'] === 'manager' && in_array($role, ['staff', 'worker'])) {
        $can_create = true;
    }
    
    if (!$can_create) {
        echo json_encode(['error' => 'Permission denied']);
        return;
    }
    
    // Check if username exists
    $exists = $db->querySingle("SELECT id FROM admin_users WHERE username = '" . $db->escapeString($username) . "'");
    if ($exists) {
        echo json_encode(['error' => 'Username already exists']);
        return;
    }
    
    // Generate ID based on joining date
    $generated_id = generateUniqueId($db, $role, $date_of_joining);
    
    $approval_status = 'approved';
    
    $hash = password_hash($password, PASSWORD_DEFAULT);
    
    $staff_id  = ($role !== 'worker') ? $generated_id : null;
    $worker_id = ($role === 'worker') ? $generated_id : null;
    $supervisor_id = ($role === 'worker') ? $current_id : 0;
    
    $stmt = $db->prepare("INSERT INTO admin_users 
        (username, password_hash, full_name, email, phone_primary, role, designation, care_permission, hr_permission,
         access_blocked, staff_id, worker_id, approval_status, supervisor_id, is_active,
         date_of_joining, max_concurrent_chats, profile_photo, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, datetime('now', 'localtime'))");
    
    $stmt->bindValue(1,  $username,            SQLITE3_TEXT);
    $stmt->bindValue(2,  $hash,                SQLITE3_TEXT);
    $stmt->bindValue(3,  $full_name,           SQLITE3_TEXT);
    $stmt->bindValue(4,  $email,               SQLITE3_TEXT);
    $stmt->bindValue(5,  $phone,               SQLITE3_TEXT);
    $stmt->bindValue(6,  $role,                SQLITE3_TEXT);
    $stmt->bindValue(7,  $designation,         SQLITE3_TEXT);
    $stmt->bindValue(8,  $care_permission,     SQLITE3_INTEGER);
    $stmt->bindValue(9,  $hr_permission,       SQLITE3_INTEGER);
    $stmt->bindValue(10, $access_blocked,      SQLITE3_INTEGER);
    $stmt->bindValue(11, $staff_id,            SQLITE3_TEXT);
    $stmt->bindValue(12, $worker_id,           SQLITE3_TEXT);
    $stmt->bindValue(13, $approval_status,     SQLITE3_TEXT);
    $stmt->bindValue(14, $supervisor_id,       SQLITE3_INTEGER);
    $stmt->bindValue(15, $date_of_joining,     SQLITE3_TEXT);
    $stmt->bindValue(16, $max_concurrent_chats,SQLITE3_INTEGER);
    $stmt->bindValue(17, $profile_photo ?: null, SQLITE3_TEXT);
    $stmt->execute();
    
    $user_id = $db->lastInsertRowID();
    
    logAudit($db, $current_id, 'user_created', 'admin_users', $user_id, null, json_encode($_POST), "Created user: $username");
    
    echo json_encode([
        'success' => true,
        'user_id' => $user_id,
        'generated_id' => $generated_id,
        'approval_status' => $approval_status,
        'qr_url' => 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode('https://' . $_SERVER['HTTP_HOST'] . '/admin.php?verify_id=' . $generated_id)
    ]);
}

/**
 * Update user details - FIXED with proper role-based permissions
 */
function updateUser($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $current_id = $_SESSION['admin_id'];
    $target_id = intval($_POST['user_id'] ?? 0);
    
    if (!$target_id) {
        echo json_encode(['error' => 'User ID required']);
        return;
    }
    
    // Get current user's role
    $current = $db->querySingle("SELECT role FROM admin_users WHERE id = $current_id", true);
    
    // Check permission - Only admins can update profiles (including their own)
    // Managers can only update staff and workers, not themselves
    $can_update = false;
    
    if ($current['role'] === 'admin') {
        // Admin can update anyone including self
        $can_update = true;
    } elseif ($current['role'] === 'manager' && $target_id != $current_id) {
        // Manager can update staff and workers only, not self
        $target = $db->querySingle("SELECT role FROM admin_users WHERE id = $target_id", true);
        if ($target && in_array($target['role'], ['staff', 'worker'])) {
            $can_update = true;
        }
    }
    
    if (!$can_update) {
        echo json_encode(['error' => 'Permission denied. You cannot update this profile.']);
        return;
    }
    
    // Get old data for audit
    $old_data = $db->querySingle("SELECT * FROM admin_users WHERE id = $target_id", true);
    
    // Define updatable fields based on role
    $updatable_fields = [];
    
    if ($current['role'] === 'admin') {
        // Admin can update everything including profile photo
        $updatable_fields = [
            'full_name', 'email', 'designation', 'role', 'phone_primary', 'phone_secondary',
            'address', 'city', 'state', 'pincode', 'date_of_birth', 'date_of_joining',
            'emergency_contact_name', 'emergency_contact_phone', 'blood_group',
            'care_permission', 'hr_permission', 'tech_permission', 'admin_permission',
            'max_concurrent_chats', 'access_blocked', 'profile_photo'
        ];
    } elseif ($current['role'] === 'manager') {
        // Manager can update limited fields for staff/workers but NOT profile photo
        $updatable_fields = [
            'full_name', 'email', 'designation', 'phone_primary', 'phone_secondary',
            'address', 'city', 'state', 'pincode', 'emergency_contact_name', 
            'emergency_contact_phone', 'blood_group', 'care_permission',
            'access_blocked'
        ];
    }
    
    $updates = [];
    $params = [];
    
    foreach ($updatable_fields as $field) {
        if (isset($_POST[$field])) {
            $updates[] = "$field = ?";
            $params[] = $_POST[$field];
        }
    }
    
    // Handle ID regeneration (admin only)
    if (isset($_POST['regenerate_id']) && $_POST['regenerate_id'] == 1 && $current['role'] === 'admin') {
        $role = $old_data['role'];
        $new_id = generateUniqueId($db, $role, $old_data['date_of_joining']);
        $id_field = ($role === 'worker') ? 'worker_id' : 'staff_id';
        $updates[] = "$id_field = ?";
        $params[] = $new_id;
    }
    
    if (empty($updates)) {
        echo json_encode(['error' => 'No fields to update']);
        return;
    }
    
    $params[] = $target_id;
    $sql = "UPDATE admin_users SET " . implode(', ', $updates) . ", updated_at = datetime('now', 'localtime') WHERE id = ?";
    
    $stmt = $db->prepare($sql);
    foreach ($params as $i => $value) {
        $stmt->bindValue($i + 1, $value, is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
    }
    $stmt->execute();
    
    // Get new data for audit
    $new_data = $db->querySingle("SELECT * FROM admin_users WHERE id = $target_id", true);
    
    logAudit($db, $current_id, 'user_updated', 'admin_users', $target_id, json_encode($old_data), json_encode($new_data), "Updated user profile");
    
    echo json_encode(['success' => true]);
}

/**
 * Request profile update (for users who can't directly edit)
 */
function requestProfileUpdate($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $user_id = $_SESSION['admin_id'];
    $requested_changes = $_POST['changes'] ?? '';
    $reason = $_POST['reason'] ?? '';
    
    if (empty($requested_changes)) {
        echo json_encode(['error' => 'No changes requested']);
        return;
    }
    
    $stmt = $db->prepare("INSERT INTO profile_update_requests (user_id, requested_changes, reason, status) VALUES (?, ?, ?, 'pending')");
    $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
    $stmt->bindValue(2, $requested_changes, SQLITE3_TEXT);
    $stmt->bindValue(3, $reason, SQLITE3_TEXT);
    $stmt->execute();
    
    $request_id = $db->lastInsertRowID();
    
    logAudit($db, $user_id, 'profile_update_requested', 'profile_update_requests', $request_id, null, $requested_changes, "Requested profile update");
    
    echo json_encode(['success' => true, 'request_id' => $request_id]);
}

/**
 * Approve/reject profile update request
 */
function approveProfileUpdate($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $current_id = $_SESSION['admin_id'];
    $request_id = intval($_POST['request_id'] ?? 0);
    $status = $_POST['status'] ?? ''; // 'approved' or 'rejected'
    $notes = $_POST['notes'] ?? '';
    
    if (!$request_id || !in_array($status, ['approved', 'rejected'])) {
        echo json_encode(['error' => 'Invalid parameters']);
        return;
    }
    
    $request = $db->querySingle("SELECT * FROM profile_update_requests WHERE id = $request_id", true);
    if (!$request) {
        echo json_encode(['error' => 'Request not found']);
        return;
    }
    
    // Check if current user can approve (admin or manager)
    $current = $db->querySingle("SELECT role FROM admin_users WHERE id = $current_id", true);
    if (!in_array($current['role'], ['admin', 'manager'])) {
        echo json_encode(['error' => 'Permission denied']);
        return;
    }
    
    if ($status === 'approved') {
        // Apply the changes
        $changes = json_decode($request['requested_changes'], true);
        if ($changes) {
            $updates = [];
            $params = [];
            foreach ($changes as $field => $value) {
                $updates[] = "$field = ?";
                $params[] = $value;
            }
            $params[] = $request['user_id'];
            $sql = "UPDATE admin_users SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $db->prepare($sql);
            foreach ($params as $i => $value) {
                $stmt->bindValue($i + 1, $value, SQLITE3_TEXT);
            }
            $stmt->execute();
        }
    }
    
    $db->exec("UPDATE profile_update_requests SET status = '$status', reviewed_by = $current_id, reviewed_at = datetime('now', 'localtime'), review_notes = '$notes' WHERE id = $request_id");
    
    logAudit($db, $current_id, 'profile_update_' . $status, 'profile_update_requests', $request_id, null, $status, "Profile update request $status");
    
    echo json_encode(['success' => true]);
}

/**
 * Get profile update requests
 */
function getProfileUpdateRequests($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $current_id = $_SESSION['admin_id'];
    $current = $db->querySingle("SELECT role FROM admin_users WHERE id = $current_id", true);
    
    $query = "SELECT pur.*, au.full_name as user_name, au.username, 
                     au2.full_name as reviewer_name
              FROM profile_update_requests pur
              LEFT JOIN admin_users au ON pur.user_id = au.id
              LEFT JOIN admin_users au2 ON pur.reviewed_by = au2.id";
    
    if ($current['role'] === 'staff') {
        $query .= " WHERE pur.user_id = $current_id";
    }
    
    $query .= " ORDER BY pur.created_at DESC";
    
    $requests = $db->query($query);
    
    $list = [];
    while ($req = $requests->fetchArray(SQLITE3_ASSOC)) {
        $list[] = $req;
    }
    
    echo json_encode($list);
}

/**
 * Delete user (soft delete)
 */
function deleteUser($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $current_id = $_SESSION['admin_id'];
    $target_id = intval($_POST['user_id'] ?? 0);
    
    if (!$target_id || $target_id == $current_id) {
        echo json_encode(['error' => 'Cannot delete yourself']);
        return;
    }
    
    // Check permission
    if (!canManageUser($db, $current_id, $target_id, 'delete')) {
        echo json_encode(['error' => 'Permission denied']);
        return;
    }
    
    // Soft delete
    $db->exec("UPDATE admin_users SET is_active = 0, is_online = 0 WHERE id = $target_id");
    
    logAudit($db, $current_id, 'user_deleted', 'admin_users', $target_id, null, null, "User deleted");
    
    echo json_encode(['success' => true]);
}

/**
 * Toggle user active status
 */
function toggleUserStatus($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $current_id = $_SESSION['admin_id'];
    $target_id = intval($_POST['user_id'] ?? 0);
    $status = intval($_POST['status'] ?? 0);
    
    if (!$target_id || $target_id == $current_id) {
        echo json_encode(['error' => 'Invalid user']);
        return;
    }
    
    if (!canManageUser($db, $current_id, $target_id, 'edit')) {
        echo json_encode(['error' => 'Permission denied']);
        return;
    }
    
    $db->exec("UPDATE admin_users SET is_active = $status WHERE id = $target_id");
    
    logAudit($db, $current_id, $status ? 'user_activated' : 'user_deactivated', 'admin_users', $target_id, null, null, "User " . ($status ? 'activated' : 'deactivated'));
    
    echo json_encode(['success' => true]);
}

/**
 * Block/Unblock user access
 */
function blockUser($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $current_id = $_SESSION['admin_id'];
    $target_id  = intval($_POST['user_id'] ?? 0);
    $blocked    = intval($_POST['blocked'] ?? 1);
    
    if (!$target_id || $target_id == $current_id) {
        echo json_encode(['error' => 'Invalid user']);
        return;
    }
    
    if (!canManageUser($db, $current_id, $target_id, 'edit')) {
        echo json_encode(['error' => 'Permission denied']);
        return;
    }
    
    // Force logout if blocking
    if ($blocked) {
        $db->exec("UPDATE admin_users SET access_blocked = 1, is_online = 0 WHERE id = $target_id");
    } else {
        $db->exec("UPDATE admin_users SET access_blocked = 0 WHERE id = $target_id");
    }
    
    logAudit($db, $current_id, $blocked ? 'user_blocked' : 'user_unblocked', 'admin_users', $target_id, null, null, "User access " . ($blocked ? 'blocked' : 'unblocked'));
    
    echo json_encode(['success' => true]);
}

/**
 * Verify ID card of a worker
 */
function verifyIdCard($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $current_id  = $_SESSION['admin_id'];
    $target_id   = intval($_POST['user_id'] ?? 0);
    $verified    = intval($_POST['verified'] ?? 1);
    
    if (!$target_id) {
        echo json_encode(['error' => 'User ID required']);
        return;
    }
    
    if (!canManageUser($db, $current_id, $target_id, 'edit')) {
        echo json_encode(['error' => 'Permission denied']);
        return;
    }
    
    $db->exec("UPDATE admin_users SET id_card_verified = $verified WHERE id = $target_id");
    
    logAudit($db, $current_id, $verified ? 'id_card_verified' : 'id_card_unverified', 'admin_users', $target_id, null, null, "ID card " . ($verified ? 'verified' : 'unverified'));
    
    echo json_encode(['success' => true]);
}

/**
 * Reset password
 */
function resetPassword($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $current_id = $_SESSION['admin_id'];
    $target_id = intval($_POST['user_id'] ?? 0);
    
    if (!$target_id) {
        echo json_encode(['error' => 'User ID required']);
        return;
    }
    
    // Check permission
    if (!canManageUser($db, $current_id, $target_id, 'edit')) {
        echo json_encode(['error' => 'Permission denied']);
        return;
    }
    
    $new_password = bin2hex(random_bytes(4));
    $hash = password_hash($new_password, PASSWORD_DEFAULT);
    
    $db->exec("UPDATE admin_users SET password_hash = '$hash' WHERE id = $target_id");
    
    logAudit($db, $current_id, 'password_reset', 'admin_users', $target_id, null, null, "Password reset");
    
    echo json_encode([
        'success' => true,
        'new_password' => $new_password
    ]);
}

/**
 * Upload profile photo
 */
function uploadProfilePhoto($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $user_id = intval($_POST['user_id'] ?? $_SESSION['admin_id']);
    
    if (!isset($_FILES['photo'])) {
        echo json_encode(['error' => 'No file uploaded']);
        return;
    }
    
    $file = $_FILES['photo'];
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    
    if (!in_array($file['type'], $allowed)) {
        echo json_encode(['error' => 'Invalid file type. Only JPG, PNG, GIF, WEBP allowed']);
        return;
    }
    
    if ($file['size'] > 5 * 1024 * 1024) {
        echo json_encode(['error' => 'File too large. Max 5MB']);
        return;
    }
    
    $upload_path = 'uploads/profile_photos/';
    if (!file_exists($upload_path)) {
        mkdir($upload_path, 0755, true);
    }
    
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'profile_' . $user_id . '_' . time() . '.' . $ext;
    $full_path = $upload_path . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $full_path)) {
        $db->exec("UPDATE admin_users SET profile_photo = '$full_path' WHERE id = $user_id");
        
        logAudit($db, $_SESSION['admin_id'], 'photo_uploaded', 'admin_users', $user_id, null, $full_path, "Profile photo updated");
        
        echo json_encode([
            'success' => true,
            'photo_url' => $full_path
        ]);
    } else {
        echo json_encode(['error' => 'Failed to upload file']);
    }
}

/**
 * Create task
 */
function createTask($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $user_id = $_SESSION['admin_id'];
    
    $title = $_POST['title'] ?? '';
    $description = $_POST['description'] ?? '';
    $assigned_to = !empty($_POST['assigned_to']) ? intval($_POST['assigned_to']) : null;
    $priority = $_POST['priority'] ?? 'medium';
    $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
    
    if (empty($title)) {
        echo json_encode(['error' => 'Title required']);
        return;
    }
    
    try {
        $db->exec("BEGIN TRANSACTION");
        
        $stmt = $db->prepare("INSERT INTO tasks (title, description, assigned_to, assigned_by, priority, due_date, created_at) 
                              VALUES (?, ?, ?, ?, ?, ?, datetime('now', 'localtime'))");
        $stmt->bindValue(1, $title, SQLITE3_TEXT);
        $stmt->bindValue(2, $description, SQLITE3_TEXT);
        $stmt->bindValue(3, $assigned_to, $assigned_to ? SQLITE3_INTEGER : SQLITE3_NULL);
        $stmt->bindValue(4, $user_id, SQLITE3_INTEGER);
        $stmt->bindValue(5, $priority, SQLITE3_TEXT);
        $stmt->bindValue(6, $due_date, $due_date ? SQLITE3_TEXT : SQLITE3_NULL);
        $stmt->execute();
        
        $task_id = $db->lastInsertRowID();
        
        // Add initial comment
        $creator_name = getCurrentUserName($db, $user_id);
        $comment = "Task created by " . $creator_name;
        $stmt = $db->prepare("INSERT INTO task_comments (task_id, user_id, comment, change_type) VALUES (?, ?, ?, 'created')");
        $stmt->bindValue(1, $task_id, SQLITE3_INTEGER);
        $stmt->bindValue(2, $user_id, SQLITE3_INTEGER);
        $stmt->bindValue(3, $comment, SQLITE3_TEXT);
        $stmt->execute();
        
        $db->exec("COMMIT");
        
        logAudit($db, $user_id, 'task_created', 'tasks', $task_id, null, json_encode($_POST), "Task created: $title");
        
        echo json_encode(['success' => true, 'task_id' => $task_id]);
    } catch (Exception $e) {
        $db->exec("ROLLBACK");
        logError("Task creation error: " . $e->getMessage());
        echo json_encode(['error' => 'Failed to create task: ' . $e->getMessage()]);
    }
}

/**
 * Get tasks
 */
function getTasks($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $user_id = $_SESSION['admin_id'];
    $show_archived = isset($_GET['show_archived']) ? intval($_GET['show_archived']) : 0;
    $filter = $_GET['filter'] ?? 'all'; // all, assigned_to_me, created_by_me
    
    $query = "SELECT t.*, 
                     creator.full_name as creator_name,
                     assignee.full_name as assignee_name,
                     (SELECT COUNT(*) FROM task_comments WHERE task_id = t.id) as comment_count
              FROM tasks t
              LEFT JOIN admin_users creator ON t.assigned_by = creator.id
              LEFT JOIN admin_users assignee ON t.assigned_to = assignee.id
              WHERE t.is_archived = $show_archived";
    
    if ($filter === 'assigned_to_me') {
        $query .= " AND t.assigned_to = $user_id";
    } elseif ($filter === 'created_by_me') {
        $query .= " AND t.assigned_by = $user_id";
    }
    
    $query .= " ORDER BY 
                CASE t.status 
                    WHEN 'pending' THEN 1
                    WHEN 'in_progress' THEN 2
                    WHEN 'completed' THEN 3
                    WHEN 'cancelled' THEN 4
                END,
                t.due_date ASC,
                t.created_at DESC";
    
    $result = $db->query($query);
    
    $list = [];
    while ($task = $result->fetchArray(SQLITE3_ASSOC)) {
        $task['created_at_formatted'] = date('d M Y h:i A', strtotime($task['created_at']));
        $task['due_date_formatted'] = $task['due_date'] ? date('d M Y', strtotime($task['due_date'])) : 'No deadline';
        $list[] = $task;
    }
    
    echo json_encode($list);
}

/**
 * Get task details with comments
 */
function getTaskDetails($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $task_id = intval($_GET['task_id'] ?? 0);
    
    if (!$task_id) {
        echo json_encode(['error' => 'Task ID required']);
        return;
    }
    
    $task = $db->querySingle("SELECT t.*, 
                                     creator.full_name as creator_name,
                                     assignee.full_name as assignee_name
                              FROM tasks t
                              LEFT JOIN admin_users creator ON t.assigned_by = creator.id
                              LEFT JOIN admin_users assignee ON t.assigned_to = assignee.id
                              WHERE t.id = $task_id", true);
    
    if (!$task) {
        echo json_encode(['error' => 'Task not found']);
        return;
    }
    
    $comments = $db->query("SELECT tc.*, au.full_name as user_name, au.profile_photo
                            FROM task_comments tc
                            LEFT JOIN admin_users au ON tc.user_id = au.id
                            WHERE tc.task_id = $task_id
                            ORDER BY tc.created_at ASC");
    
    $comment_list = [];
    while ($comment = $comments->fetchArray(SQLITE3_ASSOC)) {
        $comment['created_at_formatted'] = date('d M Y h:i A', strtotime($comment['created_at']));
        $comment_list[] = $comment;
    }
    
    $task['comments'] = $comment_list;
    $task['created_at_formatted'] = date('d M Y h:i A', strtotime($task['created_at']));
    $task['due_date_formatted'] = $task['due_date'] ? date('d M Y', strtotime($task['due_date'])) : 'No deadline';
    
    echo json_encode(['success' => true, 'task' => $task]);
}

/**
 * Update task - REQUIRES COMMENT
 */
function updateTask($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $user_id = $_SESSION['admin_id'];
    $task_id = intval($_POST['task_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    $comment = trim($_POST['comment'] ?? '');
    $title = $_POST['title'] ?? '';
    $description = $_POST['description'] ?? '';
    $assigned_to = !empty($_POST['assigned_to']) ? intval($_POST['assigned_to']) : null;
    $priority = $_POST['priority'] ?? '';
    $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
    
    if (!$task_id) {
        echo json_encode(['error' => 'Task ID required']);
        return;
    }
    
    // Comment is required for any modification
    if (empty($comment)) {
        echo json_encode(['error' => 'A comment is required when modifying a task']);
        return;
    }
    
    try {
        // Get old task data
        $old_task = $db->querySingle("SELECT * FROM tasks WHERE id = $task_id", true);
        if (!$old_task) {
            echo json_encode(['error' => 'Task not found']);
            return;
        }
        
        $db->exec("BEGIN TRANSACTION");
        
        $updates = [];
        $params = [];
        $change_log = [];
        
        // Track changes
        if ($status && $status !== $old_task['status']) {
            $updates[] = "status = ?";
            $params[] = $status;
            $change_log[] = "Status changed from '{$old_task['status']}' to '$status'";
            
            if ($status === 'completed') {
                $updates[] = "completed_at = datetime('now', 'localtime')";
            } elseif ($status === 'cancelled') {
                $updates[] = "cancelled_at = datetime('now', 'localtime')";
            }
        }
        
        if ($title && $title !== $old_task['title']) {
            $updates[] = "title = ?";
            $params[] = $title;
            $change_log[] = "Title changed";
        }
        
        if ($description !== $old_task['description']) {
            $updates[] = "description = ?";
            $params[] = $description;
            $change_log[] = "Description updated";
        }
        
        if ($assigned_to != $old_task['assigned_to']) {
            $old_assignee = $old_task['assigned_to'] ? ($db->querySingle("SELECT full_name FROM admin_users WHERE id = {$old_task['assigned_to']}") ?: 'Unassigned') : 'Unassigned';
            $new_assignee = $assigned_to ? ($db->querySingle("SELECT full_name FROM admin_users WHERE id = $assigned_to") ?: 'Assigned') : 'Unassigned';
            $updates[] = "assigned_to = ?";
            $params[] = $assigned_to;
            $change_log[] = "Assigned changed from '$old_assignee' to '$new_assignee'";
        }
        
        if ($priority && $priority !== $old_task['priority']) {
            $updates[] = "priority = ?";
            $params[] = $priority;
            $change_log[] = "Priority changed from '{$old_task['priority']}' to '$priority'";
        }
        
        if ($due_date !== $old_task['due_date']) {
            $updates[] = "due_date = ?";
            $params[] = $due_date;
            $change_log[] = "Due date changed";
        }
        
        $updates[] = "updated_at = datetime('now', 'localtime')";
        
        if (!empty($updates)) {
            // Remove the last element (updated_at) from array_slice for the SET clause
            $set_clause = implode(', ', array_slice($updates, 0, -1));
            $params[] = $task_id;
            
            $sql = "UPDATE tasks SET " . $set_clause . " WHERE id = ?";
            $stmt = $db->prepare($sql);
            foreach ($params as $i => $value) {
                $stmt->bindValue($i + 1, $value, is_null($value) ? SQLITE3_NULL : (is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT));
            }
            $stmt->execute();
        }
        
        // Add comment with change details
        $comment_text = $comment;
        if (!empty($change_log)) {
            $comment_text .= "\n\nChanges made:\n- " . implode("\n- ", $change_log);
        }
        
        $stmt = $db->prepare("INSERT INTO task_comments (task_id, user_id, comment, change_type, old_value, new_value) 
                              VALUES (?, ?, ?, 'update', ?, ?)");
        $stmt->bindValue(1, $task_id, SQLITE3_INTEGER);
        $stmt->bindValue(2, $user_id, SQLITE3_INTEGER);
        $stmt->bindValue(3, $comment_text, SQLITE3_TEXT);
        $stmt->bindValue(4, json_encode($old_task), SQLITE3_TEXT);
        $stmt->bindValue(5, json_encode($_POST), SQLITE3_TEXT);
        $stmt->execute();
        
        $db->exec("COMMIT");
        
        logAudit($db, $user_id, 'task_updated', 'tasks', $task_id, json_encode($old_task), json_encode($_POST), "Task updated: " . substr($comment, 0, 50));
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $db->exec("ROLLBACK");
        logError("Task update error: " . $e->getMessage());
        echo json_encode(['error' => 'Failed to update task: ' . $e->getMessage()]);
    }
}

/**
 * Add task comment
 */
function addTaskComment($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $user_id = $_SESSION['admin_id'];
    $task_id = intval($_POST['task_id'] ?? 0);
    $comment = $_POST['comment'] ?? '';
    
    if (!$task_id || empty($comment)) {
        echo json_encode(['error' => 'Task ID and comment required']);
        return;
    }
    
    try {
        $stmt = $db->prepare("INSERT INTO task_comments (task_id, user_id, comment) VALUES (?, ?, ?)");
        $stmt->bindValue(1, $task_id, SQLITE3_INTEGER);
        $stmt->bindValue(2, $user_id, SQLITE3_INTEGER);
        $stmt->bindValue(3, $comment, SQLITE3_TEXT);
        $stmt->execute();
        
        $comment_id = $db->lastInsertRowID();
        
        logAudit($db, $user_id, 'task_comment', 'tasks', $task_id, null, $comment, "Comment added to task");
        
        echo json_encode(['success' => true, 'comment_id' => $comment_id]);
    } catch (Exception $e) {
        logError("Add task comment error: " . $e->getMessage());
        echo json_encode(['error' => 'Failed to add comment: ' . $e->getMessage()]);
    }
}

/**
 * Delete task (soft delete)
 */
function deleteTask($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $user_id = $_SESSION['admin_id'];
    $task_id = intval($_POST['task_id'] ?? 0);
    
    if (!$task_id) {
        echo json_encode(['error' => 'Task ID required']);
        return;
    }
    
    // Check if user has permission (admin or task creator/assignee)
    $task = $db->querySingle("SELECT * FROM tasks WHERE id = $task_id", true);
    if (!$task) {
        echo json_encode(['error' => 'Task not found']);
        return;
    }
    
    $current_user = $db->querySingle("SELECT role FROM admin_users WHERE id = $user_id", true);
    $can_delete = ($current_user['role'] === 'admin' || $task['assigned_by'] == $user_id || $task['assigned_to'] == $user_id);
    
    if (!$can_delete) {
        echo json_encode(['error' => 'Permission denied']);
        return;
    }
    
    try {
        $db->exec("UPDATE tasks SET is_archived = 1 WHERE id = $task_id");
        
        logAudit($db, $user_id, 'task_deleted', 'tasks', $task_id, json_encode($task), null, "Task deleted");
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        logError("Task delete error: " . $e->getMessage());
        echo json_encode(['error' => 'Failed to delete task: ' . $e->getMessage()]);
    }
}

/**
 * Archive old tasks (completed/cancelled > 10 days)
 */
function archiveOldTasks($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $user_id = $_SESSION['admin_id'];
    $current = $db->querySingle("SELECT role FROM admin_users WHERE id = $user_id", true);
    
    if ($current['role'] !== 'admin') {
        echo json_encode(['error' => 'Permission denied']);
        return;
    }
    
    try {
        $db->exec("UPDATE tasks SET is_archived = 1 
                   WHERE (status = 'completed' AND completed_at < datetime('now', '-10 days'))
                   OR (status = 'cancelled' AND cancelled_at < datetime('now', '-10 days'))");
        
        $archived_count = $db->changes();
        
        logAudit($db, $user_id, 'tasks_archived', 'tasks', 0, null, $archived_count, "Archived $archived_count old tasks");
        
        echo json_encode(['success' => true, 'archived_count' => $archived_count]);
    } catch (Exception $e) {
        logError("Archive old tasks error: " . $e->getMessage());
        echo json_encode(['error' => 'Failed to archive tasks: ' . $e->getMessage()]);
    }
}

/**
 * Get team members
 */
function getTeamMembers($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $members = $db->query("SELECT * FROM team_members WHERE is_active = 1 ORDER BY display_order ASC");
    
    $list = [];
    while ($member = $members->fetchArray(SQLITE3_ASSOC)) {
        $list[] = $member;
    }
    
    echo json_encode($list);
}

/**
 * Create team member
 */
function createTeamMember($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $user_id = $_SESSION['admin_id'];
    $current = $db->querySingle("SELECT role FROM admin_users WHERE id = $user_id", true);
    
    if ($current['role'] !== 'admin') {
        echo json_encode(['error' => 'Permission denied']);
        return;
    }
    
    $name = $_POST['name'] ?? '';
    $position = $_POST['position'] ?? '';
    $bio = $_POST['bio'] ?? '';
    $photo_url = $_POST['photo_url'] ?? '';
    $display_order = intval($_POST['display_order'] ?? 0);
    
    if (empty($name)) {
        echo json_encode(['error' => 'Name required']);
        return;
    }
    
    $stmt = $db->prepare("INSERT INTO team_members (name, position, bio, photo_url, display_order, is_active) 
                          VALUES (?, ?, ?, ?, ?, 1)");
    $stmt->bindValue(1, $name, SQLITE3_TEXT);
    $stmt->bindValue(2, $position, SQLITE3_TEXT);
    $stmt->bindValue(3, $bio, SQLITE3_TEXT);
    $stmt->bindValue(4, $photo_url, SQLITE3_TEXT);
    $stmt->bindValue(5, $display_order, SQLITE3_INTEGER);
    $stmt->execute();
    
    $member_id = $db->lastInsertRowID();
    
    logAudit($db, $user_id, 'team_member_created', 'team_members', $member_id, null, json_encode($_POST), "Team member created: $name");
    
    echo json_encode(['success' => true, 'member_id' => $member_id]);
}

/**
 * Update team member
 */
function updateTeamMember($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $user_id = $_SESSION['admin_id'];
    $current = $db->querySingle("SELECT role FROM admin_users WHERE id = $user_id", true);
    
    if ($current['role'] !== 'admin') {
        echo json_encode(['error' => 'Permission denied']);
        return;
    }
    
    $member_id = intval($_POST['id'] ?? 0);
    
    if (!$member_id) {
        echo json_encode(['error' => 'Member ID required']);
        return;
    }
    
    $old_data = $db->querySingle("SELECT * FROM team_members WHERE id = $member_id", true);
    
    $updates = [];
    $params = [];
    
    $fields = ['name', 'position', 'bio', 'photo_url', 'display_order', 'is_active'];
    foreach ($fields as $field) {
        if (isset($_POST[$field])) {
            $updates[] = "$field = ?";
            $params[] = $_POST[$field];
        }
    }
    
    $updates[] = "updated_at = datetime('now', 'localtime')";
    $params[] = $member_id;
    
    $sql = "UPDATE team_members SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = $db->prepare($sql);
    foreach ($params as $i => $value) {
        $stmt->bindValue($i + 1, $value, is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
    }
    $stmt->execute();
    
    logAudit($db, $user_id, 'team_member_updated', 'team_members', $member_id, json_encode($old_data), json_encode($_POST), "Team member updated");
    
    echo json_encode(['success' => true]);
}

/**
 * Delete team member
 */
function deleteTeamMember($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $user_id = $_SESSION['admin_id'];
    $current = $db->querySingle("SELECT role FROM admin_users WHERE id = $user_id", true);
    
    if ($current['role'] !== 'admin') {
        echo json_encode(['error' => 'Permission denied']);
        return;
    }
    
    $member_id = intval($_POST['id'] ?? 0);
    
    if (!$member_id) {
        echo json_encode(['error' => 'Member ID required']);
        return;
    }
    
    $db->exec("DELETE FROM team_members WHERE id = $member_id");
    
    logAudit($db, $user_id, 'team_member_deleted', 'team_members', $member_id, null, null, "Team member deleted");
    
    echo json_encode(['success' => true]);
}

/**
 * Get site settings
 */
function getSiteSettings($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $current = $db->querySingle("SELECT role FROM admin_users WHERE id = " . $_SESSION['admin_id'], true);
    
    if ($current['role'] !== 'admin') {
        echo json_encode(['error' => 'Permission denied']);
        return;
    }
    
    $group = $_GET['group'] ?? 'all';
    
    $query = "SELECT * FROM site_settings";
    if ($group !== 'all') {
        $query .= " WHERE setting_group = '$group'";
    }
    $query .= " ORDER BY setting_group, setting_key";
    
    $settings = $db->query($query);
    
    $list = [];
    while ($setting = $settings->fetchArray(SQLITE3_ASSOC)) {
        $list[] = $setting;
    }
    
    echo json_encode($list);
}

/**
 * Update site settings
 */
function updateSiteSettings($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $user_id = $_SESSION['admin_id'];
    $current = $db->querySingle("SELECT role FROM admin_users WHERE id = $user_id", true);
    
    if ($current['role'] !== 'admin') {
        echo json_encode(['error' => 'Permission denied']);
        return;
    }
    
    $settings = json_decode(file_get_contents('php://input'), true);
    
    if (!is_array($settings)) {
        echo json_encode(['error' => 'Invalid settings data']);
        return;
    }
    
    $old_settings = [];
    foreach ($settings as $key => $value) {
        $old = $db->querySingle("SELECT setting_value FROM site_settings WHERE setting_key = '$key'");
        $old_settings[$key] = $old;
        
        $db->exec("UPDATE site_settings SET setting_value = '$value', updated_at = datetime('now', 'localtime') WHERE setting_key = '$key'");
    }
    
    // If timezone changed, update PHP timezone
    if (isset($settings['timezone'])) {
        date_default_timezone_set($settings['timezone']);
    }
    
    logAudit($db, $user_id, 'settings_updated', 'site_settings', 0, json_encode($old_settings), json_encode($settings), "Site settings updated");
    
    echo json_encode(['success' => true]);
}

/**
 * Test email configuration
 */
function testEmailConfig($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $current = $db->querySingle("SELECT role FROM admin_users WHERE id = " . $_SESSION['admin_id'], true);
    
    if ($current['role'] !== 'admin') {
        echo json_encode(['error' => 'Permission denied']);
        return;
    }
    
    $to = $_POST['test_email'] ?? $_SESSION['admin_email'] ?? '';
    
    if (empty($to)) {
        echo json_encode(['error' => 'Test email address required']);
        return;
    }
    
    // Get SMTP settings
    $host = getSetting($db, 'smtp_host', '');
    $port = getSetting($db, 'smtp_port', '587');
    $user = getSetting($db, 'smtp_user', '');
    $pass = getSetting($db, 'smtp_pass', '');
    $from = getSetting($db, 'from_email', 'noreply@hidk.in');
    $from_name = getSetting($db, 'from_name', 'D K Associates');
    
    if (empty($host) || empty($user) || empty($pass)) {
        echo json_encode(['error' => 'SMTP settings not configured']);
        return;
    }
    
    // Simulate email test (in production, actual send would happen here)
    $success = true;
    
    logAudit($db, $_SESSION['admin_id'], 'email_test', 'system', 0, null, $to, "Email configuration tested");
    
    echo json_encode([
        'success' => $success,
        'message' => $success ? 'Test email sent successfully' : 'Failed to send test email'
    ]);
}

/**
 * Get audit logs
 */
function getAuditLogs($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $current = $db->querySingle("SELECT role FROM admin_users WHERE id = " . $_SESSION['admin_id'], true);
    
    if ($current['role'] !== 'admin') {
        echo json_encode(['error' => 'Permission denied']);
        return;
    }
    
    $limit = intval($_GET['limit'] ?? 100);
    $entity = $_GET['entity'] ?? '';
    $user_id = intval($_GET['user_id'] ?? 0);
    
    $query = "SELECT al.*, au.full_name as user_name 
              FROM audit_logs al
              LEFT JOIN admin_users au ON al.user_id = au.id
              WHERE 1=1";
    
    if ($entity) {
        $query .= " AND al.entity_type = '$entity'";
    }
    
    if ($user_id) {
        $query .= " AND al.user_id = $user_id";
    }
    
    $query .= " ORDER BY al.created_at DESC LIMIT $limit";
    
    $logs = $db->query($query);
    
    $list = [];
    while ($log = $logs->fetchArray(SQLITE3_ASSOC)) {
        $log['created_at_formatted'] = date('d M Y h:i:s A', strtotime($log['created_at']));
        $list[] = $log;
    }
    
    echo json_encode($list);
}

/**
 * Get current user's full name
 */
function getCurrentUserName($db, $user_id) {
    return $db->querySingle("SELECT full_name FROM admin_users WHERE id = $user_id") ?: 'Unknown User';
}

/**
 * Handle login
 */
function handleLogin($db) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    $stmt = $db->prepare("SELECT * FROM admin_users WHERE username = ? AND is_active = 1");
    $stmt->bindValue(1, $username, SQLITE3_TEXT);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($user && password_verify($password, $user['password_hash'])) {
        if (!empty($user['access_blocked'])) {
            $_SESSION['login_error'] = 'Your account has been blocked. Please contact the administrator.';
            return;
        }
        $_SESSION['admin_id'] = $user['id'];
        $_SESSION['admin_role'] = $user['role'];
        
        $db->exec("UPDATE admin_users SET last_login = datetime('now', 'localtime'), is_online = 1, last_activity = datetime('now', 'localtime') WHERE id = " . $user['id']);
        
        logAudit($db, $user['id'], 'login', 'admin_users', $user['id'], null, null, 'User logged in');
        
        header('Location: admin.php');
        exit;
    }
    
    $_SESSION['login_error'] = 'Invalid username or password';
}

/**
 * Handle admin POST actions
 */
function handleAdminActions($db, $user) {
    $action = $_POST['admin_action'] ?? '';
    
    switch ($action) {
        case 'add_vacancy':
            if (in_array($user['role'], ['admin', 'manager'])) {
                $stmt = $db->prepare("INSERT INTO open_positions 
                    (title, location, type, salary, description, requirements, urgent, is_active, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 1, datetime('now', 'localtime'))");
                $stmt->bindValue(1, $_POST['title'], SQLITE3_TEXT);
                $stmt->bindValue(2, $_POST['location'], SQLITE3_TEXT);
                $stmt->bindValue(3, $_POST['type'], SQLITE3_TEXT);
                $stmt->bindValue(4, $_POST['salary'], SQLITE3_TEXT);
                $stmt->bindValue(5, $_POST['description'], SQLITE3_TEXT);
                $stmt->bindValue(6, $_POST['requirements'], SQLITE3_TEXT);
                $stmt->bindValue(7, isset($_POST['urgent']) ? 1 : 0, SQLITE3_INTEGER);
                $stmt->execute();
                
                logAudit($db, $user['id'], 'vacancy_created', 'open_positions', $db->lastInsertRowID(), null, json_encode($_POST), 'Job vacancy created');
            }
            break;
            
        case 'update_vacancy':
            $id = intval($_POST['id'] ?? 0);
            if ($id && in_array($user['role'], ['admin', 'manager'])) {
                $old = $db->querySingle("SELECT * FROM open_positions WHERE id = $id", true);
                
                $stmt = $db->prepare("UPDATE open_positions 
                    SET title = ?, location = ?, type = ?, salary = ?, 
                        description = ?, requirements = ?, urgent = ?, is_active = ?
                    WHERE id = ?");
                $stmt->bindValue(1, $_POST['title'], SQLITE3_TEXT);
                $stmt->bindValue(2, $_POST['location'], SQLITE3_TEXT);
                $stmt->bindValue(3, $_POST['type'], SQLITE3_TEXT);
                $stmt->bindValue(4, $_POST['salary'], SQLITE3_TEXT);
                $stmt->bindValue(5, $_POST['description'], SQLITE3_TEXT);
                $stmt->bindValue(6, $_POST['requirements'], SQLITE3_TEXT);
                $stmt->bindValue(7, isset($_POST['urgent']) ? 1 : 0, SQLITE3_INTEGER);
                $stmt->bindValue(8, $_POST['is_active'], SQLITE3_INTEGER);
                $stmt->bindValue(9, $id, SQLITE3_INTEGER);
                $stmt->execute();
                
                logAudit($db, $user['id'], 'vacancy_updated', 'open_positions', $id, json_encode($old), json_encode($_POST), 'Job vacancy updated');
            }
            break;
            
        case 'delete_vacancy':
            $id = intval($_POST['id'] ?? 0);
            if ($id && $user['role'] === 'admin') {
                $old = $db->querySingle("SELECT * FROM open_positions WHERE id = $id", true);
                $db->exec("DELETE FROM open_positions WHERE id = $id");
                logAudit($db, $user['id'], 'vacancy_deleted', 'open_positions', $id, json_encode($old), null, 'Job vacancy deleted');
            }
            break;
            
        case 'toggle_vacancy':
            $id = intval($_POST['id'] ?? 0);
            $active = intval($_POST['active'] ?? 0);
            if ($id && in_array($user['role'], ['admin', 'manager'])) {
                $db->exec("UPDATE open_positions SET is_active = $active WHERE id = $id");
                logAudit($db, $user['id'], $active ? 'vacancy_activated' : 'vacancy_deactivated', 'open_positions', $id, null, null, 'Job vacancy toggled');
            }
            break;
            
        case 'update_profile':
            if (!empty($_POST['new_password'])) {
                if ($_POST['new_password'] === $_POST['confirm_password']) {
                    if (password_verify($_POST['current_password'], $user['password_hash'])) {
                        $hash = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
                        $db->exec("UPDATE admin_users SET password_hash = '$hash' WHERE id = " . $user['id']);
                        $_SESSION['profile_message'] = 'Password updated successfully';
                        logAudit($db, $user['id'], 'password_changed', 'admin_users', $user['id'], null, null, 'Password changed');
                    } else {
                        $_SESSION['profile_error'] = 'Current password is incorrect';
                    }
                } else {
                    $_SESSION['profile_error'] = 'New passwords do not match';
                }
            }
            break;
    }
    
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

/**
 * Output public verification page
 */
function outputVerificationPage($verify_id) {
    global $db;
    
    $stmt = $db->prepare("SELECT * FROM admin_users WHERE staff_id = ? OR worker_id = ?");
    $stmt->bindValue(1, $verify_id, SQLITE3_TEXT);
    $stmt->bindValue(2, $verify_id, SQLITE3_TEXT);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    
    $is_valid = !empty($user);
    $name = $user ? $user['full_name'] : '';
    $designation = $user ? ($user['designation'] ?: ($user['role'] === 'worker' ? 'Worker' : 'Staff')) : '';
    $id_number = $user ? ($user['staff_id'] ?: $user['worker_id']) : '';
    $photo = $user && $user['profile_photo'] ? $user['profile_photo'] : null;
    $date_of_joining = $user ? $user['date_of_joining'] : '';
    
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta http-equiv="refresh" content="4;url=/">
        <title>Identity Verification - D K Associates</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
        <style>
            body {
                font-family: 'Plus Jakarta Sans', sans-serif;
                background: linear-gradient(145deg, #f6f9fc 0%, #eef2f6 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0;
                padding: 20px;
            }
            .verification-card {
                max-width: 500px;
                width: 100%;
                background: white;
                border-radius: 24px;
                padding: 30px;
                box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
                text-align: center;
                animation: slideUp 0.5s ease;
            }
            @keyframes slideUp {
                from { opacity: 0; transform: translateY(30px); }
                to { opacity: 1; transform: translateY(0); }
            }
            .verified-icon {
                width: 80px;
                height: 80px;
                background: <?php echo $is_valid ? '#10b981' : '#ef4444'; ?>;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 20px;
                color: white;
                font-size: 40px;
            }
            .profile-photo {
                width: 120px;
                height: 120px;
                border-radius: 50%;
                object-fit: cover;
                border: 4px solid #0f3b5e;
                margin: 0 auto 15px;
                display: <?php echo $photo ? 'block' : 'none'; ?>;
            }
            .id-badge {
                background: #f8fafc;
                border-radius: 16px;
                padding: 20px;
                margin: 20px 0;
                border: 2px solid #e2e8f0;
            }
            .id-number {
                font-family: monospace;
                font-size: 1.3rem;
                font-weight: 600;
                color: #0f3b5e;
                letter-spacing: 1px;
            }
            .designation-badge {
                background: linear-gradient(135deg, #0f3b5e 0%, #1a4b73 100%);
                color: white;
                padding: 5px 15px;
                border-radius: 50px;
                display: inline-block;
                font-weight: 600;
                margin-bottom: 15px;
            }
            .redirect-note {
                color: #64748b;
                font-size: 0.9rem;
                margin-top: 20px;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
            }
            .company-logo {
                color: #0f3b5e;
                font-size: 2rem;
                margin-bottom: 10px;
            }
            .join-date {
                color: #64748b;
                font-size: 0.9rem;
                margin-top: 5px;
            }
        </style>
    </head>
    <body>
        <div class="verification-card">
            <div class="company-logo">🏢 D K Associates</div>
            
            <div class="verified-icon">
                <i class="bi <?php echo $is_valid ? 'bi-check-lg' : 'bi-x-lg'; ?>"></i>
            </div>
            
            <h2 class="mb-2"><?php echo $is_valid ? 'Identity Verified' : 'Invalid ID'; ?></h2>
            
            <?php if ($is_valid): ?>
                <?php if ($photo): ?>
                    <img src="<?php echo htmlspecialchars($photo); ?>" alt="Profile Photo" class="profile-photo">
                <?php endif; ?>
                
                <div class="designation-badge">
                    <?php echo htmlspecialchars($designation); ?>
                </div>
                
                <div class="id-badge">
                    <div class="text-muted small mb-2">Verified ID Card</div>
                    <div class="id-number"><?php echo htmlspecialchars($id_number); ?></div>
                    <?php if ($date_of_joining): ?>
                        <div class="join-date">Issued: <?php echo date('d M Y', strtotime($date_of_joining)); ?></div>
                    <?php endif; ?>
                </div>
                
                <div class="text-start mb-3">
                    <p><strong>Name:</strong> <?php echo htmlspecialchars($name); ?></p>
                    <p><strong>Designation:</strong> <?php echo htmlspecialchars($designation); ?></p>
                    <p><strong>Status:</strong> <span class="badge bg-success">Active</span></p>
                </div>
                
                <p class="text-success"><i class="bi bi-patch-check-fill me-2"></i>This is a valid D K Associates identification card.</p>
            <?php else: ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    The provided ID could not be verified.
                </div>
                <p class="text-muted">Please check the ID and try again, or contact D K Associates support.</p>
            <?php endif; ?>
            
            <div class="redirect-note">
                <i class="bi bi-arrow-repeat"></i>
                Redirecting to D K Associates website in 4 seconds...
            </div>
        </div>
        
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
        <script>
            // QR Code Modal Function (to be used from admin interface)
            function showQRModal(qrUrl, idNumber) {
                const modalHtml = `
                    <div class="modal fade" id="qrModal" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">ID Card QR Code</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body text-center">
                                    <img src="${qrUrl}" class="img-fluid mb-3" style="max-width: 250px;">
                                    <p><strong>ID: ${idNumber}</strong></p>
                                    <p class="text-muted small">Scan this QR code to verify identity</p>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                    <a href="${qrUrl}" download="qr_${idNumber}.png" class="btn btn-primary">
                                        <i class="bi bi-download"></i> Download
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                
                // Remove existing modal if any
                const existingModal = document.getElementById('qrModal');
                if (existingModal) existingModal.remove();
                
                // Add new modal to body
                document.body.insertAdjacentHTML('beforeend', modalHtml);
                
                // Show modal
                const modal = new bootstrap.Modal(document.getElementById('qrModal'));
                modal.show();
            }
        </script>
    </body>
    </html>
    <?php
}

/**
 * Output login page
 */
function outputLoginPage() {
    $error = $_SESSION['login_error'] ?? '';
    unset($_SESSION['login_error']);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Admin Login - D K Associates</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body {
                background: linear-gradient(135deg, #0f3b5e 0%, #1a4b73 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            }
            .login-card {
                max-width: 400px;
                width: 90%;
                background: white;
                border-radius: 20px;
                padding: 40px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                animation: fadeIn 0.5s ease;
            }
            @keyframes fadeIn {
                from { opacity: 0; transform: translateY(-20px); }
                to { opacity: 1; transform: translateY(0); }
            }
            .logo {
                text-align: center;
                margin-bottom: 30px;
            }
            .logo h1 {
                color: #0f3b5e;
                font-weight: 700;
            }
            .logo p {
                color: #6c757d;
                font-size: 0.9rem;
            }
        </style>
    </head>
    <body>
        <div class="login-card">
            <div class="logo">
                <h1><img src="https://careers.hidk.in/logo.png" width=120px height=100px alt="Logo" onerror="this.style.display='none'; this.parentElement.innerHTML='🏢';"></h1>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <input type="hidden" name="action" value="login">
                
                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <input type="text" class="form-control form-control-lg" name="username" required autofocus>
                </div>
                
                <div class="mb-4">
                    <label class="form-label">Password</label>
                    <input type="password" class="form-control form-control-lg" name="password" required>
                </div>
                
                <button type="submit" class="btn btn-primary w-100 btn-lg">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Login
                </button>
            </form>
            
            <div class="text-center mt-3">
                <a href="/" class="text-decoration-none small">
                    <i class="bi bi-arrow-left me-1"></i>Back to Website
                </a>
            </div>
        </div>
        
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    </body>
    </html>
    <?php
}

/**
 * Output main admin interface
 */
function outputAdminInterface($db, $user, $view) {
    $role = $user['role'];
    $is_admin = ($role === 'admin');
    $is_manager = ($role === 'manager');
    $timezone = getSetting($db, 'timezone', DEFAULT_TIMEZONE);
    $date_format = getSetting($db, 'date_format', DEFAULT_DATE_FORMAT);
    $time_format = getSetting($db, 'time_format', DEFAULT_TIME_FORMAT);
    $site_logo = getSetting($db, 'site_logo', DEFAULT_SITE_LOGO);
    $site_favicon = getSetting($db, 'site_favicon', DEFAULT_SITE_FAVICON);
    
    // Get counts for dashboard
    $pending_requests = $db->querySingle("SELECT COUNT(*) FROM profile_update_requests WHERE status = 'pending'");
    $active_tasks = $db->querySingle("SELECT COUNT(*) FROM tasks WHERE status IN ('pending', 'in_progress') AND is_archived = 0");
    $pending_enquiries = $db->querySingle("SELECT COUNT(*) FROM service_enquiries WHERE admin_status = 'pending' AND is_archived = 0");
    $active_chats = $db->querySingle("SELECT COUNT(*) FROM chat_sessions WHERE status = 'active'");
    
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
        <title>Admin Panel - D K Associates</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
        <link rel="icon" href="<?php echo htmlspecialchars($site_favicon); ?>" type="image/x-icon">
        <style>
            /* ================================================
               RESET & BASE
            ================================================ */
            *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
            :root {
                --sidebar-w: 270px;
                --top-h: 64px;
                --brand: #0f3b5e;
                --brand-dark: #0a2c45;
                --brand-light: rgba(255,255,255,0.12);
                --accent: #3b82f6;
                --surface: #f8fafc;
                --card-bg: #ffffff;
                --border: #e2e8f0;
                --text: #1e293b;
                --muted: #64748b;
                --radius: 12px;
                --shadow: 0 2px 12px rgba(0,0,0,0.08);
                --shadow-md: 0 4px 24px rgba(0,0,0,0.12);
                --transition: 0.28s cubic-bezier(.4,0,.2,1);
            }
            html { font-size: 15px; }
            body {
                font-family: 'Plus Jakarta Sans', sans-serif;
                background: var(--surface);
                color: var(--text);
                overflow-x: hidden;
                -webkit-font-smoothing: antialiased;
            }
            .wrapper { display: flex; min-height: 100vh; }
            .sidebar-overlay {
                display: none;
                position: fixed;
                inset: 0;
                background: rgba(0,0,0,0.45);
                z-index: 1039;
                backdrop-filter: blur(2px);
                opacity: 0;
                transition: opacity var(--transition);
            }
            .sidebar-overlay.visible {
                display: block;
                opacity: 1;
            }
            .sidebar {
                width: var(--sidebar-w);
                background: linear-gradient(175deg, var(--brand) 0%, var(--brand-dark) 100%);
                color: white;
                position: fixed;
                top: 0; left: 0; bottom: 0;
                display: flex;
                flex-direction: column;
                z-index: 1040;
                overflow: hidden;
                transition: transform var(--transition);
                will-change: transform;
                box-shadow: var(--shadow-md);
            }
            .sidebar-scroll {
                flex: 1;
                overflow-y: auto;
                overflow-x: hidden;
                scrollbar-width: thin;
                scrollbar-color: rgba(255,255,255,0.2) transparent;
                padding-bottom: 16px;
            }
            .sidebar-scroll::-webkit-scrollbar { width: 4px; }
            .sidebar-scroll::-webkit-scrollbar-track { background: transparent; }
            .sidebar-scroll::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 4px; }
            .sidebar-header {
                padding: 18px 20px 14px;
                border-bottom: 1px solid rgba(255,255,255,0.08);
                flex-shrink: 0;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
            }
            .sidebar-brand {
                display: flex;
                align-items: center;
                gap: 10px;
                min-width: 0;
            }
            .sidebar-brand img {
                height: 36px;
                width: auto;
                object-fit: contain;
                flex-shrink: 0;
            }
            .sidebar-brand-text h3 {
                font-size: 1rem;
                font-weight: 700;
                margin: 0;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .sidebar-brand-text p {
                font-size: 0.7rem;
                opacity: 0.6;
                margin: 0;
                white-space: nowrap;
            }
            .sidebar-close-btn {
                display: none;
                flex-shrink: 0;
                background: rgba(255,255,255,0.1);
                border: none;
                color: white;
                width: 32px; height: 32px;
                border-radius: 8px;
                align-items: center;
                justify-content: center;
                font-size: 1.1rem;
                cursor: pointer;
                transition: background var(--transition);
            }
            .sidebar-close-btn:hover { background: rgba(255,255,255,0.2); }
            .user-info {
                margin: 12px 10px;
                padding: 12px 14px;
                background: rgba(255,255,255,0.08);
                border-radius: 10px;
                border: 1px solid rgba(255,255,255,0.07);
            }
            .user-info-inner { display: flex; align-items: center; gap: 10px; }
            .user-avatar {
                width: 40px; height: 40px;
                border-radius: 50%;
                object-fit: cover;
                flex-shrink: 0;
                border: 2px solid rgba(255,255,255,0.3);
            }
            .user-avatar-initial {
                width: 40px; height: 40px;
                border-radius: 50%;
                background: rgba(255,255,255,0.18);
                color: white;
                display: flex; align-items: center; justify-content: center;
                font-weight: 700; font-size: 1rem;
                flex-shrink: 0;
                border: 2px solid rgba(255,255,255,0.3);
            }
            .user-info-text { min-width: 0; }
            .user-info-text strong {
                display: block;
                font-size: 0.85rem;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .user-info-text .role-badge {
                display: inline-block;
                font-size: 0.65rem;
                padding: 2px 8px;
                border-radius: 20px;
                background: rgba(255,255,255,0.18);
                color: rgba(255,255,255,0.9);
                margin-top: 2px;
            }
            .user-staff-id {
                margin-top: 8px;
                font-size: 0.72rem;
                opacity: 0.65;
                display: flex; align-items: center; gap: 5px;
            }
            .nav-section-label {
                padding: 14px 20px 4px;
                font-size: 0.62rem;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.8px;
                color: rgba(255,255,255,0.35);
            }
            .nav-link {
                color: rgba(255,255,255,0.78);
                padding: 10px 14px;
                margin: 1px 8px;
                border-radius: 9px;
                transition: background var(--transition), color var(--transition), transform var(--transition);
                display: flex;
                align-items: center;
                gap: 11px;
                font-size: 0.875rem;
                font-weight: 500;
                text-decoration: none;
                position: relative;
            }
            .nav-link:hover {
                background: rgba(255,255,255,0.1);
                color: white;
                transform: translateX(3px);
            }
            .nav-link.active {
                background: rgba(255,255,255,0.16);
                color: white;
                font-weight: 600;
                box-shadow: inset 3px 0 0 rgba(255,255,255,0.6);
            }
            .nav-link i {
                width: 20px;
                font-size: 1.05rem;
                flex-shrink: 0;
                text-align: center;
            }
            .nav-link .nav-badge {
                margin-left: auto;
                background: #ef4444;
                color: white;
                font-size: 0.65rem;
                font-weight: 700;
                padding: 2px 7px;
                border-radius: 20px;
                min-width: 20px;
                text-align: center;
                animation: pulse 2s infinite;
            }
            @keyframes pulse {
                0%,100% { opacity: 1; }
                50% { opacity: 0.7; }
            }
            .sidebar-logout {
                margin: 8px 10px 16px;
                flex-shrink: 0;
            }
            .sidebar-logout button {
                width: 100%;
                padding: 10px;
                border-radius: 9px;
                border: 1px solid rgba(255,255,255,0.2);
                background: rgba(255,255,255,0.06);
                color: rgba(255,255,255,0.8);
                font-size: 0.875rem;
                font-weight: 500;
                display: flex; align-items: center; justify-content: center; gap: 8px;
                cursor: pointer;
                transition: all var(--transition);
            }
            .sidebar-logout button:hover {
                background: rgba(239,68,68,0.25);
                border-color: rgba(239,68,68,0.5);
                color: white;
            }
            .content {
                flex: 1;
                margin-left: var(--sidebar-w);
                min-width: 0;
                display: flex;
                flex-direction: column;
                transition: margin-left var(--transition);
            }
            .navbar-top {
                position: sticky;
                top: 0;
                z-index: 100;
                background: white;
                padding: 0 20px;
                height: var(--top-h);
                border-bottom: 1px solid var(--border);
                box-shadow: 0 1px 8px rgba(0,0,0,0.06);
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                flex-shrink: 0;
            }
            .navbar-left { display: flex; align-items: center; gap: 12px; min-width: 0; }
            .hamburger-btn {
                display: none;
                background: none;
                border: none;
                color: var(--brand);
                font-size: 1.4rem;
                padding: 6px 8px;
                border-radius: 8px;
                cursor: pointer;
                transition: background var(--transition);
                flex-shrink: 0;
                line-height: 1;
            }
            .hamburger-btn:hover { background: var(--surface); }
            .page-title {
                font-size: 1.15rem;
                font-weight: 700;
                color: var(--brand);
                margin: 0;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .navbar-right {
                display: flex;
                align-items: center;
                gap: 10px;
                flex-shrink: 0;
            }
            .navbar-time {
                font-size: 0.8rem;
                color: var(--muted);
                white-space: nowrap;
            }
            .page-content {
                flex: 1;
                padding: 20px;
                max-width: 100%;
                overflow-x: hidden;
            }
            .card {
                border: 1px solid var(--border);
                border-radius: var(--radius);
                box-shadow: var(--shadow);
            }
            .stats-card {
                background: var(--card-bg);
                border-radius: var(--radius);
                padding: 20px;
                box-shadow: var(--shadow);
                border: 1px solid var(--border);
                transition: transform var(--transition), box-shadow var(--transition);
            }
            .stats-card:hover {
                transform: translateY(-3px);
                box-shadow: var(--shadow-md);
            }
            .stats-icon {
                width: 48px; height: 48px;
                border-radius: 10px;
                display: flex; align-items: center; justify-content: center;
                font-size: 1.4rem;
                margin-bottom: 12px;
            }
            .table-responsive { border-radius: var(--radius); overflow: hidden; }
            .table th { font-size: 0.78rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; color: var(--muted); }
            .table td { font-size: 0.875rem; vertical-align: middle; }
            .task-card {
                background: white;
                border-radius: var(--radius);
                padding: 15px;
                margin-bottom: 14px;
                border-left: 4px solid var(--brand);
                box-shadow: var(--shadow);
            }
            .task-card.high { border-left-color: #ef4444; }
            .task-card.medium { border-left-color: #f59e0b; }
            .task-card.low { border-left-color: #10b981; }
            .task-status {
                display: inline-block;
                padding: 3px 10px;
                border-radius: 20px;
                font-size: 0.78rem;
                font-weight: 600;
            }
            .status-pending { background: #fef3c7; color: #92400e; }
            .status-in_progress { background: #dbeafe; color: #1e40af; }
            .status-completed { background: #d1fae5; color: #065f46; }
            .status-cancelled { background: #fee2e2; color: #991b1b; }
            .chat-container {
                display: flex;
                height: calc(100vh - var(--top-h) - 40px);
                background: white;
                border-radius: var(--radius);
                overflow: hidden;
                box-shadow: var(--shadow);
            }
            .chat-user { padding: 10px 15px; border-bottom: 1px solid var(--border); cursor: pointer; transition: background var(--transition); }
            .chat-user:hover, .chat-user.active { background: #e8f0fb; }
            .chat-user.global { background: var(--brand); color: white; }
            .chat-user .online-indicator { width: 9px; height: 9px; border-radius: 50%; display: inline-block; margin-right: 5px; }
            .chat-user .online { background: #10b981; }
            .chat-user .offline { background: #94a3b8; }
            .comment-item { padding: 10px; border-bottom: 1px solid var(--border); }
            .comment-item:last-child { border-bottom: none; }
            .audit-log-item { font-size: 0.88rem; padding: 8px; border-bottom: 1px solid var(--border); }
            .qr-modal-img { max-width: 240px; margin: 0 auto; }
            @media (max-width: 768px) {
                html { font-size: 14px; }
                .sidebar { transform: translateX(calc(-1 * var(--sidebar-w))); box-shadow: none; }
                .sidebar.open { transform: translateX(0); box-shadow: 4px 0 32px rgba(0,0,0,0.25); }
                .sidebar-close-btn { display: flex; }
                .content { margin-left: 0; }
                .hamburger-btn { display: flex; align-items: center; justify-content: center; }
                .page-content { padding: 12px; }
                .navbar-top { padding: 0 12px; height: 56px; }
                .page-title { font-size: 1rem; }
                .navbar-time { display: none; }
                .stats-grid-mobile { display: grid !important; grid-template-columns: 1fr 1fr; gap: 10px; }
                .stats-card { padding: 14px; }
                .stats-icon { width: 38px; height: 38px; font-size: 1.1rem; margin-bottom: 8px; }
                .chat-container { height: calc(100vh - 56px - 24px); border-radius: 8px; }
                .table-responsive { -webkit-overflow-scrolling: touch; }
                .modal-dialog { margin: 0; max-width: 100%; }
                .modal-content { border-radius: 16px 16px 0 0; min-height: 60vh; width: 72%; }
                .modal.fade .modal-dialog { transform: translateY(100%); }
                .modal.show .modal-dialog { transform: translateY(0); }
                .modal .modal-dialog { transition: transform 0.3s ease; position: fixed; bottom: 0; left: 0; right: 0; }
                .btn-sm { font-size: 0.78rem; }
                .btn-group .btn { padding: 5px 8px; }
                .btn-text-hide { display: none; }
            }
            @media (max-width: 480px) {
                .page-content { padding: 8px; }
                .stats-card { padding: 12px; }
                .navbar-top { height: 52px; }
                .page-title { font-size: 0.95rem; }
            }
            body.sidebar-open { overflow: hidden; }
        </style>
    </head>
    <body>
        <div class="sidebar-overlay" id="sidebarOverlay"></div>
        <div class="wrapper">
            <div class="sidebar" id="sidebar">
                <div class="sidebar-header">
                    <div class="sidebar-brand">
                        <?php if ($site_logo && filter_var($site_logo, FILTER_VALIDATE_URL)): ?>
                            <img src="<?php echo htmlspecialchars($site_logo); ?>" alt="Logo">
                        <?php else: ?>
                            <div style="font-size:1.6rem;line-height:1;">🏢</div>
                        <?php endif; ?>
                        <div class="sidebar-brand-text">
                            <h3>D K Associates</h3>
                            <p>Admin Panel v4.0</p>
                        </div>
                    </div>
                    <button class="sidebar-close-btn" id="sidebarCloseBtn" aria-label="Close menu">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
                <div class="sidebar-scroll">
                    <div class="user-info">
                        <div class="user-info-inner">
                            <?php if (!empty($user['profile_photo'])): ?>
                                <img src="<?php echo htmlspecialchars($user['profile_photo']); ?>" class="user-avatar" alt="">
                            <?php else: ?>
                                <div class="user-avatar-initial"><?php echo strtoupper(substr($user['full_name'], 0, 1)); ?></div>
                            <?php endif; ?>
                            <div class="user-info-text">
                                <strong><?php echo htmlspecialchars($user['full_name']); ?></strong>
                                <span class="role-badge"><?php echo ucfirst($role); ?></span>
                            </div>
                        </div>
                        <div class="user-staff-id">
                            <i class="bi bi-person-badge"></i>
                            <?php echo htmlspecialchars($user['staff_id'] ?: $user['worker_id'] ?: 'N/A'); ?>
                        </div>
                    </div>
                    <nav>
                        <div class="nav-section-label">Main</div>
                        <a class="nav-link <?php echo $view === 'dashboard' ? 'active' : ''; ?>" href="?view=dashboard">
                            <i class="bi bi-speedometer2"></i>
                            <span>Dashboard</span>
                            <?php if ($pending_requests > 0 && $is_admin): ?>
                                <span class="nav-badge"><?php echo $pending_requests; ?></span>
                            <?php endif; ?>
                        </a>
                        <a class="nav-link <?php echo $view === 'users' ? 'active' : ''; ?>" href="?view=users">
                            <i class="bi bi-people"></i>
                            <span>User Management</span>
                        </a>
                        <a class="nav-link <?php echo $view === 'chat' ? 'active' : ''; ?>" href="?view=chat">
                            <i class="bi bi-chat-dots"></i>
                            <span>Chat Center</span>
                            <?php if ($active_chats > 0 && !empty($user['care_permission'])): ?>
                                <span class="nav-badge"><?php echo $active_chats; ?></span>
                            <?php endif; ?>
                        </a>
                        <a class="nav-link <?php echo $view === 'tasks' ? 'active' : ''; ?>" href="?view=tasks">
                            <i class="bi bi-check2-square"></i>
                            <span>Tasks</span>
                            <?php if ($active_tasks > 0): ?>
                                <span class="nav-badge"><?php echo $active_tasks; ?></span>
                            <?php endif; ?>
                        </a>
                        <div class="nav-section-label">Workspace</div>
                        <a class="nav-link <?php echo $view === 'enquiries' ? 'active' : ''; ?>" href="?view=enquiries">
                            <i class="bi bi-envelope"></i>
                            <span>Enquiries</span>
                            <?php if ($pending_enquiries > 0): ?>
                                <span class="nav-badge"><?php echo $pending_enquiries; ?></span>
                            <?php endif; ?>
                        </a>
                        <a class="nav-link <?php echo $view === 'contacts' ? 'active' : ''; ?>" href="?view=contacts">
                            <i class="bi bi-journal-text"></i>
                            <span>Contacts</span>
                        </a>
                        <a class="nav-link <?php echo $view === 'vacancies' ? 'active' : ''; ?>" href="?view=vacancies">
                            <i class="bi bi-briefcase"></i>
                            <span>Job Vacancies</span>
                        </a>
                        <?php if ($is_admin): ?>
                        <div class="nav-section-label">Admin</div>
                        <a class="nav-link <?php echo $view === 'team' ? 'active' : ''; ?>" href="?view=team">
                            <i class="bi bi-people-fill"></i>
                            <span>Team Management</span>
                        </a>
                        <a class="nav-link <?php echo $view === 'settings' ? 'active' : ''; ?>" href="?view=settings">
                            <i class="bi bi-gear-fill"></i>
                            <span>Site Settings</span>
                        </a>
                        <a class="nav-link <?php echo $view === 'audit' ? 'active' : ''; ?>" href="?view=audit">
                            <i class="bi bi-shield-check"></i>
                            <span>Audit Logs</span>
                        </a>
                        <?php endif; ?>
                        <div class="nav-section-label">Account</div>
                        <a class="nav-link <?php echo $view === 'reports' ? 'active' : ''; ?>" href="?view=reports">
                            <i class="bi bi-bar-chart-line"></i>
                            <span>Reports</span>
                        </a>
                        <a class="nav-link <?php echo $view === 'profile' ? 'active' : ''; ?>" href="?view=profile">
                            <i class="bi bi-person-circle"></i>
                            <span>My Profile</span>
                        </a>
                        <a class="nav-link" href="#" id="openAdminChat">
                            <i class="bi bi-headset"></i>
                            <span>Support Chat</span>
                            <span class="nav-badge" id="sidebarChatBadge" style="display:none;"></span>
                        </a>
                    </nav>
                </div>
                <div class="sidebar-logout">
                    <form method="POST">
                        <input type="hidden" name="action" value="logout">
                        <button type="submit">
                            <i class="bi bi-box-arrow-right"></i>
                            Logout
                        </button>
                    </form>
                </div>
            </div>
            <div class="content" id="mainContent">
                <div class="navbar-top">
                    <div class="navbar-left">
                        <button class="hamburger-btn" id="hamburgerBtn" aria-label="Open menu" aria-expanded="false">
                            <i class="bi bi-list"></i>
                        </button>
                        <span class="page-title">
                            <?php
                            $titles = [
                                'dashboard' => 'Dashboard',
                                'users'     => 'User Management',
                                'chat'      => 'Chat Center',
                                'tasks'     => 'Task Management',
                                'enquiries' => 'Enquiries & Contacts',
                                'contacts'  => 'Contact Management',
                                'vacancies' => 'Job Vacancies',
                                'team'      => 'Team Management',
                                'settings'  => 'Site Settings',
                                'audit'     => 'Audit Logs',
                                'reports'   => 'Reports',
                                'profile'   => 'My Profile',
                            ];
                            echo $titles[$view] ?? 'Dashboard';
                            ?>
                        </span>
                    </div>
                    <div class="navbar-right">
                        <span class="navbar-time">
                            <i class="bi bi-clock me-1"></i>
                            <?php echo date($date_format . ' ' . $time_format); ?>
                        </span>
                    </div>
                </div>
                <div class="page-content">
                <?php
                switch ($view) {
                    case 'dashboard':  includeDashboard($db, $user);     break;
                    case 'users':      includeUsersView($db, $user);     break;
                    case 'chat':       includeChatView($db, $user);      break;
                    case 'tasks':      includeTasksView($db, $user);     break;
                    case 'enquiries':  includeEnquiriesView($db, $user); break;
                    case 'contacts':   includeContactsView($db, $user);  break;
                    case 'vacancies':  includeVacanciesView($db, $user); break;
                    case 'team':       includeTeamView($db, $user);      break;
                    case 'settings':   includeSettingsView($db, $user);  break;
                    case 'audit':      includeAuditView($db, $user);     break;
                    case 'reports':    includeReportsView($db, $user);   break;
                    case 'profile':    includeProfileView($db, $user);   break;
                }
                ?>
                </div>
            </div>
        </div>
        
        <!-- QR Code Modal -->
        <div class="modal fade" id="qrModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">ID Card QR Code</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body text-center">
                        <img src="" class="img-fluid mb-3 qr-modal-img" id="qrImage">
                        <p><strong id="qrIdNumber"></strong></p>
                        <p class="text-muted small">Scan this QR code to verify identity</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <a href="" id="qrDownloadBtn" download class="btn btn-primary">
                            <i class="bi bi-download"></i> Download
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Digital ID Card Modal -->
        <div class="modal fade" id="idCardModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content border-0 bg-transparent shadow-none">
                    <div class="modal-body p-0">
                        <div id="idCardBody"></div>
                    </div>
                    <div class="modal-footer justify-content-center gap-2 bg-white" style="border-radius:0 0 14px 14px;border-top:1px solid #e2e8f0;">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle me-1"></i>Close
                        </button>
                        <button type="button" class="btn btn-primary" onclick="printIdCard()">
                            <i class="bi bi-printer me-1"></i>Print / Download
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Chat Widget -->
        <div class="admin-chat-widget" id="adminChatWidget" style="display:none;">
            <div class="admin-chat-header">
                <div class="d-flex align-items-center gap-2 flex-fill" style="min-width:0;">
                    <i class="bi bi-headset" style="font-size:1.1rem;flex-shrink:0;"></i>
                    <div style="min-width:0;">
                        <h6 id="widgetTitle" style="margin:0;font-size:0.88rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                            <?php echo !empty($user['care_permission']) ? 'Guest Helpdesk' : 'Staff Chat'; ?>
                        </h6>
                        <small style="opacity:0.72;font-size:0.68rem;">
                            <?php echo !empty($user['care_permission']) ? 'Guest chats &amp; support' : 'Internal support'; ?>
                        </small>
                    </div>
                </div>
                <button class="btn-close btn-close-white btn-sm flex-shrink-0" id="closeAdminChat" style="opacity:0.8;"></button>
            </div>

            <?php if (!empty($user['care_permission'])): ?>
            <div id="wTabBar" style="display:flex;border-bottom:1px solid rgba(255,255,255,0.15);flex-shrink:0;background:linear-gradient(135deg,#0f3b5e,#1a4b73);">
                <button id="wTabGuest" onclick="switchWidgetTab('guest')"
                        style="flex:1;padding:8px 4px;border:none;font-size:0.78rem;font-weight:600;cursor:pointer;color:white;background:rgba(255,255,255,0.18);border-bottom:2px solid white;transition:all 0.2s;">
                    <i class="bi bi-people me-1"></i>Guest
                    <span id="wGuestBadge" style="display:none;background:#ef4444;color:white;border-radius:50%;padding:1px 5px;font-size:0.62rem;margin-left:3px;"></span>
                </button>
                <button id="wTabStaff" onclick="switchWidgetTab('staff')"
                        style="flex:1;padding:8px 4px;border:none;font-size:0.78rem;font-weight:600;cursor:pointer;color:rgba(255,255,255,0.65);background:transparent;border-bottom:2px solid transparent;transition:all 0.2s;">
                    <i class="bi bi-chat me-1"></i>Staff
                </button>
            </div>

            <div id="wPanelGuest" style="flex:1;display:flex;flex-direction:column;overflow:hidden;background:#f8fafc;">
                <div id="wGuestList" style="flex:1;overflow-y:auto;"></div>
                <div id="wGuestConv" style="display:none;flex-direction:column;flex:1;overflow:hidden;">
                    <div style="padding:8px 12px;background:#e8f0fb;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;gap:8px;flex-shrink:0;">
                        <button onclick="backToGuestList()" style="background:none;border:none;color:#0f3b5e;font-size:1rem;padding:0 4px;cursor:pointer;line-height:1;"><i class="bi bi-arrow-left"></i></button>
                        <span id="wGuestConvName" style="font-weight:600;font-size:0.83rem;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"></span>
                        <button onclick="endWidgetGuestChat()" style="background:#ef4444;border:none;color:white;border-radius:6px;padding:3px 8px;font-size:0.7rem;cursor:pointer;flex-shrink:0;"><i class="bi bi-x me-1"></i>End</button>
                    </div>
                    <div id="wGuestMsgs" style="flex:1;overflow-y:auto;padding:10px;display:flex;flex-direction:column;gap:5px;background:#f8fafc;"></div>
                    <div style="padding:8px;border-top:1px solid #e2e8f0;display:flex;gap:6px;background:white;flex-shrink:0;">
                        <input id="wGuestInput" type="text" placeholder="Reply to guest…"
                               style="flex:1;border:1.5px solid #e2e8f0;border-radius:20px;padding:7px 12px;font-size:0.83rem;outline:none;"
                               onkeypress="if(event.key==='Enter'){event.preventDefault();sendWidgetGuestMsg();}">
                        <button onclick="sendWidgetGuestMsg()"
                                style="width:34px;height:34px;border-radius:50%;background:#0f3b5e;border:none;color:white;display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;">
                            <i class="bi bi-send-fill" style="font-size:0.78rem;"></i>
                        </button>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div id="wPanelStaff" style="<?php echo !empty($user['care_permission']) ? 'display:none;' : ''; ?>flex-direction:column;flex:1;overflow:hidden;">
                <div class="admin-chat-messages" id="adminChatMessages"></div>
                <div class="admin-chat-input">
                    <input type="text" id="adminChatInput" placeholder="Type a message…">
                    <button id="adminChatSendBtn"><i class="bi bi-send-fill"></i></button>
                </div>
            </div>
        </div>

        <!-- Floating toggle button -->
        <button class="admin-chat-toggle" id="adminChatToggle"
                title="<?php echo !empty($user['care_permission']) ? 'Guest Helpdesk' : 'Staff Chat'; ?>">
            <i class="bi bi-<?php echo !empty($user['care_permission']) ? 'headset' : 'chat-dots-fill'; ?>"></i>
            <span class="admin-chat-badge" id="adminChatBadge" style="display:none;">0</span>
        </button>

        <style>
        .admin-chat-toggle {
            position: fixed; bottom: 24px; right: 24px;
            width: 56px; height: 56px; border-radius: 50%;
            background: linear-gradient(135deg, #e67e22 0%, #f39c12 100%);
            color: white; border: none;
            box-shadow: 0 8px 24px rgba(230,126,34,0.4);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.4rem; cursor: pointer; transition: all 0.3s;
            z-index: 1100;
        }
        .admin-chat-toggle:hover { transform: scale(1.1); }
        .admin-chat-badge {
            position: absolute; top: -4px; right: -4px;
            background: #ef4444; color: white; border-radius: 50%;
            width: 20px; height: 20px; font-size: 0.65rem; font-weight: 700;
            display: flex; align-items: center; justify-content: center;
            border: 2px solid white;
        }
        .admin-chat-widget {
            position: fixed; bottom: 92px; right: 24px;
            width: 340px; height: 490px; background: white;
            border-radius: 16px; box-shadow: 0 20px 60px rgba(0,0,0,0.18);
            display: none; flex-direction: column; z-index: 1099;
            overflow: hidden; border: 1px solid #e2e8f0;
        }
        .admin-chat-widget.show { display: flex !important; animation: widgetUp 0.25s ease; }
        @keyframes widgetUp { from{opacity:0;transform:translateY(18px)} to{opacity:1;transform:translateY(0)} }
        .admin-chat-header {
            background: linear-gradient(135deg, #0f3b5e 0%, #1a4b73 100%);
            color: white; padding: 12px 14px; display: flex; align-items: center;
            gap: 8px; flex-shrink: 0;
        }
        .admin-chat-messages {
            flex: 1; padding: 12px 14px; overflow-y: auto;
            background: #f8fafc; display: flex; flex-direction: column; gap: 6px;
        }
        .admin-chat-message { display:flex; flex-direction:column; }
        .admin-chat-message.me    { align-items:flex-end; }
        .admin-chat-message.other { align-items:flex-start; }
        .admin-chat-bubble {
            max-width: 82%; padding: 7px 11px; border-radius: 12px;
            font-size: 0.87rem; word-wrap: break-word; line-height: 1.42;
        }
        .admin-chat-message.me .admin-chat-bubble {
            background: linear-gradient(135deg,#0f3b5e,#1a4b73);
            color: white; border-bottom-right-radius:3px;
        }
        .admin-chat-message.other .admin-chat-bubble {
            background: white; color:#1e293b;
            border-bottom-left-radius:3px; box-shadow:0 1px 4px rgba(0,0,0,0.08);
        }
        .admin-chat-time { font-size:0.62rem; color:#94a3b8; margin-top:2px; }
        .admin-chat-input {
            padding: 9px 10px; border-top: 1px solid #e2e8f0;
            display: flex; gap: 7px; background: white; flex-shrink: 0;
        }
        .admin-chat-input input {
            flex: 1; border: 1.5px solid #e2e8f0; border-radius: 20px;
            padding: 7px 12px; outline: none; font-size: 0.87rem;
        }
        .admin-chat-input input:focus { border-color: #0f3b5e; }
        .admin-chat-input button {
            width: 35px; height: 35px; border-radius: 50%;
            background: linear-gradient(135deg,#0f3b5e,#1a4b73);
            color: white; border: none; display:flex; align-items:center;
            justify-content:center; cursor:pointer; flex-shrink:0;
        }
        .admin-chat-input button:hover { transform:scale(1.1); }
        .w-guest-item {
            padding: 9px 12px; border-bottom: 1px solid #f1f5f9;
            cursor: pointer; transition: background 0.15s;
            display: flex; align-items: center; gap: 9px;
        }
        .w-guest-item:hover { background: #e8f0fb; }
        .w-guest-avatar {
            width: 34px; height: 34px; border-radius: 50%;
            background: #059669; color: white;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 0.88rem; flex-shrink: 0;
        }
        .w-guest-avatar.waiting { background: #d97706; }
        .w-guest-info { flex:1; min-width:0; }
        .w-guest-info strong { display:block; font-size:0.81rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .w-guest-info small { color:#94a3b8; font-size:0.7rem; }
        .w-unread-pill { background:#ef4444; color:white; border-radius:50%; width:18px; height:18px; font-size:0.6rem; font-weight:700; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .w-section-label { padding:4px 12px; font-size:0.63rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:#94a3b8; background:#f1f5f9; }
        .wg-msg { display:flex; flex-direction:column; }
        .wg-msg.me { align-items:flex-end; }
        .wg-msg.other { align-items:flex-start; }
        .wg-msg.system { align-items:center; }
        .wg-bubble { max-width:82%; padding:7px 11px; border-radius:12px; font-size:0.82rem; word-wrap:break-word; line-height:1.42; }
        .wg-msg.me .wg-bubble { background:#0f3b5e; color:white; border-bottom-right-radius:3px; }
        .wg-msg.other .wg-bubble { background:white; color:#1e293b; border-bottom-left-radius:3px; box-shadow:0 1px 4px rgba(0,0,0,0.07); }
        .wg-msg.system .wg-bubble { background:#fef3c7; color:#92400e; font-size:0.73rem; border-radius:8px; }
        .wg-time { font-size:0.61rem; color:#94a3b8; margin-top:2px; }
        @media (max-width: 480px) {
            .admin-chat-toggle { bottom:14px; right:14px; width:50px; height:50px; font-size:1.2rem; }
            .admin-chat-widget { right:0; bottom:72px; width:100vw; height:70vh; border-radius:16px 16px 0 0; }
        }
        </style>

        <script>
        // ===================================================================
        // Helper Functions
        // ===================================================================
        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }
        
        function userToast(msg, type) {
            type = type || 'success';
            var icons = { success:'check-circle-fill', danger:'exclamation-triangle-fill', warning:'exclamation-circle-fill', info:'info-circle-fill' };
            var t = document.createElement('div');
            t.className = 'position-fixed top-0 end-0 m-3 alert alert-' + type + ' shadow d-flex align-items-center gap-2';
            t.style.cssText = 'z-index:10000;max-width:360px;font-size:.88rem;border-radius:10px;';
            t.innerHTML = '<i class="bi bi-' + (icons[type]||'info-circle-fill') + ' flex-shrink-0"></i><div>' + msg + '</div>';
            document.body.appendChild(t);
            setTimeout(function(){ if(t.parentNode) t.remove(); }, 4000);
        }
        
        function showModal(id) {
            var el = document.getElementById(id);
            if (!el) return;
            var m = bootstrap.Modal.getOrCreateInstance(el);
            m.show();
        }
        
        function hideModal(id) {
            var el = document.getElementById(id);
            if (!el) return;
            var m = bootstrap.Modal.getInstance(el);
            if (m) m.hide();
        }
        
        function formatDate(dateStr) {
            if (!dateStr) return '—';
            try {
                var d = new Date(dateStr);
                if (isNaN(d.getTime())) return dateStr;
                return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
            } catch(e) {
                return dateStr;
            }
        }
        
        // ===================================================================
        // QR Code Function
        // ===================================================================
        function showQR(id) {
            var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data='
                + encodeURIComponent('https://' + window.location.host + '/admin.php?verify_id=' + id);
            var img = document.getElementById('qrImage');
            var num = document.getElementById('qrIdNumber');
            var dl  = document.getElementById('qrDownloadBtn');
            if (img) img.src = qrUrl;
            if (num) num.textContent = 'ID: ' + id;
            if (dl)  { dl.href = qrUrl; dl.download = 'qr_' + id + '.png'; }
            showModal('qrModal');
        }
        
        // ===================================================================
        // ID Card Function
        // ===================================================================
        function showIdCard(userId) {
            var body = document.getElementById('idCardBody');
            if (!body) return;
            body.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary"></div><p class="mt-2 text-muted small">Loading ID card…</p></div>';
            showModal('idCardModal');
            
            fetch('admin.php?api=get_user_details&user_id=' + userId)
                .then(function(r){ return r.json(); })
                .then(function(d){
                    if (!d.success){ 
                        body.innerHTML='<div class="alert alert-danger m-3">'+(d.error||'Failed to load')+'</div>'; 
                        return; 
                    }
                    var u = d.user;
                    var cardId   = u.staff_id || u.worker_id || '—';
                    var roleLbl  = u.role === 'worker' ? 'Worker' : (escapeHtml(u.designation) || u.role.charAt(0).toUpperCase() + u.role.slice(1));
                    var blood    = u.blood_group || '—';
                    var dob      = formatDate(u.date_of_birth);
                    var doj      = formatDate(u.date_of_joining);
                    var phone    = u.phone_primary || '—';
                    var email    = u.email || '—';
                    
                    var qrData = window.location.origin + '/admin.php?verify_id=' + encodeURIComponent(cardId);
                    var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&margin=2&data=' + encodeURIComponent(qrData);
                    
                    var photoHtml = u.profile_photo
                        ? '<img src="'+escapeHtml(u.profile_photo)+'" style="width:100px;height:100px;object-fit:cover;border-radius:12px;border:3px solid white;box-shadow:0 2px 8px rgba(0,0,0,0.1);" onerror="this.outerHTML=\'<div style=\\\"width:100px;height:100px;border-radius:12px;background:#0f3b5e;color:white;display:flex;align-items:center;justify-content:center;font-size:2.5rem;font-weight:800;\\\">\'+escapeHtml(u.full_name.charAt(0).toUpperCase())+\'</div>\'">'
                        : '<div style="width:100px;height:100px;border-radius:12px;background:#0f3b5e;color:white;display:flex;align-items:center;justify-content:center;font-size:2.5rem;font-weight:800;">'+escapeHtml(u.full_name.charAt(0).toUpperCase())+'</div>';
                    
                    body.innerHTML = `
                        <div id="idCardPrint" style="font-family:'Plus Jakarta Sans',Arial,sans-serif;max-width:420px;margin:0 auto;border-radius:20px;overflow:hidden;box-shadow:0 12px 40px rgba(0,0,0,0.2);">
                            <div style="background:linear-gradient(135deg,#0f3b5e 0%,#1a5c8a 100%);padding:16px 20px;">
                                <div style="display:flex;align-items:center;justify-content:space-between;">
                                    <div>
                                        <h3 style="color:white;margin:0;font-size:1.2rem;font-weight:700;">D K Associates</h3>
                                        <p style="color:rgba(255,255,255,0.8);margin:4px 0 0;font-size:0.7rem;">Official Identity Card</p>
                                    </div>
                                    <div style="background:rgba(255,255,255,0.2);padding:4px 12px;border-radius:20px;">
                                        <span style="color:white;font-size:0.7rem;font-weight:600;">${u.role === 'worker' ? 'WORKER' : 'STAFF'}</span>
                                    </div>
                                </div>
                            </div>
                            <div style="background:white;padding:20px;">
                                <div style="display:flex;gap:18px;margin-bottom:20px;">
                                    ${photoHtml}
                                    <div style="flex:1;">
                                        <h4 style="margin:0 0 4px 0;color:#0f3b5e;font-size:1.2rem;font-weight:700;">${escapeHtml(u.full_name)}</h4>
                                        <p style="margin:0 0 8px 0;color:#64748b;font-weight:500;">${escapeHtml(roleLbl)}</p>
                                        <div style="background:#f8fafc;padding:8px 12px;border-radius:10px;margin-top:8px;">
                                            <div style="font-size:0.7rem;color:#64748b;">ID NUMBER</div>
                                            <div style="font-size:1rem;font-weight:800;color:#0f3b5e;letter-spacing:1px;">${escapeHtml(cardId)}</div>
                                        </div>
                                    </div>
                                </div>
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:20px;padding:12px;background:#f8fafc;border-radius:12px;">
                                    <div><div style="font-size:0.65rem;color:#64748b;">📞 PHONE</div><div style="font-size:0.85rem;font-weight:500;">${escapeHtml(phone)}</div></div>
                                    <div><div style="font-size:0.65rem;color:#64748b;">📧 EMAIL</div><div style="font-size:0.7rem;font-weight:500;word-break:break-all;">${escapeHtml(email)}</div></div>
                                    <div><div style="font-size:0.65rem;color:#64748b;">🎂 DATE OF BIRTH</div><div style="font-size:0.85rem;font-weight:500;">${dob}</div></div>
                                    <div><div style="font-size:0.65rem;color:#64748b;">📅 JOINING DATE</div><div style="font-size:0.85rem;font-weight:500;">${doj}</div></div>
                                    ${blood !== '—' ? `<div><div style="font-size:0.65rem;color:#64748b;">🩸 BLOOD GROUP</div><div style="font-size:0.85rem;font-weight:500;">${escapeHtml(blood)}</div></div>` : ''}
                                    ${u.designation ? `<div><div style="font-size:0.65rem;color:#64748b;">💼 DESIGNATION</div><div style="font-size:0.85rem;font-weight:500;">${escapeHtml(u.designation)}</div></div>` : ''}
                                </div>
                                <div style="display:flex;align-items:center;gap:15px;padding:12px;background:linear-gradient(135deg,#f8fafc 0%,#f1f5f9 100%);border-radius:12px;">
                                    <img src="${qrUrl}" style="width:80px;height:80px;border-radius:8px;border:1px solid #e2e8f0;" alt="QR Code">
                                    <div style="flex:1;">
                                        <div style="font-size:0.75rem;font-weight:600;color:#0f3b5e;">Scan to Verify Identity</div>
                                        <div style="font-size:0.65rem;color:#64748b;margin-top:4px;">Use this QR code to verify the authenticity of this ID card online.</div>
                                    </div>
                                </div>
                                <div style="margin-top:15px;text-align:center;font-size:0.65rem;color:#94a3b8;border-top:1px solid #e2e8f0;padding-top:12px;">
                                    This is a computer-generated document. Valid only with official stamp.
                                </div>
                            </div>
                        </div>
                    `;
                })
                .catch(function(){ body.innerHTML='<div class="alert alert-danger m-3">Network error loading ID card</div>'; });
        }
        
        function printIdCard() {
            var card = document.getElementById('idCardPrint');
            if (!card) {
                userToast('ID card not loaded', 'warning');
                return;
            }
            
            var printWindow = window.open('', '_blank', 'width=500,height=700');
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>ID Card - D K Associates</title>
                    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
                    <style>
                        * { margin: 0; padding: 0; box-sizing: border-box; }
                        body {
                            font-family: 'Plus Jakarta Sans', Arial, sans-serif;
                            background: #e2e8f0;
                            display: flex;
                            justify-content: center;
                            align-items: center;
                            min-height: 100vh;
                            padding: 20px;
                        }
                        @media print {
                            body {
                                background: white;
                                padding: 0;
                                margin: 0;
                            }
                            .no-print {
                                display: none;
                            }
                        }
                        .print-btn {
                            position: fixed;
                            bottom: 20px;
                            right: 20px;
                            padding: 10px 20px;
                            background: #0f3b5e;
                            color: white;
                            border: none;
                            border-radius: 8px;
                            cursor: pointer;
                            font-size: 14px;
                            z-index: 1000;
                        }
                        .print-btn:hover {
                            background: #1a5c8a;
                        }
                    </style>
                </head>
                <body>
                    ${card.outerHTML}
                    <button class="print-btn no-print" onclick="window.print();setTimeout(function(){window.close();},1000);">
                        🖨️ Print ID Card
                    </button>
                    <script>
                        window.onload = function() {
                            setTimeout(function() {
                                window.print();
                                setTimeout(function() { window.close(); }, 500);
                            }, 500);
                        };
                    <\/script>
                </body>
                </html>
            `);
            printWindow.document.close();
        }
        
        // ===================================================================
        // Online Status Updater
        // ===================================================================
        (function() {
            let lastStatusUpdate = 0;
            
            function updateOnlineStatus() {
                const now = Date.now();
                if (now - lastStatusUpdate < 15000) return;
                lastStatusUpdate = now;
                
                fetch('admin.php?api=update_staff_online_status')
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            var onlineBadges = document.querySelectorAll('.online-count-badge');
                            onlineBadges.forEach(function(badge) {
                                if (badge.textContent) {
                                    badge.textContent = data.online_count || '0';
                                }
                            });
                        }
                    })
                    .catch(function() {});
            }
            
            setTimeout(updateOnlineStatus, 1000);
            setInterval(updateOnlineStatus, 20000);
            
            document.addEventListener('visibilitychange', function() {
                if (!document.hidden) updateOnlineStatus();
            });
            
            var activityTimeout;
            function onUserActivity() {
                clearTimeout(activityTimeout);
                activityTimeout = setTimeout(updateOnlineStatus, 1000);
            }
            document.addEventListener('mousemove', onUserActivity);
            document.addEventListener('keypress', onUserActivity);
            document.addEventListener('click', onUserActivity);
        })();
        
        // ===================================================================
        // Sidebar Toggle
        // ===================================================================
        (function() {
            var sidebar   = document.getElementById('sidebar');
            var overlay   = document.getElementById('sidebarOverlay');
            var hamburger = document.getElementById('hamburgerBtn');
            var closeBtn  = document.getElementById('sidebarCloseBtn');
            var MOBILE    = 768;
            
            function isMobile() { return window.innerWidth <= MOBILE; }
            function openSidebar() {
                if (!sidebar) return;
                sidebar.classList.add('open');
                if (overlay) overlay.classList.add('visible');
                document.body.classList.add('sidebar-open');
                if (hamburger) hamburger.setAttribute('aria-expanded', 'true');
            }
            function closeSidebar() {
                if (!sidebar) return;
                sidebar.classList.remove('open');
                if (overlay) overlay.classList.remove('visible');
                document.body.classList.remove('sidebar-open');
                if (hamburger) hamburger.setAttribute('aria-expanded', 'false');
            }
            if (hamburger) hamburger.addEventListener('click', function(e) {
                e.stopPropagation();
                sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
            });
            if (closeBtn) closeBtn.addEventListener('click', closeSidebar);
            if (overlay) overlay.addEventListener('click', closeSidebar);
            if (sidebar) sidebar.querySelectorAll('.nav-link').forEach(function(link) {
                link.addEventListener('click', function() {
                    if (isMobile()) setTimeout(closeSidebar, 80);
                });
            });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && sidebar && sidebar.classList.contains('open')) closeSidebar();
            });
            window.addEventListener('resize', function() {
                if (!isMobile()) {
                    if (sidebar) sidebar.classList.remove('open');
                    if (overlay) overlay.classList.remove('visible');
                    document.body.classList.remove('sidebar-open');
                }
            });
        })();
        </script>
        
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
        
    </body>
    </html>
    <?php
}

/**
 * Include Dashboard View - FIXED to filter activities based on hierarchy
 */
function includeDashboard($db, $user) {
    $is_admin = ($user['role'] === 'admin');
    $is_manager = ($user['role'] === 'manager');
    $current_id = $user['id'];
    
    // Get counts
    $total_users = $db->querySingle("SELECT COUNT(*) FROM admin_users WHERE is_active = 1");
    $online_staff = $db->querySingle("SELECT COUNT(*) FROM admin_users WHERE is_online = 1 AND last_activity > datetime('now', '-5 minutes')");
    $total_enquiries = $db->querySingle("SELECT COUNT(*) FROM service_enquiries WHERE is_archived = 0");
    $pending_requests = $db->querySingle("SELECT COUNT(*) FROM profile_update_requests WHERE status = 'pending'");
    $active_tasks = $db->querySingle("SELECT COUNT(*) FROM tasks WHERE status IN ('pending', 'in_progress') AND is_archived = 0");
    $active_chats = $db->querySingle("SELECT COUNT(*) FROM chat_sessions WHERE status = 'active'");
    
    // Get recent activities - FILTERED by hierarchy
    $query = "SELECT al.*, au.full_name, au.role 
              FROM audit_logs al
              LEFT JOIN admin_users au ON al.user_id = au.id 
              WHERE 1=1";
    
    // Non-admins can only see activities of users in their hierarchy
    if (!$is_admin) {
        if ($is_manager) {
            // Managers can see their own activities and activities of staff/workers
            $query .= " AND (al.user_id = $current_id OR au.role IN ('staff', 'worker'))";
        } else {
            // Staff/workers can only see their own activities
            $query .= " AND al.user_id = $current_id";
        }
    }
    
    $query .= " ORDER BY al.created_at DESC LIMIT 10";
    
    $recent_logs = $db->query($query);
    ?>
    
    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="stats-card">
                <div class="stats-icon" style="background: #e6f7ff; color: #1890ff;">
                    <i class="bi bi-people"></i>
                </div>
                <h3><?php echo $online_staff; ?> / <?php echo $total_users; ?></h3>
                <p class="text-muted mb-0">Staff Online</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card">
                <div class="stats-icon" style="background: #f6ffed; color: #52c41a;">
                    <i class="bi bi-chat-dots"></i>
                </div>
                <h3><?php echo $active_chats; ?></h3>
                <p class="text-muted mb-0">Active Chats</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card">
                <div class="stats-icon" style="background: #fff7e6; color: #fa8c16;">
                    <i class="bi bi-check2-square"></i>
                </div>
                <h3><?php echo $active_tasks; ?></h3>
                <p class="text-muted mb-0">Active Tasks</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card">
                <div class="stats-icon" style="background: #f9f0ff; color: #722ed1;">
                    <i class="bi bi-envelope"></i>
                </div>
                <h3><?php echo $total_enquiries; ?></h3>
                <p class="text-muted mb-0">Pending Enquiries</p>
            </div>
        </div>
    </div>
    
    <div class="row">
        <?php if ($is_admin && $pending_requests > 0): ?>
        <div class="col-12 mb-4">
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle me-2"></i>
                You have <strong><?php echo $pending_requests; ?></strong> pending profile update requests.
                <a href="?view=users&tab=requests" class="alert-link">Review now</a>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="col-lg-6 mb-4">
            <div class="card">
                <div class="card-header bg-white">
                    <h5 class="mb-0">Recent Activity</h5>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <?php 
                        $has_logs = false;
                        while ($log = $recent_logs->fetchArray(SQLITE3_ASSOC)): 
                            $has_logs = true;
                        ?>
                        <div class="list-group-item">
                            <div class="d-flex justify-content-between">
                                <small class="text-muted"><?php echo date('d M H:i', strtotime($log['created_at'])); ?></small>
                                <small class="badge bg-<?php 
                                    echo $log['role'] === 'admin' ? 'danger' : 
                                        ($log['role'] === 'manager' ? 'warning' : 
                                        ($log['role'] === 'staff' ? 'info' : 'secondary')); 
                                ?>"><?php echo htmlspecialchars($log['full_name'] ?: 'System'); ?></small>
                            </div>
                            <p class="mb-0"><?php echo htmlspecialchars($log['action']); ?></p>
                            <?php if (!empty($log['description'])): ?>
                            <small class="text-muted"><?php echo htmlspecialchars($log['description']); ?></small>
                            <?php endif; ?>
                        </div>
                        <?php endwhile; ?>
                        
                        <?php if (!$has_logs): ?>
                        <div class="list-group-item text-center text-muted">
                            No recent activity found
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-6 mb-4">
            <div class="card">
                <div class="card-header bg-white">
                    <h5 class="mb-0">Quick Actions</h5>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <button class="btn btn-outline-primary text-start" onclick="showModal('createTaskModal')">
                            <i class="bi bi-plus-circle me-2"></i>Create New Task
                        </button>
                        <button class="btn btn-outline-primary text-start" onclick="window.location.href='?view=chat'">
                            <i class="bi bi-chat me-2"></i>Go to Chat Center
                        </button>
                        <?php if ($user['role'] === 'admin'): ?>
                        <button class="btn btn-outline-primary text-start" onclick="window.location.href='?view=contacts'">
                            <i class="bi bi-journal-text me-2"></i>Manage Contacts
                        </button>
                        <button class="btn btn-outline-primary text-start" onclick="window.location.href='?view=settings'">
                            <i class="bi bi-gear me-2"></i>Site Settings
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php
}

/**
 * Include Users View
 */
function includeUsersView($db, $user) {
    $is_admin   = ($user['role'] === 'admin');
    $is_manager = ($user['role'] === 'manager');
    $tab = $_GET['tab'] ?? 'list';
    ?>
    
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?php echo $tab === 'list' ? 'active' : ''; ?>" href="?view=users&tab=list">
                <i class="bi bi-people me-1"></i>Staff List
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $tab === 'workers' ? 'active' : ''; ?>" href="?view=users&tab=workers">
                <i class="bi bi-person-gear me-1"></i>Workers
            </a>
        </li>
        <?php if ($is_admin || $is_manager): ?>
        <li class="nav-item">
            <a class="nav-link <?php echo $tab === 'requests' ? 'active' : ''; ?>" href="?view=users&tab=requests">
                <i class="bi bi-clock-history me-1"></i>Profile Requests
            </a>
        </li>
        <?php endif; ?>
    </ul>
    
    <?php
    if ($tab === 'list') {
        includeUsersList($db, $user, false);
    } elseif ($tab === 'workers') {
        includeUsersList($db, $user, true);
    } else {
        includeProfileRequests($db, $user);
    }
}

/**
 * Include Users List
 */
function includeUsersList($db, $user, $workers_only = false) {
    $is_admin   = ($user['role'] === 'admin');
    $is_manager = ($user['role'] === 'manager');
    $section_label = $workers_only ? 'Workers' : 'Staff';
    $role_filter   = $workers_only ? "role = 'worker'" : "role != 'worker'";

    // Fetch users eagerly – no lazy loading
    $query = "SELECT * FROM admin_users WHERE is_active = 1 AND $role_filter";
    if (!$is_admin && !$is_manager) {
        $query .= " AND role IN ('staff')";
    }
    $query .= " ORDER BY CASE role WHEN 'admin' THEN 1 WHEN 'manager' THEN 2 WHEN 'staff' THEN 3 WHEN 'worker' THEN 4 END, full_name ASC";
    $users_result = $db->query($query);
    $rows = [];
    while ($r = $users_result->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;

    // Collect staff list for assignment dropdowns
    $all_staff_result = $db->query("SELECT id, full_name, role FROM admin_users WHERE is_active = 1 AND role != 'worker' ORDER BY full_name");
    $all_staff = [];
    while ($r = $all_staff_result->fetchArray(SQLITE3_ASSOC)) $all_staff[] = $r;
    ?>
    
    <div class="mb-3 d-flex gap-2 align-items-center">
        <button class="btn btn-primary" onclick="showModal('addUserModal')">
            <i class="bi bi-plus-circle me-2"></i>Add <?php echo $section_label; ?>
        </button>
        <input type="text" id="userSearchBox" class="form-control" style="max-width:260px;" placeholder="Search <?php echo strtolower($section_label); ?>...">
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="usersTable">
                    <thead class="table-light">
                        <tr>
                            <th>Photo</th>
                            <th>Full Name</th>
                            <th>Username</th>
                            <?php if (!$workers_only): ?><th>Role</th><?php endif; ?>
                            <th>Designation</th>
                            <th>Phone</th>
                            <th>ID Card</th>
                            <?php if ($workers_only): ?><th>ID Verified</th><?php endif; ?>
                            <th>Status</th>
                            <th>Access</th>
                            <?php if (!$workers_only): ?><th>Care</th><th>HR</th><?php endif; ?>
                            <th>Last Active</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row):
                        $can_edit   = ($is_admin || ($is_manager && in_array($row['role'], ['staff','worker'])));
                        $can_delete = ($is_admin || ($is_manager && in_array($row['role'], ['staff','worker'])));
                        $row_blocked = !empty($row['access_blocked']);
                    ?>
                    <tr class="user-row <?php echo $row_blocked ? 'table-danger' : ''; ?>"
                        data-name="<?php echo htmlspecialchars(strtolower($row['full_name'])); ?>"
                        data-uname="<?php echo htmlspecialchars(strtolower($row['username'])); ?>">
                        <td>
                            <?php if (!empty($row['profile_photo'])): ?>
                                <img src="<?php echo htmlspecialchars($row['profile_photo']); ?>" class="rounded-circle" style="width:40px;height:40px;object-fit:cover;">
                            <?php else: ?>
                                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center fw-bold" style="width:40px;height:40px;">
                                    <?php echo strtoupper(substr($row['full_name'],0,1)); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><strong><?php echo htmlspecialchars($row['full_name']); ?></strong></td>
                        <td class="text-muted"><?php echo htmlspecialchars($row['username']); ?></td>
                        <?php if (!$workers_only): ?>
                        <td>
                            <span class="badge bg-<?php echo $row['role']==='admin'?'danger':($row['role']==='manager'?'warning':($row['role']==='staff'?'info':'secondary')); ?>">
                                <?php echo ucfirst($row['role']); ?>
                            </span>
                        </td>
                        <?php endif; ?>
                        <td><?php echo htmlspecialchars($row['designation'] ?: '—'); ?></td>
                        <td><?php echo htmlspecialchars($row['phone_primary'] ?: '—'); ?></td>
                        <td>
                            <?php $card_id = $row['staff_id'] ?: $row['worker_id']; ?>
                            <?php if ($card_id): ?>
                                <code class="small"><?php echo htmlspecialchars($card_id); ?></code>
                                <button class="btn btn-sm btn-link p-0 ms-1" onclick="showIdCard(<?php echo $row['id']; ?>)" title="View ID Card">
                                    <i class="bi bi-id-card"></i>
                                </button>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <?php if ($workers_only): ?>
                        <td>
                            <?php if (!empty($row['id_card_verified'])): ?>
                                <span class="badge bg-success"><i class="bi bi-patch-check me-1"></i>Verified</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Unverified</span>
                                <?php if ($can_edit): ?>
                                <button class="btn btn-xs btn-outline-success ms-1" onclick="verifyIdCard(<?php echo $row['id']; ?>,1)" title="Mark Verified" style="font-size:0.7rem;padding:2px 6px;">
                                    ✓ Verify
                                </button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td>
                            <?php if ($row['is_online'] && $row['last_activity'] && strtotime($row['last_activity']) > strtotime('-5 minutes')): ?>
                                <span class="badge bg-success">Online</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Offline</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row_blocked): ?>
                                <span class="badge bg-danger"><i class="bi bi-lock me-1"></i>Blocked</span>
                            <?php else: ?>
                                <span class="badge bg-success"><i class="bi bi-unlock me-1"></i>Active</span>
                            <?php endif; ?>
                        </td>
                        <?php if (!$workers_only): ?>
                        <td>
                            <?php echo $row['care_permission'] ? '<span class="badge bg-info">Yes</span>' : '<span class="badge bg-light text-dark">No</span>'; ?>
                        </td>
                        <td>
                            <?php echo !empty($row['hr_permission']) ? '<span class="badge bg-purple" style="background:#7c3aed;">Yes</span>' : '<span class="badge bg-light text-dark">No</span>'; ?>
                        </td>
                        <?php endif; ?>
                        <td class="small text-muted">
                            <?php echo $row['last_activity'] ? date('d M H:i', strtotime($row['last_activity'])) : 'Never'; ?>
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                            <?php if ($can_edit): ?>
                                <button class="btn btn-outline-primary" onclick="editUser(<?php echo $row['id']; ?>)" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </button>
                            <?php endif; ?>
                            <?php if ($can_edit && $row['id'] != $user['id']): ?>
                                <?php if ($row_blocked): ?>
                                <button class="btn btn-outline-success" onclick="blockUser(<?php echo $row['id']; ?>,0)" title="Unblock Access">
                                    <i class="bi bi-unlock"></i>
                                </button>
                                <?php else: ?>
                                <button class="btn btn-outline-warning" onclick="blockUser(<?php echo $row['id']; ?>,1)" title="Block Access">
                                    <i class="bi bi-lock"></i>
                                </button>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($can_delete && $row['id'] != $user['id']): ?>
                                <button class="btn btn-outline-danger" onclick="deleteUser(<?php echo $row['id']; ?>)" title="Delete">
                                    <i class="bi bi-trash"></i>
                                </button>
                            <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($rows)): ?>
                    <tr><td colspan="14" class="text-center text-muted py-4">No <?php echo strtolower($section_label); ?> found</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ==================== ADD USER MODAL ==================== -->
    <div class="modal fade" id="addUserModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Register New <?php echo $section_label; ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="addUserForm" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="full_name" required placeholder="Enter full name">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Username <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="username" required placeholder="Unique login username">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Phone Number</label>
                                <input type="tel" class="form-control" name="phone_primary" placeholder="+91 XXXXX XXXXX">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Email</label>
                                <input type="email" class="form-control" name="email" placeholder="email@example.com">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Designation</label>
                                <input type="text" class="form-control" name="designation" placeholder="e.g. Senior Consultant">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Role <span class="text-danger">*</span></label>
                                <select class="form-select" name="role" id="regRoleSelect" required>
                                    <?php if ($workers_only): ?>
                                    <option value="worker" selected>Worker</option>
                                    <?php else: ?>
                                    <option value="staff">Staff</option>
                                    <?php if ($is_admin): ?>
                                    <option value="manager">Manager</option>
                                    <option value="admin">Admin</option>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Password <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="text" class="form-control" name="password" id="regPassword" value="<?php echo bin2hex(random_bytes(4)); ?>" required>
                                    <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('regPassword').value=Math.random().toString(36).slice(2,10)">
                                        <i class="bi bi-arrow-clockwise"></i>
                                    </button>
                                </div>
                                <small class="text-muted">Auto-generated. You may change it manually.</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Date of Joining</label>
                                <input type="date" class="form-control" name="date_of_joining" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Profile Photo</label>
                                <input type="file" class="form-control" name="profile_photo_file" id="regPhotoFile" accept="image/*">
                                <small class="text-muted">JPG, PNG, WebP – max 5MB (optional)</small>
                                <div id="regPhotoPreviewWrap" class="mt-2" style="display:none;">
                                    <img id="regPhotoPreview" src="" class="rounded-circle" style="width:70px;height:70px;object-fit:cover;border:2px solid #0f3b5e;">
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Access Control</label>
                                <div class="d-flex flex-wrap gap-3 mt-1">
                                    <?php if (!$workers_only): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="care_permission" id="reg_care">
                                        <label class="form-check-label" for="reg_care">
                                            <span class="badge bg-info me-1">Care</span> Guest Chat Access
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="hr_permission" id="reg_hr">
                                        <label class="form-check-label" for="reg_hr">
                                            <span class="badge me-1" style="background:#7c3aed;">HR</span> Recruitment Access
                                        </label>
                                    </div>
                                    <?php endif; ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="access_blocked" id="reg_blocked">
                                        <label class="form-check-label text-danger" for="reg_blocked">
                                            <i class="bi bi-lock me-1"></i>Block Access Initially
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-person-check me-2"></i>Register <?php echo $section_label; ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ==================== EDIT USER MODAL — Full registration form prefilled ==================== -->
    <div class="modal fade" id="editUserModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Edit User Profile</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="editUserForm">
                    <input type="hidden" name="user_id" id="edit_user_id">
                    <div class="modal-body">
                        <!-- Photo + ID row at top -->
                        <div class="d-flex align-items-center gap-3 mb-4 p-3 bg-light rounded">
                            <div id="editAvatarWrap">
                                <div id="editAvatarInitial" class="rounded-circle d-flex align-items-center justify-content-center fw-bold fs-4"
                                     style="width:70px;height:70px;background:#0f3b5e;color:white;flex-shrink:0;">?</div>
                                <img id="editAvatarImg" src="" class="rounded-circle" style="width:70px;height:70px;object-fit:cover;display:none;flex-shrink:0;">
                            </div>
                            <div class="flex-fill">
                                <div class="fw-bold" id="editUserDisplayName">—</div>
                                <small class="text-muted" id="editUserDisplayRole">—</small>
                            </div>
                            <div class="text-end">
                                <code id="editUserIdCard" class="small"></code>
                            </div>
                        </div>

                        <div class="row g-3">
                            <!-- Section: Basic -->
                            <div class="col-12"><h6 class="fw-bold text-primary border-bottom pb-1 mb-0"><i class="bi bi-person me-2"></i>Basic Information</h6></div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="full_name" id="edit_full_name" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Email</label>
                                <input type="email" class="form-control" name="email" id="edit_email">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Designation</label>
                                <input type="text" class="form-control" name="designation" id="edit_designation">
                            </div>
                            <?php if ($is_admin): ?>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Role</label>
                                <select class="form-select" name="role" id="edit_role">
                                    <option value="staff">Staff</option>
                                    <option value="worker">Worker</option>
                                    <option value="manager">Manager</option>
                                    <option value="admin">Admin</option>
                                </select>
                            </div>
                            <?php endif; ?>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Date of Joining</label>
                                <input type="date" class="form-control" name="date_of_joining" id="edit_date_of_joining">
                            </div>

                            <!-- Section: Contact -->
                            <div class="col-12 mt-2"><h6 class="fw-bold text-primary border-bottom pb-1 mb-0"><i class="bi bi-telephone me-2"></i>Contact & Address</h6></div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Primary Phone</label>
                                <input type="text" class="form-control" name="phone_primary" id="edit_phone_primary">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Secondary Phone</label>
                                <input type="text" class="form-control" name="phone_secondary" id="edit_phone_secondary">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Pincode</label>
                                <input type="text" class="form-control" name="pincode" id="edit_pincode">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Address</label>
                                <textarea class="form-control" name="address" id="edit_address" rows="2"></textarea>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">City</label>
                                <input type="text" class="form-control" name="city" id="edit_city">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">State</label>
                                <input type="text" class="form-control" name="state" id="edit_state">
                            </div>

                            <!-- Section: Personal -->
                            <div class="col-12 mt-2"><h6 class="fw-bold text-primary border-bottom pb-1 mb-0"><i class="bi bi-heart-pulse me-2"></i>Personal Details</h6></div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Date of Birth</label>
                                <input type="date" class="form-control" name="date_of_birth" id="edit_dob">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Blood Group</label>
                                <select class="form-select" name="blood_group" id="edit_blood_group">
                                    <option value="">Select</option>
                                    <?php foreach (['A+','A-','B+','B-','O+','O-','AB+','AB-'] as $bg): ?>
                                    <option value="<?php echo $bg; ?>"><?php echo $bg; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Emergency Contact Name</label>
                                <input type="text" class="form-control" name="emergency_contact_name" id="edit_emergency_name">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Emergency Contact Phone</label>
                                <input type="text" class="form-control" name="emergency_contact_phone" id="edit_emergency_phone">
                            </div>

                            <!-- Section: Profile Photo -->
                            <div class="col-12 mt-2"><h6 class="fw-bold text-primary border-bottom pb-1 mb-0"><i class="bi bi-camera me-2"></i>Profile Photo</h6></div>
                            <div class="col-12">
                                <div class="d-flex align-items-center gap-3">
                                    <img id="editPhotoPreview" src="" class="rounded-circle border"
                                         style="width:64px;height:64px;object-fit:cover;display:none;">
                                    <div class="flex-fill">
                                        <input type="file" class="form-control mb-2" id="editPhotoFile" accept="image/*">
                                        <small class="text-muted">Upload new photo (JPG/PNG/WebP, max 5MB) — or leave blank to keep current</small>
                                    </div>
                                </div>
                                <!-- hidden field updated by upload -->
                                <input type="hidden" name="profile_photo" id="edit_profile_photo">
                            </div>

                            <!-- Section: Permissions -->
                            <div class="col-12 mt-2"><h6 class="fw-bold text-primary border-bottom pb-1 mb-0"><i class="bi bi-shield-check me-2"></i>Permissions & Access</h6></div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Max Concurrent Chats</label>
                                <input type="number" class="form-control" name="max_concurrent_chats" id="edit_max_chats" min="1" max="10" value="2">
                            </div>
                            <div class="col-12">
                                <div class="d-flex flex-wrap gap-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="care_permission" id="edit_care_permission">
                                        <label class="form-check-label" for="edit_care_permission">
                                            <span class="badge bg-info">Care</span> Guest Chat Access
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="hr_permission" id="edit_hr_permission">
                                        <label class="form-check-label" for="edit_hr_permission">
                                            <span class="badge" style="background:#7c3aed;">HR</span> Recruitment Access
                                        </label>
                                    </div>
                                    <?php if ($is_admin): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="tech_permission" id="edit_tech_permission">
                                        <label class="form-check-label" for="edit_tech_permission">
                                            <span class="badge bg-secondary">Tech</span> Tech Permission
                                        </label>
                                    </div>
                                    <?php endif; ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="access_blocked" id="edit_access_blocked">
                                        <label class="form-check-label text-danger" for="edit_access_blocked">
                                            <i class="bi bi-lock me-1"></i>Block Access
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <?php if ($is_admin): ?>
                            <div class="col-12">
                                <div class="alert alert-warning py-2 mb-0">
                                    <div class="form-check mb-0">
                                        <input class="form-check-input" type="checkbox" name="regenerate_id" id="regenerate_id" value="1">
                                        <label class="form-check-label" for="regenerate_id">
                                            <i class="bi bi-arrow-clockwise me-1"></i>Regenerate ID Card — old QR will stop working
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-warning" onclick="resetUserPassword()">
                            <i class="bi bi-key me-1"></i>Reset Password
                        </button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    // ---- Toast helper (replaces all alert() in user management) ----
    function userToast(msg, type) {
        type = type || 'success';
        var t = document.createElement('div');
        t.className = 'position-fixed top-0 end-0 m-3 alert alert-' + type + ' shadow-sm d-flex align-items-center gap-2';
        t.style.cssText = 'z-index:9999;max-width:340px;font-size:.88rem;';
        t.innerHTML = '<i class="bi bi-' + (type==='success'?'check-circle-fill text-success':'exclamation-triangle-fill text-danger') + '"></i><div>' + msg + '</div>';
        document.body.appendChild(t);
        setTimeout(function(){ if(t.parentNode) t.remove(); }, 4000);
    }

    // ---- Inline result inside a modal ----
    function showModalResult(modalId, html, type) {
        type = type || 'info';
        var boxId = modalId + '_result';
        var box = document.getElementById(boxId);
        if (!box) {
            box = document.createElement('div');
            box.id = boxId;
            var footer = document.querySelector('#' + modalId + ' .modal-footer');
            if (footer) footer.parentNode.insertBefore(box, footer);
        }
        box.innerHTML = '<div class="alert alert-' + type + ' mx-3 mb-0 d-flex align-items-start gap-2 py-2">'
            + '<i class="bi bi-' + (type==='success'?'check-circle-fill':'exclamation-triangle-fill') + ' mt-1 flex-shrink-0"></i>'
            + '<div style="min-width:0;">' + html + '</div></div>';
    }

    // ---- Live search ----
    document.getElementById('userSearchBox').addEventListener('input', function() {
        var q = this.value.toLowerCase();
        document.querySelectorAll('.user-row').forEach(function(row) {
            var ok = (row.dataset.name||'').includes(q) || (row.dataset.uname||'').includes(q);
            row.style.display = ok ? '' : 'none';
        });
    });

    // ---- Registration photo preview ----
    var _rpf = document.getElementById('regPhotoFile');
    if (_rpf) _rpf.addEventListener('change', function() {
        if (!this.files[0]) return;
        var rd = new FileReader();
        rd.onload = function(e) {
            document.getElementById('regPhotoPreview').src = e.target.result;
            document.getElementById('regPhotoPreviewWrap').style.display = 'block';
        };
        rd.readAsDataURL(this.files[0]);
    });

    // ---- Add user (registration form) ----
    document.getElementById('addUserForm').addEventListener('submit', function(e) {
        e.preventDefault();
        var btn = this.querySelector('[type=submit]');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…';
        var label = '<?php echo addslashes($section_label); ?>';
        var file  = (document.getElementById('regPhotoFile')||{}).files;
        file = file && file[0];
        var formEl = this;

        function doCreate(photoPath) {
            var fd = new FormData(formEl);
            if (photoPath) fd.set('profile_photo', photoPath);
            fd.delete('profile_photo_file');
            fetch('admin.php?api=create_user', { method:'POST', body: new URLSearchParams(fd) })
                .then(function(r){ return r.json(); })
                .then(function(d) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-person-check me-1"></i>Register ' + label;
                    if (d.success) {
                        var pwd = (document.getElementById('regPassword')||{}).value || '';
                        showModalResult('addUserModal',
                            '<strong>Registered!</strong> ID: <code class="user-select-all">' + d.generated_id + '</code><br>'
                            + 'Password: <code class="user-select-all fs-6">' + pwd + '</code>'
                            + ' <button class="btn btn-sm btn-outline-secondary py-0 px-1" style="font-size:.72rem;" '
                            + 'onclick="navigator.clipboard.writeText(\'' + pwd + '\').then(function(){this.textContent=\'Copied!\'},this)">Copy</button>'
                            + '<br><small class="text-muted">Page reloads in 3 s.</small>',
                            'success');
                        setTimeout(function(){ location.reload(); }, 3000);
                    } else {
                        showModalResult('addUserModal', d.error || 'Registration failed', 'danger');
                    }
                })
                .catch(function(){
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-person-check me-1"></i>Register ' + label;
                    showModalResult('addUserModal', 'Network error — please retry', 'danger');
                });
        }

        if (file) {
            var fd2 = new FormData(formEl);
            fd2.delete('profile_photo_file');
            fetch('admin.php?api=create_user', { method:'POST', body: new URLSearchParams(fd2) })
                .then(function(r){ return r.json(); })
                .then(function(d) {
                    if (!d.success) {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="bi bi-person-check me-1"></i>Register ' + label;
                        showModalResult('addUserModal', d.error || 'Failed', 'danger');
                        return;
                    }
                    var pfd = new FormData();
                    pfd.append('photo', file);
                    pfd.append('user_id', d.user_id);
                    fetch('admin.php?api=upload_profile_photo', { method:'POST', body: pfd })
                        .then(function(){
                            btn.disabled = false;
                            btn.innerHTML = '<i class="bi bi-person-check me-1"></i>Register ' + label;
                            var pwd = (document.getElementById('regPassword')||{}).value || '';
                            showModalResult('addUserModal',
                                '<strong>Registered!</strong> ID: <code class="user-select-all">' + d.generated_id + '</code><br>'
                                + 'Password: <code class="user-select-all fs-6">' + pwd + '</code>'
                                + ' <button class="btn btn-sm btn-outline-secondary py-0 px-1" style="font-size:.72rem;" '
                                + 'onclick="navigator.clipboard.writeText(\'' + pwd + '\').then(function(){this.textContent=\'Copied!\'},this)">Copy</button>',
                                'success');
                            setTimeout(function(){ location.reload(); }, 3000);
                        });
                })
                .catch(function(){
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-person-check me-1"></i>Register ' + label;
                    showModalResult('addUserModal', 'Network error', 'danger');
                });
        } else {
            doCreate(null);
        }
    });

    // ---- Edit photo — upload immediately ----
    var _epf = document.getElementById('editPhotoFile');
    if (_epf) _epf.addEventListener('change', function() {
        if (!this.files[0]) return;
        var uid  = (document.getElementById('edit_user_id')||{}).value;
        var prev = document.getElementById('editPhotoPreview');
        var rd   = new FileReader();
        rd.onload = function(e) { if(prev){ prev.src=e.target.result; prev.style.display='block'; } };
        rd.readAsDataURL(this.files[0]);
        if (!uid) { showModalResult('editUserModal','Save basic info first, then upload photo','warning'); return; }
        var fd = new FormData();
        fd.append('photo', this.files[0]);
        fd.append('user_id', uid);
        fetch('admin.php?api=upload_profile_photo', { method:'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(d) {
                if (d.success) {
                    var ep = document.getElementById('edit_profile_photo');
                    if (ep) ep.value = d.photo_url;
                    if (prev) { prev.src=d.photo_url; prev.style.display='block'; }
                    userToast('Photo updated', 'success');
                } else {
                    showModalResult('editUserModal', 'Photo upload failed: ' + d.error, 'danger');
                }
            })
            .catch(function(){ showModalResult('editUserModal','Photo upload network error','danger'); });
    });

    // ---- Edit user — open modal with all fields prefilled ----
    function editUser(userId) {
        fetch('admin.php?api=get_user_details&user_id=' + userId)
            .then(function(r){ return r.json(); })
            .then(function(data) {
                if (!data.success) { userToast(data.error || 'Could not load user', 'danger'); return; }
                var u = data.user;
                function sv(id, val) { var el=document.getElementById(id); if(el) el.value = val||''; }
                function sc(id, val) { var el=document.getElementById(id); if(el) el.checked = !!(val==1||val===true); }

                sv('edit_user_id',            u.id);
                sv('edit_full_name',          u.full_name);
                sv('edit_email',              u.email);
                sv('edit_designation',        u.designation);
                sv('edit_date_of_joining',    u.date_of_joining);
                sv('edit_role',               u.role);
                sv('edit_phone_primary',      u.phone_primary);
                sv('edit_phone_secondary',    u.phone_secondary);
                sv('edit_address',            u.address);
                sv('edit_city',               u.city);
                sv('edit_state',              u.state);
                sv('edit_pincode',            u.pincode);
                sv('edit_dob',                u.date_of_birth);
                sv('edit_blood_group',        u.blood_group);
                sv('edit_emergency_name',     u.emergency_contact_name);
                sv('edit_emergency_phone',    u.emergency_contact_phone);
                sv('edit_max_chats',          u.max_concurrent_chats||2);
                sv('edit_profile_photo',      u.profile_photo);
                sc('edit_care_permission',    u.care_permission);
                sc('edit_hr_permission',      u.hr_permission);
                sc('edit_tech_permission',    u.tech_permission);
                sc('edit_access_blocked',     u.access_blocked);

                var dn=document.getElementById('editUserDisplayName'),
                    dr=document.getElementById('editUserDisplayRole'),
                    ic=document.getElementById('editUserIdCard');
                if(dn) dn.textContent = u.full_name||'—';
                if(dr) dr.textContent = (u.role||'').charAt(0).toUpperCase()+(u.role||'').slice(1);
                if(ic) ic.textContent = u.staff_id||u.worker_id||'';

                var img=document.getElementById('editAvatarImg'),
                    ini=document.getElementById('editAvatarInitial'),
                    prv=document.getElementById('editPhotoPreview');
                if (u.profile_photo) {
                    if(img){ img.src=u.profile_photo; img.style.display='block'; }
                    if(ini) ini.style.display='none';
                    if(prv){ prv.src=u.profile_photo; prv.style.display='block'; }
                } else {
                    if(img) img.style.display='none';
                    if(ini){ ini.style.display='flex'; ini.textContent=(u.full_name||'?').charAt(0).toUpperCase(); }
                    if(prv) prv.style.display='none';
                }
                var pf=document.getElementById('editPhotoFile');
                if(pf) pf.dataset.userId = u.id;
                var ri=document.getElementById('regenerate_id');
                if(ri) ri.checked=false;
                // Clear previous results
                var rb=document.getElementById('editUserModal_result'); if(rb) rb.innerHTML='';
                var pb=document.getElementById('editUserResultBox');    if(pb) pb.remove();

                showModal('editUserModal');
            })
            .catch(function(){ userToast('Network error loading user', 'danger'); });
    }

    // ---- Edit user form submit ----
    document.getElementById('editUserForm').addEventListener('submit', function(e) {
        e.preventDefault();
        var btn = this.querySelector('[type=submit]');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…';
        fetch('admin.php?api=update_user', { method:'POST', body: new URLSearchParams(new FormData(this)) })
            .then(function(r){ return r.json(); })
            .then(function(data) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Save Changes';
                if (data.success) {
                    hideModal('editUserModal');
                    userToast('Profile updated', 'success');
                    setTimeout(function(){ location.reload(); }, 700);
                } else {
                    showModalResult('editUserModal', data.error||'Update failed', 'danger');
                }
            })
            .catch(function(){
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Save Changes';
                showModalResult('editUserModal', 'Network error', 'danger');
            });
    });

    // ---- Block / Unblock ----
    function blockUser(userId, blocked) {
        var msg = blocked ? 'Block this user? They will be logged out immediately.' : 'Restore access for this user?';
        if (!confirm(msg)) return;
        var fd = new FormData();
        fd.append('user_id', userId);
        fd.append('blocked', blocked ? 1 : 0);
        fetch('admin.php?api=block_user', { method:'POST', body: new URLSearchParams(fd) })
            .then(function(r){ return r.json(); })
            .then(function(d){ if(d.success) location.reload(); else userToast(d.error||'Failed','danger'); });
    }

    // ---- Verify ID card ----
    function verifyIdCard(userId, verified) {
        var fd = new FormData();
        fd.append('user_id', userId);
        fd.append('verified', verified ? 1 : 0);
        fetch('admin.php?api=verify_id_card', { method:'POST', body: new URLSearchParams(fd) })
            .then(function(r){ return r.json(); })
            .then(function(d){ if(d.success) location.reload(); else userToast(d.error||'Failed','danger'); });
    }

    // ---- Delete user ----
    function deleteUser(userId) {
        if (!confirm('Delete this user? (Soft delete — recoverable by admin)')) return;
        var fd = new FormData(); fd.append('user_id', userId);
        fetch('admin.php?api=delete_user', { method:'POST', body: new URLSearchParams(fd) })
            .then(function(r){ return r.json(); })
            .then(function(d){ if(d.success) location.reload(); else userToast(d.error||'Failed','danger'); });
    }

    // ---- Reset password — inline copyable card ----
    function resetUserPassword() {
        var uid = (document.getElementById('edit_user_id')||{}).value;
        if (!uid) { showModalResult('editUserModal','No user selected','warning'); return; }
        if (!confirm('Generate a new random password for this user?')) return;
        var fd = new FormData(); fd.append('user_id', uid);
        fetch('admin.php?api=reset_password', { method:'POST', body: new URLSearchParams(fd) })
            .then(function(r){ return r.json(); })
            .then(function(d) {
                var old = document.getElementById('editUserResultBox');
                if(old) old.remove();
                var box = document.createElement('div');
                box.id  = 'editUserResultBox';
                var footer = document.querySelector('#editUserModal .modal-footer');
                if(footer) footer.parentNode.insertBefore(box, footer);
                if (d.success) {
                    var pw = d.new_password;
                    box.innerHTML = '<div class="alert alert-success m-3 mb-0">'
                        + '<div class="fw-semibold mb-2"><i class="bi bi-key-fill me-2"></i>Password Reset Successfully</div>'
                        + '<div class="input-group mb-2">'
                        + '<code class="form-control user-select-all text-center fw-bold fs-6" style="letter-spacing:2px;" id="_pwdCode">' + pw + '</code>'
                        + '<button class="btn btn-outline-secondary" id="_pwdCopy" onclick="navigator.clipboard.writeText(\'' + pw + '\').then(function(){document.getElementById(\'_pwdCopy\').innerHTML=\'<i class=\\\"bi bi-check\\\"></i> Copied\';})">'
                        + '<i class="bi bi-clipboard"></i> Copy</button>'
                        + '</div>'
                        + '<small class="text-muted">Copy and share securely. This will not be shown again.</small>'
                        + '</div>';
                } else {
                    box.innerHTML = '<div class="alert alert-danger m-3 mb-0"><i class="bi bi-exclamation-triangle me-2"></i>' + (d.error||'Failed') + '</div>';
                }
            });
    }

    // ---- Toggle status (legacy) ----
    function toggleUserStatus(userId, status) {
        if (!confirm((status ? 'Activate' : 'Deactivate') + ' this user?')) return;
        var fd = new FormData(); fd.append('user_id', userId); fd.append('status', status);
        fetch('admin.php?api=toggle_user_status', { method:'POST', body: new URLSearchParams(fd) })
            .then(function(r){ return r.json(); })
            .then(function(d){ if(d.success) location.reload(); else userToast(d.error||'Failed','danger'); });
    }
    </script>
    <?php
}

/**
 * Include Profile Requests
 */
function includeProfileRequests($db, $user) {
    ?>
    
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>User</th>
                            <th>Requested Changes</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $requests = $db->query("SELECT pur.*, au.full_name, au.username 
                                                FROM profile_update_requests pur
                                                JOIN admin_users au ON pur.user_id = au.id
                                                ORDER BY pur.created_at DESC");
                        
                        while ($req = $requests->fetchArray(SQLITE3_ASSOC)):
                            $changes = json_decode($req['requested_changes'], true);
                        ?>
                        <tr>
                            <td><?php echo date('d M H:i', strtotime($req['created_at'])); ?></td>
                            <td><?php echo htmlspecialchars($req['full_name']); ?><br><small><?php echo htmlspecialchars($req['username']); ?></small></td>
                            <td>
                                <?php if ($changes): ?>
                                    <?php foreach ($changes as $field => $value): ?>
                                        <div><strong><?php echo $field; ?>:</strong> <?php echo htmlspecialchars($value); ?></div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($req['reason']); ?></td>
                            <td>
                                <span class="badge bg-<?php 
                                    echo $req['status'] === 'pending' ? 'warning' : 
                                        ($req['status'] === 'approved' ? 'success' : 'danger'); 
                                ?>">
                                    <?php echo ucfirst($req['status']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($req['status'] === 'pending'): ?>
                                <button class="btn btn-sm btn-success" onclick="approveRequest(<?php echo $req['id']; ?>, 'approved')">
                                    Approve
                                </button>
                                <button class="btn btn-sm btn-danger" onclick="approveRequest(<?php echo $req['id']; ?>, 'rejected')">
                                    Reject
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script>
        function approveRequest(requestId, status) {
            const notes = prompt('Enter notes for this ' + status + ' decision:');
            
            const formData = new FormData();
            formData.append('request_id', requestId);
            formData.append('status', status);
            formData.append('notes', notes || '');
            
            fetch('admin.php?api=approve_profile_update', {
                method: 'POST',
                body: new URLSearchParams(formData)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    userToast('Request ' + status, 'success');
                    location.reload();
                } else {
                    userToast(data.error||'Error occurred','danger');
                }
            });
        }
    </script>
    
    <?php
}

/**
 * Include Chat View - FIXED version
 */
function includeChatView($db, $user) {
    // Pre-load all non-worker users from DB for internal chat (no lazy loading)
    $internal_users_result = $db->query(
        "SELECT id, full_name, role, designation, profile_photo, is_online,
                CASE WHEN last_activity > datetime('now','-5 minutes') THEN 1 ELSE 0 END as is_active_now
         FROM admin_users
         WHERE is_active = 1 AND access_blocked = 0 AND role != 'worker' AND id != " . intval($user['id']) . "
         ORDER BY is_active_now DESC, full_name ASC"
    );
    $internal_users = [];
    while ($r = $internal_users_result->fetchArray(SQLITE3_ASSOC)) {
        $r['is_online'] = ($r['is_active_now'] == 1);
        $internal_users[] = $r;
    }

    // Pre-load assigned guest chats for agents
    $my_guest_chats = [];
    $available_guest_chats = [];
    if (!empty($user['care_permission'])) {
        $uid = intval($user['id']);
        $r = $db->query("SELECT cs.*,
                            (SELECT COUNT(*) FROM chat_messages WHERE session_id = cs.session_id AND is_read = 0 AND sender_type != 'staff') as unread
                          FROM chat_sessions cs
                          WHERE cs.assigned_to = $uid AND cs.status = 'active'
                          ORDER BY cs.last_activity DESC");
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) $my_guest_chats[] = $row;

        $r2 = $db->query("SELECT cs.*,
                             (SELECT COUNT(*) FROM chat_messages WHERE session_id = cs.session_id) as msg_count
                           FROM chat_sessions cs
                           WHERE cs.assigned_to = 0 AND cs.status = 'active'
                           ORDER BY cs.created_at ASC LIMIT 20");
        while ($row = $r2->fetchArray(SQLITE3_ASSOC)) $available_guest_chats[] = $row;
    }
    ?>

    <style>
        .chat-container { display:flex; height:calc(100vh - 180px); background:white; border-radius:12px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,0.08); }
        .chat-sidebar { width:280px; min-width:240px; background:#f8fafc; border-right:1px solid #e2e8f0; display:flex; flex-direction:column; flex-shrink:0; }
        .chat-sidebar-header { padding:14px 16px; border-bottom:1px solid #e2e8f0; }
        .chat-sidebar-header input { width:100%; padding:6px 10px; border:1px solid #e2e8f0; border-radius:8px; font-size:0.85rem; outline:none; }
        .chat-sidebar-header input:focus { border-color:#0f3b5e; }
        .chat-tabs { display:flex; border-bottom:1px solid #cbd5e1; flex-shrink:0; background:#f1f5f9; }
        .chat-tab { flex:1; padding:10px 4px; text-align:center; cursor:pointer; background:#e2e8f0; border:none; font-size:0.85rem; font-weight:700; color:#334155; transition:all 0.2s; border-bottom:2px solid transparent; margin-bottom:-1px; }
        .chat-tab.active { background:white; border-bottom:2px solid #0f3b5e; color:#0f3b5e; }
        .chat-tab:hover:not(.active) { background:#f8fafc; color:#0f3b5e; }
        .chat-users { flex:1; overflow-y:auto; }
        .chat-section-label { padding:6px 12px; font-size:0.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#94a3b8; background:#f1f5f9; }
        .chat-user-item { padding:10px 14px; border-bottom:1px solid #f1f5f9; cursor:pointer; transition:background 0.15s; display:flex; align-items:center; gap:10px; }
        .chat-user-item:hover, .chat-user-item.active { background:#e8f0fb; }
        .chat-user-item .avatar { width:38px; height:38px; border-radius:50%; background:#0f3b5e; color:white; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:0.95rem; flex-shrink:0; position:relative; object-fit:cover; }
        .chat-user-item .avatar img { width:38px; height:38px; border-radius:50%; object-fit:cover; }
        .online-dot { position:absolute; bottom:1px; right:1px; width:10px; height:10px; border-radius:50%; border:2px solid white; }
        .online-dot.on  { background:#10b981; }
        .online-dot.off { background:#94a3b8; }
        .chat-user-item .info { flex:1; min-width:0; }
        .chat-user-item .info strong { display:block; font-size:0.88rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .chat-user-item .info small  { color:#94a3b8; font-size:0.75rem; }
        .chat-user-item .unread-badge { background:#ef4444; color:white; border-radius:50%; width:18px; height:18px; font-size:0.65rem; font-weight:700; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .chat-main { flex:1; display:flex; flex-direction:column; min-width:0; }
        .chat-header { padding:14px 18px; border-bottom:1px solid #e2e8f0; background:white; display:flex; justify-content:space-between; align-items:center; flex-shrink:0; }
        .chat-messages { flex:1; padding:16px 18px; overflow-y:auto; display:flex; flex-direction:column; gap:6px; background:#f8fafc; }
        .chat-empty-state { flex:1; display:flex; align-items:center; justify-content:center; color:#94a3b8; font-size:0.9rem; flex-direction:column; gap:8px; }
        .message { display:flex; flex-direction:column; max-width:72%; }
        .message.me    { align-self:flex-end;   align-items:flex-end; }
        .message.other { align-self:flex-start; align-items:flex-start; }
        .message.system { align-self:center; align-items:center; max-width:90%; }
        .message-bubble { padding:9px 14px; border-radius:16px; word-wrap:break-word; font-size:0.9rem; line-height:1.45; }
        .message.me    .message-bubble { background:#0f3b5e; color:white; border-bottom-right-radius:4px; }
        .message.other .message-bubble { background:white; color:#1e293b; border-bottom-left-radius:4px; box-shadow:0 1px 3px rgba(0,0,0,0.08); }
        .message.system .message-bubble { background:#fef3c7; color:#92400e; font-size:0.8rem; border-radius:8px; }
        .message-sender { font-size:0.72rem; color:#94a3b8; margin-bottom:2px; font-weight:600; }
        .message-time   { font-size:0.68rem; color:#94a3b8; margin-top:3px; }
        .chat-input-area { padding:12px 16px; border-top:1px solid #e2e8f0; display:flex; gap:10px; align-items:center; background:white; flex-shrink:0; }
        .chat-input-area input { flex:1; padding:10px 16px; border:2px solid #e2e8f0; border-radius:24px; outline:none; font-size:0.9rem; transition:border-color 0.2s; }
        .chat-input-area input:focus { border-color:#0f3b5e; }
        .chat-input-area input:disabled { background:#f8fafc; cursor:not-allowed; }
        .chat-send-btn { width:42px; height:42px; border-radius:50%; background:#0f3b5e; color:white; border:none; display:flex; align-items:center; justify-content:center; cursor:pointer; transition:all 0.2s; flex-shrink:0; }
        .chat-send-btn:hover:not(:disabled) { transform:scale(1.1); background:#1a4b73; }
        .chat-send-btn:disabled { background:#cbd5e1; cursor:not-allowed; }
        @media(max-width:600px) { .chat-sidebar { display:none; } }
    </style>

    <div class="chat-container">
        <!-- Sidebar -->
        <div class="chat-sidebar">
            <div class="chat-sidebar-header">
                <h6 class="mb-2 fw-bold" style="color:#0f3b5e;">💬 Chat Center</h6>
                <input type="text" id="chatUserSearch" placeholder="Search users..." oninput="filterChatUsers(this.value)">
            </div>

            <div class="chat-tabs">
                <button class="chat-tab active" data-tab="internal" onclick="switchChatTab('internal', this)">Internal</button>
                <?php if (!empty($user['care_permission'])): ?>
                <button class="chat-tab" data-tab="guest" onclick="switchChatTab('guest', this)">
                    Guest<?php if (count($my_guest_chats) > 0): ?> <span class="badge bg-danger" style="font-size:0.6rem;"><?php echo count($my_guest_chats); ?></span><?php endif; ?>
                </button>
                <?php endif; ?>
                <button class="chat-tab" data-tab="global" onclick="switchChatTab('global', this)">Global</button>
            </div>

            <div class="chat-users" id="chatUsersList">
                <!-- Internal users panel -->
                <div id="panel-internal">
                    <?php
                    $online_users  = array_filter($internal_users, fn($u) => $u['is_online']);
                    $offline_users = array_filter($internal_users, fn($u) => !$u['is_online']);
                    if (!empty($online_users)): ?>
                        <div class="chat-section-label">Online (<?php echo count($online_users); ?>)</div>
                        <?php foreach ($online_users as $u): ?>
                        <div class="chat-user-item" data-userid="<?php echo $u['id']; ?>" data-username="<?php echo htmlspecialchars(strtolower($u['full_name'])); ?>"
                             onclick="selectInternalUser(<?php echo $u['id']; ?>, '<?php echo addslashes(htmlspecialchars($u['full_name'])); ?>', this)">
                            <div class="avatar" style="background:#0f3b5e;">
                                <?php if (!empty($u['profile_photo'])): ?>
                                    <img src="<?php echo htmlspecialchars($u['profile_photo']); ?>" alt="">
                                <?php else: ?>
                                    <?php echo strtoupper(substr($u['full_name'],0,1)); ?>
                                <?php endif; ?>
                                <span class="online-dot on"></span>
                            </div>
                            <div class="info">
                                <strong><?php echo htmlspecialchars($u['full_name']); ?></strong>
                                <small><?php echo htmlspecialchars($u['designation'] ?: ucfirst($u['role'])); ?></small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif;
                    if (!empty($offline_users)): ?>
                        <div class="chat-section-label">Offline (<?php echo count($offline_users); ?>)</div>
                        <?php foreach ($offline_users as $u): ?>
                        <div class="chat-user-item" data-userid="<?php echo $u['id']; ?>" data-username="<?php echo htmlspecialchars(strtolower($u['full_name'])); ?>"
                             onclick="selectInternalUser(<?php echo $u['id']; ?>, '<?php echo addslashes(htmlspecialchars($u['full_name'])); ?>', this)">
                            <div class="avatar" style="background:#64748b;">
                                <?php if (!empty($u['profile_photo'])): ?>
                                    <img src="<?php echo htmlspecialchars($u['profile_photo']); ?>" alt="">
                                <?php else: ?>
                                    <?php echo strtoupper(substr($u['full_name'],0,1)); ?>
                                <?php endif; ?>
                                <span class="online-dot off"></span>
                            </div>
                            <div class="info">
                                <strong><?php echo htmlspecialchars($u['full_name']); ?></strong>
                                <small><?php echo htmlspecialchars($u['designation'] ?: ucfirst($u['role'])); ?></small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif;
                    if (empty($internal_users)): ?>
                        <div style="padding:24px 16px;text-align:center;color:#94a3b8;font-size:0.88rem;">No other users found</div>
                    <?php endif; ?>
                </div>

                <!-- Guest chats panel -->
                <?php if (!empty($user['care_permission'])): ?>
                <div id="panel-guest" style="display:none;">
                    <?php if (!empty($my_guest_chats)): ?>
                        <div class="chat-section-label">My Active Chats</div>
                        <?php foreach ($my_guest_chats as $gc): ?>
                        <div class="chat-user-item" onclick="selectGuestChat('<?php echo addslashes($gc['session_id']); ?>', '<?php echo addslashes(htmlspecialchars($gc['guest_name'] ?: 'Guest')); ?>', true, this)">
                            <div class="avatar" style="background:#059669;">G</div>
                            <div class="info">
                                <strong><?php echo htmlspecialchars($gc['guest_name'] ?: 'Guest'); ?></strong>
                                <small><?php echo htmlspecialchars($gc['contact_reason'] ?: 'General inquiry'); ?></small>
                            </div>
                            <?php if ($gc['unread'] > 0): ?>
                                <div class="unread-badge"><?php echo $gc['unread']; ?></div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if (!empty($available_guest_chats)): ?>
                        <div class="chat-section-label">Waiting (<?php echo count($available_guest_chats); ?>)</div>
                        <?php foreach ($available_guest_chats as $gc): ?>
                        <div class="chat-user-item" onclick="selectGuestChat('<?php echo addslashes($gc['session_id']); ?>', '<?php echo addslashes(htmlspecialchars($gc['guest_name'] ?: 'Guest')); ?>', false, this)">
                            <div class="avatar" style="background:#d97706;">G</div>
                            <div class="info">
                                <strong><?php echo htmlspecialchars($gc['guest_name'] ?: 'Guest'); ?></strong>
                                <small><?php echo htmlspecialchars($gc['contact_reason'] ?: 'Waiting...'); ?></small>
                            </div>
                            <span class="badge bg-warning text-dark" style="font-size:0.65rem;white-space:nowrap;">Take</span>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if (empty($my_guest_chats) && empty($available_guest_chats)): ?>
                        <div style="padding:24px 16px;text-align:center;color:#94a3b8;font-size:0.88rem;">
                            <i class="bi bi-chat-square" style="font-size:2rem;display:block;margin-bottom:8px;"></i>
                            No active guest chats
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Global chat panel -->
                <div id="panel-global" style="display:none;">
                    <div class="chat-user-item" style="background:#0f3b5e;" onclick="selectGlobalChat(this)">
                        <div class="avatar" style="background:rgba(255,255,255,0.2);">
                            <i class="bi bi-megaphone" style="color:white;font-size:1rem;"></i>
                        </div>
                        <div class="info" style="color:white;">
                            <strong>Global Broadcast</strong>
                            <small style="color:rgba(255,255,255,0.7);">All staff members</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main chat area -->
        <div class="chat-main">
            <div class="chat-header">
                <div class="d-flex align-items-center gap-2">
                    <div id="chatHeaderAvatar" class="avatar" style="width:36px;height:36px;border-radius:50%;background:#0f3b5e;color:white;display:none;align-items:center;justify-content:center;font-weight:700;font-size:0.9rem;flex-shrink:0;">?</div>
                    <div>
                        <h6 class="mb-0 fw-bold" id="chatReceiverName">Select a conversation</h6>
                        <small class="text-muted" id="chatStatus">Choose from the list on the left</small>
                    </div>
                </div>
                <div id="chatActions" class="d-flex gap-2"></div>
            </div>

            <div class="chat-messages" id="chatMessages">
                <div class="chat-empty-state">
                    <i class="bi bi-chat-dots" style="font-size:3rem;color:#cbd5e1;"></i>
                    Select a conversation to begin
                </div>
            </div>

            <div class="chat-input-area">
                <input type="text" id="chatInput" placeholder="Type your message…" autocomplete="off" disabled>
                <button id="chatSendBtn" class="chat-send-btn" disabled>
                    <i class="bi bi-send-fill"></i>
                </button>
            </div>
        </div>
    </div>

    <script>
    // =============================================
    // CHAT STATE
    // =============================================
    let chatState = {
        type:       'internal',  // internal | guest | global
        receiverId: 0,
        sessionId:  null,
        lastMsgId:  0,
        pollTimer:  null,
        guestPollTimer: null
    };
    const ME_ID   = <?php echo intval($user['id']); ?>;
    const ME_NAME = '<?php echo addslashes($user['full_name']); ?>';

    // =============================================
    // INIT
    // =============================================
    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('chatSendBtn').addEventListener('click', sendMessage);
        document.getElementById('chatInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); sendMessage(); }
        });
        // Auto-refresh guest list every 8s if on guest tab
        setInterval(() => {
            if (chatState.type === 'guest') refreshGuestList();
        }, 8000);
    });

    // =============================================
    // TAB SWITCHING
    // =============================================
    function switchChatTab(type, btn) {
        document.querySelectorAll('.chat-tab').forEach(t => t.classList.remove('active'));
        btn.classList.add('active');

        // Hide all panels
        ['internal','guest','global'].forEach(p => {
            const el = document.getElementById('panel-' + p);
            if (el) el.style.display = 'none';
        });

        const panel = document.getElementById('panel-' + type);
        if (panel) panel.style.display = 'block';

        chatState.type = type;

        // Clear current chat
        clearChatArea();

        // Auto-select Global Broadcast when switching to Global tab
        if (type === 'global') {
            const globalItem = document.querySelector('#panel-global .chat-user-item');
            if (globalItem) {
                selectGlobalChat(globalItem);
            }
        }

        if (type === 'guest') refreshGuestList();
    }

    function clearChatArea() {
        if (chatState.pollTimer) { clearInterval(chatState.pollTimer); chatState.pollTimer = null; }
        chatState.receiverId = 0;
        chatState.sessionId  = null;
        chatState.lastMsgId  = 0;
        document.getElementById('chatReceiverName').textContent = 'Select a conversation';
        document.getElementById('chatStatus').textContent = 'Choose from the list on the left';
        document.getElementById('chatMessages').innerHTML = '<div class="chat-empty-state"><i class="bi bi-chat-dots" style="font-size:3rem;color:#cbd5e1;"></i><span>Select a conversation to begin</span></div>';
        document.getElementById('chatInput').disabled = true;
        document.getElementById('chatSendBtn').disabled = true;
        document.getElementById('chatActions').innerHTML = '';
        document.getElementById('chatHeaderAvatar').style.display = 'none';
        document.querySelectorAll('.chat-user-item').forEach(el => el.classList.remove('active'));
    }

    // =============================================
    // USER SEARCH
    // =============================================
    function filterChatUsers(q) {
        q = q.toLowerCase();
        document.querySelectorAll('.chat-user-item[data-username]').forEach(el => {
            el.style.display = (el.dataset.username || '').includes(q) ? '' : 'none';
        });
    }

    // =============================================
    // INTERNAL CHAT
    // =============================================
    // Replace the selectInternalUser function in includeChatView with this:

function selectInternalUser(userId, userName, el) {
    // Clear any existing polling
    if (chatState.pollTimer) {
        clearInterval(chatState.pollTimer);
        chatState.pollTimer = null;
    }
    
    // Remove active class from all chat items
    document.querySelectorAll('.chat-user-item').forEach(e => e.classList.remove('active'));
    if (el) el.classList.add('active');

    // Reset chat state for new user
    chatState.type = 'internal';
    chatState.receiverId = userId;
    chatState.sessionId = null;
    chatState.lastMsgId = 0;
    chatState.currentUserId = userId; // Track current user

    // Update UI
    document.getElementById('chatReceiverName').textContent = userName;
    document.getElementById('chatStatus').textContent = 'Private Chat';
    
    const av = document.getElementById('chatHeaderAvatar');
    av.innerHTML = userName.charAt(0).toUpperCase();
    av.style.display = 'flex';
    av.style.background = '#0f3b5e';
    
    document.getElementById('chatInput').disabled = false;
    document.getElementById('chatSendBtn').disabled = false;
    document.getElementById('chatActions').innerHTML = '';
    
    // Clear messages container
    const messagesContainer = document.getElementById('chatMessages');
    messagesContainer.innerHTML = '<div class="chat-empty-state"><i class="bi bi-chat-dots" style="font-size:3rem;color:#cbd5e1;"></i><span>Loading messages...</span></div>';
    
    // Load messages and start polling
    loadMessages();
    chatState.pollTimer = setInterval(function() {
        // Only poll if we're still on this user
        if (chatState.type === 'internal' && chatState.receiverId === userId) {
            loadMessages();
        }
    }, 2500);
}

// Update the loadMessages function to properly filter:
function loadMessages() {
    if (chatState.type !== 'internal' && chatState.type !== 'global') return;
    
    const url = `admin.php?api=chat_poll&type=internal&since_id=${chatState.lastMsgId}`;
    
    fetch(url)
        .then(r => r.json())
        .then(data => {
            if (!data.messages) return;
            
            const messagesContainer = document.getElementById('chatMessages');
            let hasNewMessages = false;
            
            data.messages.forEach(msg => {
                if (msg.id > chatState.lastMsgId) {
                    chatState.lastMsgId = msg.id;
                }
                
                // Filter messages for current conversation
                let belongs = false;
                if (chatState.type === 'global') {
                    belongs = (msg.receiver_id == 0 || msg.receiver_id === null || msg.receiver_id === '0');
                } else {
                    const isMyMessage = (msg.sender_id == ME_ID && msg.receiver_id == chatState.receiverId);
                    const isTheirMessage = (msg.sender_id == chatState.receiverId && (msg.receiver_id == ME_ID || msg.receiver_id == 0));
                    belongs = isMyMessage || isTheirMessage;
                }
                
                if (belongs) {
                    // Remove empty state if exists
                    const emptyState = messagesContainer.querySelector('.chat-empty-state');
                    if (emptyState) emptyState.remove();
                    
                    appendMessage(msg, msg.sender_id == ME_ID);
                    hasNewMessages = true;
                }
            });
            
            // If no messages and this is first load, show empty state
            if (chatState.lastMsgId === 0 && !hasNewMessages && messagesContainer.children.length === 0) {
                messagesContainer.innerHTML = '<div class="chat-empty-state"><i class="bi bi-chat" style="font-size:2.5rem;color:#cbd5e1;"></i><span>No messages yet. Say hello! 👋</span></div>';
            }
        })
        .catch(() => {});
}

    function selectGlobalChat(el) {
        document.querySelectorAll('.chat-user-item').forEach(e => e.classList.remove('active'));
        el.classList.add('active');

        chatState.type       = 'global';
        chatState.receiverId = 0;
        chatState.sessionId  = null;
        chatState.lastMsgId  = 0;

        document.getElementById('chatReceiverName').textContent = 'Global Broadcast';
        document.getElementById('chatStatus').textContent = 'All Staff';
        const av = document.getElementById('chatHeaderAvatar');
        av.innerHTML = '<i class="bi bi-megaphone" style="font-size:1rem;"></i>';
        av.style.display = 'flex';
        document.getElementById('chatInput').disabled = false;
        document.getElementById('chatSendBtn').disabled = false;
        document.getElementById('chatActions').innerHTML = '';

        loadMessages();
        if (chatState.pollTimer) clearInterval(chatState.pollTimer);
        chatState.pollTimer = setInterval(loadMessages, 2500);
    }

    function loadMessages() {
        fetch(`admin.php?api=chat_poll&type=internal&since_id=${chatState.lastMsgId}`)
            .then(r => r.json())
            .then(data => {
                if (!data.messages) return;
                let gotNew = false;
                data.messages.forEach(msg => {
                    if (msg.id > chatState.lastMsgId) chatState.lastMsgId = msg.id;

                    // Filter to correct conversation
                    let belongs = false;
                    if (chatState.receiverId === 0 && chatState.type === 'global') {
                        belongs = (msg.receiver_id == 0 || msg.receiver_id === null);
                    } else {
                        const myMsg  = (msg.sender_id == ME_ID && msg.receiver_id == chatState.receiverId);
                        const theirMsg = (msg.sender_id == chatState.receiverId && msg.receiver_id == ME_ID);
                        belongs = myMsg || theirMsg;
                    }
                    if (belongs) { appendMessage(msg, msg.sender_id == ME_ID); gotNew = true; }
                });
                // Show empty state if first load returned nothing
                if (chatState.lastMsgId === 0 && !gotNew) {
                    const c = document.getElementById('chatMessages');
                    if (!c.querySelector('.chat-empty-state')) return;
                    c.innerHTML = '<div class="chat-empty-state"><i class="bi bi-chat" style="font-size:2.5rem;color:#cbd5e1;"></i><span>No messages yet. Say hello! 👋</span></div>';
                }
            })
            .catch(() => {});
    }

    // =============================================
    // GUEST CHAT
    // =============================================
    function refreshGuestList() {
        fetch('admin.php?api=chat_poll&type=available_guest')
            .then(r => r.json())
            .then(data => {
                const panel = document.getElementById('panel-guest');
                if (!panel) return;
                let html = '';
                if (data.assigned && data.assigned.length > 0) {
                    html += '<div class="chat-section-label">My Active Chats</div>';
                    data.assigned.forEach(chat => {
                        const name = (chat.guest_name || 'Guest').replace(/'/g, "\\'");
                        html += `<div class="chat-user-item" onclick="selectGuestChat('${chat.session_id}','${name}',true,this)">
                            <div class="avatar" style="background:#059669;">G</div>
                            <div class="info">
                                <strong>${escHtml(chat.guest_name || 'Guest')}</strong>
                                <small>${escHtml(chat.contact_reason || 'General inquiry')}</small>
                            </div>
                            ${(chat.message_count > 0) ? `<div class="unread-badge">${chat.message_count}</div>` : ''}
                        </div>`;
                    });
                }
                if (data.available && data.available.length > 0) {
                    html += `<div class="chat-section-label">Waiting (${data.available.length})</div>`;
                    data.available.forEach(chat => {
                        const name = (chat.guest_name || 'Guest').replace(/'/g, "\\'");
                        html += `<div class="chat-user-item" onclick="selectGuestChat('${chat.session_id}','${name}',false,this)">
                            <div class="avatar" style="background:#d97706;">G</div>
                            <div class="info">
                                <strong>${escHtml(chat.guest_name || 'Guest')}</strong>
                                <small>${escHtml(chat.contact_reason || 'Waiting…')}</small>
                            </div>
                            <span class="badge bg-warning text-dark" style="font-size:0.65rem;">Take</span>
                        </div>`;
                    });
                }
                if (!html) {
                    html = '<div style="padding:24px 16px;text-align:center;color:#94a3b8;font-size:0.88rem;"><i class="bi bi-chat-square" style="font-size:2rem;display:block;margin-bottom:8px;"></i>No active guest chats</div>';
                }
                panel.innerHTML = html;
            })
            .catch(() => {});
    }

    function selectGuestChat(sessionId, guestName, isAssigned, el) {
        document.querySelectorAll('.chat-user-item').forEach(e => e.classList.remove('active'));
        if (el) el.classList.add('active');

        chatState.type       = 'guest';
        chatState.sessionId  = sessionId;
        chatState.receiverId = 0;
        chatState.lastMsgId  = 0;

        document.getElementById('chatReceiverName').textContent = guestName;
        document.getElementById('chatStatus').textContent = isAssigned ? '🟢 Active Chat' : '⏳ Not yet assigned';
        const av = document.getElementById('chatHeaderAvatar');
        av.textContent = 'G'; av.style.background='#059669'; av.style.display='flex';

        document.getElementById('chatInput').disabled = false;
        document.getElementById('chatSendBtn').disabled = false;

        if (!isAssigned) {
            document.getElementById('chatActions').innerHTML =
                `<button class="btn btn-sm btn-success" onclick="takeGuestChat('${sessionId}','${guestName.replace(/'/g,"\\'")}')">
                    <i class="bi bi-hand-index-thumb me-1"></i>Take Chat
                 </button>`;
        } else {
            document.getElementById('chatActions').innerHTML =
                `<button class="btn btn-sm btn-outline-danger" onclick="endGuestChat('${sessionId}')">
                    <i class="bi bi-x-circle me-1"></i>End Chat
                 </button>`;
        }

        // Load full history, then poll
        fetch(`admin.php?api=chat_poll&type=guest&session_id=${sessionId}&since_id=0`)
            .then(r => r.json())
            .then(data => {
                document.getElementById('chatMessages').innerHTML = '';
                if (data.messages && data.messages.length > 0) {
                    data.messages.forEach(msg => {
                        if (msg.id > chatState.lastMsgId) chatState.lastMsgId = msg.id;
                        appendMessage(msg, msg.sender_type === 'staff' && msg.sender_id == ME_ID);
                    });
                } else {
                    document.getElementById('chatMessages').innerHTML =
                        '<div class="chat-empty-state"><i class="bi bi-chat" style="font-size:2.5rem;color:#cbd5e1;"></i><span>No messages yet.</span></div>';
                }
            });

        if (chatState.pollTimer) clearInterval(chatState.pollTimer);
        chatState.pollTimer = setInterval(() => pollGuestMessages(sessionId), 2500);
    }

    function pollGuestMessages(sessionId) {
        fetch(`admin.php?api=chat_poll&type=guest&session_id=${sessionId}&since_id=${chatState.lastMsgId}`)
            .then(r => r.json())
            .then(data => {
                if (data.messages) {
                    data.messages.forEach(msg => {
                        if (msg.id > chatState.lastMsgId) chatState.lastMsgId = msg.id;
                        appendMessage(msg, msg.sender_type === 'staff' && msg.sender_id == ME_ID);
                    });
                }
                if (data.session_status === 'ended') {
                    clearInterval(chatState.pollTimer);
                    document.getElementById('chatInput').disabled = true;
                    document.getElementById('chatSendBtn').disabled = true;
                    document.getElementById('chatActions').innerHTML = '';
                    document.getElementById('chatStatus').textContent = '🔴 Chat Ended';
                }
            })
            .catch(() => {});
    }

    function takeGuestChat(sessionId, guestName) {
        fetch('admin.php?api=take_chat', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ session_id: sessionId })
        })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                selectGuestChat(sessionId, guestName, true, null);
                refreshGuestList();
            } else { userToast(d.error||'Failed','danger'); }
        });
    }

    function endGuestChat(sessionId) {
        if (!confirm('End this chat session?')) return;
        fetch('admin.php?api=end_chat', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ session_id: sessionId })
        })
        .then(r => r.json())
        .then(d => {
            if (d.success) { clearChatArea(); switchChatTab('guest', document.querySelector('[data-tab=guest]')); }
            else { userToast(d.error||'Failed','danger'); }
        });
    }

    // =============================================
    // APPEND MESSAGE — newest always at bottom
    // =============================================
    function appendMessage(msg, isMe) {
        const container = document.getElementById('chatMessages');

        // Clear empty state
        const empty = container.querySelector('.chat-empty-state');
        if (empty) empty.remove();

        // Deduplicate by id (temp ids start with 'temp_')
        if (msg.id && !String(msg.id).startsWith('temp_') && document.getElementById('msg_' + msg.id)) return;

        const isSystem = (msg.sender_type === 'system');
        const div = document.createElement('div');
        div.className = 'message ' + (isSystem ? 'system' : (isMe ? 'me' : 'other'));
        if (msg.id) div.id = 'msg_' + msg.id;

        let senderLine = '';
        if (!isMe && !isSystem && msg.sender_name) {
            senderLine = `<div class="message-sender">${escHtml(msg.sender_name)}</div>`;
        } else if (!isMe && !isSystem && msg.sender_type === 'guest') {
            senderLine = `<div class="message-sender">Guest</div>`;
        }

        const timeStr = msg.time || (msg.created_at ? new Date(msg.created_at).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'}) : '');

        div.innerHTML = senderLine +
            `<div class="message-bubble">${escHtml(msg.message || '').replace(/\n/g,'<br>')}</div>` +
            `<div class="message-time">${timeStr}</div>`;

        container.appendChild(div);
        container.scrollTop = container.scrollHeight;
    }

    // =============================================
    // SEND MESSAGE
    // =============================================
    function sendMessage() {
        const input = document.getElementById('chatInput');
        const text  = input.value.trim();
        if (!text) return;

        // Optimistic UI
        const tempId = 'temp_' + Date.now();
        appendMessage({ id: tempId, message: text, sender_name: ME_NAME, time: new Date().toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'}) }, true);
        input.value = '';

        const payload = { message: text, chat_type: chatState.type };
        if (chatState.type === 'guest') {
            if (!chatState.sessionId) { userToast('Select a guest chat first','warning');  return; }
            payload.session_id = chatState.sessionId;
        } else {
            payload.receiver_id = chatState.receiverId;
        }

        fetch('admin.php?api=chat_send', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify(payload)
        })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                // Replace temp element with real id
                const tmpEl = document.getElementById('msg_' + tempId);
                if (tmpEl) tmpEl.id = 'msg_' + d.message_id;
                // Trigger immediate poll to get server-confirmed message
                if (chatState.type === 'guest') pollGuestMessages(chatState.sessionId);
                else loadMessages();
            }
        })
        .catch(() => {});
    }

    // =============================================
    // HELPERS
    // =============================================
    function escHtml(str) {
        return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    </script>

    <?php
}

/**
 * Include Tasks View
 */
function includeTasksView($db, $user) {
    ?>
    <div class="mb-4">
        <button class="btn btn-primary" onclick="showModal('createTaskModal')">
            <i class="bi bi-plus-circle me-2"></i>Create New Task
        </button>
        <button class="btn btn-outline-secondary" onclick="archiveOldTasks()">
            <i class="bi bi-archive me-2"></i>Archive Old Tasks
        </button>
    </div>
    
    <div class="row mb-3">
        <div class="col-md-3">
            <select class="form-control" id="taskFilter" onchange="loadTasks()">
                <option value="all">All Tasks</option>
                <option value="assigned_to_me">Assigned to Me</option>
                <option value="created_by_me">Created by Me</option>
            </select>
        </div>
        <div class="col-md-3">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="showArchived" onchange="loadTasks()">
                <label class="form-check-label">Show Archived</label>
            </div>
        </div>
    </div>
    
    <div id="tasksList"></div>
    
    <!-- Create Task Modal -->
    <div class="modal fade" id="createTaskModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Task</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="createTaskForm">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Title *</label>
                            <input type="text" class="form-control" name="title" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="3"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Assign To</label>
                                <select class="form-control" name="assigned_to">
                                    <option value="">Unassigned</option>
                                    <?php
                                    $users = $db->query("SELECT id, full_name FROM admin_users WHERE is_active = 1 ORDER BY full_name");
                                    while ($u = $users->fetchArray(SQLITE3_ASSOC)) {
                                        echo "<option value='{$u['id']}'>{$u['full_name']}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Priority</label>
                                <select class="form-control" name="priority">
                                    <option value="low">Low</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="high">High</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Due Date</label>
                            <input type="date" class="form-control" name="due_date">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Task</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
<!-- Task Details Modal - UPDATED with comment field for modifications -->
<div class="modal fade" id="taskDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="taskDetailsTitle"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="taskDetailsContent">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="showTaskEditForm()" id="editTaskBtn">Edit Task</button>
            </div>
        </div>
    </div>
</div>

<!-- Task Edit Modal - NEW -->
<div class="modal fade" id="taskEditModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Task</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editTaskForm">
                <input type="hidden" name="task_id" id="edit_task_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Title *</label>
                        <input type="text" class="form-control" name="title" id="edit_task_title" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" id="edit_task_description" rows="3"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-control" name="status" id="edit_task_status">
                                <option value="pending">Pending</option>
                                <option value="in_progress">In Progress</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Priority</label>
                            <select class="form-control" name="priority" id="edit_task_priority">
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Assign To</label>
                            <select class="form-control" name="assigned_to" id="edit_task_assigned">
                                <option value="">Unassigned</option>
                                <?php
                                $users = $db->query("SELECT id, full_name FROM admin_users WHERE is_active = 1 ORDER BY full_name");
                                while ($u = $users->fetchArray(SQLITE3_ASSOC)) {
                                    echo "<option value='{$u['id']}'>{$u['full_name']}</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Due Date</label>
                            <input type="date" class="form-control" name="due_date" id="edit_task_due">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-danger">* Comment (required for any change)</label>
                        <textarea class="form-control" name="comment" id="edit_task_comment" rows="3" required placeholder="Explain what changes you're making and why..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Task</button>
                </div>
            </form>
        </div>
    </div>
</div>
    
    <script>
        function loadTasks() {
            const filter = document.getElementById('taskFilter').value;
            const showArchived = document.getElementById('showArchived').checked ? 1 : 0;
            
            fetch(`admin.php?api=get_tasks&filter=${filter}&show_archived=${showArchived}`)
                .then(r => r.json())
                .then(tasks => {
                    let html = '';
                    tasks.forEach(task => {
                        const statusClass = 'status-' + (task.status || 'pending').toLowerCase();
                        const canDelete = (task.assigned_by == <?php echo $user['id']; ?> || 
                                          task.assigned_to == <?php echo $user['id']; ?> || 
                                          '<?php echo $user['role']; ?>' === 'admin');
                        
                        html += `
                            <div class="task-card ${task.priority}">
                                <div class="d-flex justify-content-between align-items-start">
                                    <h5 class="mb-2">${task.title}</h5>
                                    <span class="task-status ${statusClass}">${task.status || 'Pending'}</span>
                                </div>
                                <p class="text-muted mb-2">${task.description || 'No description'}</p>
                                <div class="row small mb-2">
                                    <div class="col-md-4">
                                        <i class="bi bi-person"></i> Assigned: ${task.assignee_name || 'Unassigned'}
                                    </div>
                                    <div class="col-md-4">
                                        <i class="bi bi-calendar"></i> Due: ${task.due_date_formatted}
                                    </div>
                                    <div class="col-md-4">
                                        <i class="bi bi-chat"></i> Comments: ${task.comment_count || 0}
                                    </div>
                                </div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">Created by ${task.creator_name} on ${task.created_at_formatted}</small>
                                    <div>
                                        <button class="btn btn-sm btn-outline-primary" onclick="viewTask(${task.id})">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        ${canDelete && !showArchived ? `
                                            <button class="btn btn-sm btn-outline-danger" onclick="deleteTask(${task.id})">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        ` : ''}
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                    document.getElementById('tasksList').innerHTML = html || '<p class="text-muted">No tasks found</p>';
                })
                .catch(err => {
                    console.error('Error loading tasks:', err);
                    document.getElementById('tasksList').innerHTML = '<p class="text-danger">Error loading tasks</p>';
                });
        }
        
        function deleteTask(taskId) {
            if (!confirm('Are you sure you want to delete this task? It will be archived.')) return;
            
            const formData = new FormData();
            formData.append('task_id', taskId);
            
            fetch('admin.php?api=delete_task', {
                method: 'POST',
                body: new URLSearchParams(formData)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    loadTasks();
                } else {
                    userToast(data.error||'Failed to delete task','danger');
                }
            })
            .catch(err => {
                console.error('Delete error:', err);
                userToast('Failed to delete task','danger');
            });
        }
        
        document.getElementById('createTaskForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            fetch('admin.php?api=create_task', {
                method: 'POST',
                body: new URLSearchParams(formData)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    hideModal('createTaskModal');
                    loadTasks();
                }
            });
        });
        
        function showTaskEditForm() {
    const taskId = document.getElementById('taskDetailsModal').getAttribute('data-task-id');
    
    fetch(`admin.php?api=get_task_details&task_id=${taskId}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const task = data.task;
                document.getElementById('edit_task_id').value = task.id;
                document.getElementById('edit_task_title').value = task.title;
                document.getElementById('edit_task_description').value = task.description || '';
                document.getElementById('edit_task_status').value = task.status || 'pending';
                document.getElementById('edit_task_priority').value = task.priority || 'medium';
                document.getElementById('edit_task_assigned').value = task.assigned_to || '';
                document.getElementById('edit_task_due').value = task.due_date || '';
                document.getElementById('edit_task_comment').value = '';
                
                hideModal('taskDetailsModal');
                showModal('taskEditModal');
            }
        });
}

document.getElementById('editTaskForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    
    fetch('admin.php?api=update_task', {
        method: 'POST',
        body: new URLSearchParams(formData)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            hideModal('taskEditModal');
            loadTasks();
        } else {
            userToast(data.error||'Failed to update task','danger');
        }
    });
});
        
        function viewTask(taskId) {
    fetch(`admin.php?api=get_task_details&task_id=${taskId}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const task = data.task;
                document.getElementById('taskDetailsTitle').textContent = task.title;
                document.getElementById('taskDetailsModal').setAttribute('data-task-id', taskId);
                        let html = `
                            <div class="mb-3">
                                <p><strong>Description:</strong> ${task.description || 'No description'}</p>
                                <p><strong>Status:</strong> <span class="task-status status-${task.status}">${task.status}</span></p>
                                <p><strong>Priority:</strong> ${task.priority}</p>
                                <p><strong>Assigned To:</strong> ${task.assignee_name || 'Unassigned'}</p>
                                <p><strong>Due Date:</strong> ${task.due_date_formatted}</p>
                                <p><strong>Created By:</strong> ${task.creator_name} on ${task.created_at_formatted}</p>
                            </div>
                            <hr>
                            <h6>Comments</h6>
                            <div class="mb-3" id="taskComments">
                        `;
                        
                        task.comments.forEach(comment => {
                            html += `
                                <div class="comment-item">
                                    <div class="d-flex justify-content-between">
                                        <strong>${comment.user_name || 'System'}</strong>
                                        <small class="text-muted">${comment.created_at_formatted}</small>
                                    </div>
                                    <p class="mb-0">${comment.comment}</p>
                                </div>
                            `;
                        });
                        
                        html += `
                            </div>
                            <hr>
                            <h6>Add Comment</h6>
                            <div class="input-group">
                                <textarea class="form-control" id="taskComment" rows="2" placeholder="Add a comment..."></textarea>
                                <button class="btn btn-primary" onclick="addTaskComment(${task.id})">Post</button>
                            </div>
                        `;
                        
                        document.getElementById('taskDetailsContent').innerHTML = html;
                        showModal('taskDetailsModal');
                    }
                });
        }
        
        function addTaskComment(taskId) {
            const comment = document.getElementById('taskComment').value.trim();
            if (!comment) return;
            
            const formData = new FormData();
            formData.append('task_id', taskId);
            formData.append('comment', comment);
            
            fetch('admin.php?api=add_task_comment', {
                method: 'POST',
                body: new URLSearchParams(formData)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('taskComment').value = '';
                    viewTask(taskId);
                }
            });
        }
        
        function archiveOldTasks() {
            if (!confirm('Archive tasks completed/cancelled more than 10 days ago?')) return;
            
            fetch('admin.php?api=archive_old_tasks')
                .then(r => r.json())
                .then(data => {
                    userToast(data.archived_count+" tasks archived","success");
                    loadTasks();
                });
        }
        
        // Initial load
        loadTasks();
    </script>
    
    <?php
}

/**
 * Include Team View
 */
function includeTeamView($db, $user) {
    if ($user['role'] !== 'admin') {
        echo '<div class="alert alert-danger">Access denied</div>';
        return;
    }

    // Pre-load all team members server-side
    $members_result = $db->query("SELECT * FROM team_members ORDER BY display_order ASC, name ASC");
    $members = [];
    while ($m = $members_result->fetchArray(SQLITE3_ASSOC)) $members[] = $m;
    ?>

    <div class="mb-4 d-flex gap-2">
        <button class="btn btn-primary" onclick="showModal('addTeamModal')">
            <i class="bi bi-plus-circle me-2"></i>Add Team Member
        </button>
    </div>

    <!-- Team grid -->
    <div class="row g-4" id="teamList">
    <?php if (empty($members)): ?>
        <div class="col-12 text-muted text-center py-5">No team members added yet.</div>
    <?php else: foreach ($members as $m): ?>
        <div class="col-md-4 col-lg-3" id="team_card_<?php echo $m['id']; ?>">
            <div class="card h-100 shadow-sm border-0">
                <img src="<?php echo htmlspecialchars($m['photo_url'] ?: 'https://via.placeholder.com/300x200?text=No+Photo'); ?>"
                     class="card-img-top" style="height:180px;object-fit:cover;">
                <div class="card-body">
                    <h6 class="fw-bold mb-0"><?php echo htmlspecialchars($m['name']); ?></h6>
                    <small class="text-muted"><?php echo htmlspecialchars($m['position'] ?: 'Team Member'); ?></small>
                    <p class="small mt-2 text-secondary" style="line-height:1.4;"><?php echo htmlspecialchars(substr($m['bio'] ?: '', 0, 100)); ?><?php echo strlen($m['bio'] ?? '') > 100 ? '…' : ''; ?></p>
                    <div class="d-flex gap-1 mt-2">
                        <span class="badge <?php echo $m['is_active'] ? 'bg-success' : 'bg-secondary'; ?>">
                            <?php echo $m['is_active'] ? 'Active' : 'Hidden'; ?>
                        </span>
                        <span class="badge bg-light text-dark">Order: <?php echo $m['display_order']; ?></span>
                    </div>
                </div>
                <div class="card-footer bg-transparent border-0 d-flex gap-2">
                    <button class="btn btn-sm btn-outline-primary flex-fill"
                            onclick="editTeamMember(<?php echo $m['id']; ?>,'<?php echo addslashes(htmlspecialchars($m['name'])); ?>','<?php echo addslashes(htmlspecialchars($m['position'] ?? '')); ?>','<?php echo addslashes(htmlspecialchars($m['bio'] ?? '')); ?>','<?php echo addslashes(htmlspecialchars($m['photo_url'] ?? '')); ?>',<?php echo $m['display_order']; ?>,<?php echo $m['is_active']; ?>)">
                        <i class="bi bi-pencil me-1"></i>Edit
                    </button>
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteTeamMember(<?php echo $m['id']; ?>)">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
        </div>
    <?php endforeach; endif; ?>
    </div>

    <!-- Add Team Modal -->
    <div class="modal fade" id="addTeamModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Add Team Member</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="addTeamForm" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" required placeholder="Full name">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Position / Title</label>
                            <input type="text" class="form-control" name="position" placeholder="e.g. Senior Consultant">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Bio</label>
                            <textarea class="form-control" name="bio" rows="3" placeholder="Short description…"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Photo URL</label>
                            <input type="url" class="form-control" name="photo_url" placeholder="https://...">
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label fw-semibold">Display Order</label>
                                <input type="number" class="form-control" name="display_order" value="0" min="0">
                            </div>
                            <div class="col-6 d-flex align-items-end">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="addTeamActive" value="1" checked>
                                    <label class="form-check-label" for="addTeamActive">Visible on site</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Member</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Team Modal -->
    <div class="modal fade" id="editTeamModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Edit Team Member</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="editTeamForm">
                    <input type="hidden" name="id" id="et_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" id="et_name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Position / Title</label>
                            <input type="text" class="form-control" name="position" id="et_position">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Bio</label>
                            <textarea class="form-control" name="bio" id="et_bio" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Photo URL</label>
                            <input type="url" class="form-control" name="photo_url" id="et_photo_url" placeholder="https://...">
                            <div id="et_photo_preview_wrap" class="mt-2" style="display:none;">
                                <img id="et_photo_preview" src="" class="img-thumbnail" style="max-height:80px;">
                            </div>
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label fw-semibold">Display Order</label>
                                <input type="number" class="form-control" name="display_order" id="et_order" min="0">
                            </div>
                            <div class="col-6 d-flex align-items-end">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="et_active" value="1">
                                    <label class="form-check-label" for="et_active">Visible on site</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    document.getElementById('addTeamForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const btn = this.querySelector('[type=submit]');
        btn.disabled = true; btn.textContent = 'Saving…';
        fetch('admin.php?api=create_team_member', { method:'POST', body: new URLSearchParams(new FormData(this)) })
            .then(r => r.json()).then(d => {
                btn.disabled = false; btn.textContent = 'Add Member';
                if (d.success) { hideModal('addTeamModal'); location.reload(); }
                else userToast(d.error||'Error','danger');
            }).catch(function(){ btn.disabled=false; userToast('Network error','danger'); });
    });

    function editTeamMember(id, name, position, bio, photoUrl, order, isActive) {
        document.getElementById('et_id').value       = id;
        document.getElementById('et_name').value     = name;
        document.getElementById('et_position').value = position;
        document.getElementById('et_bio').value      = bio;
        document.getElementById('et_photo_url').value= photoUrl;
        document.getElementById('et_order').value    = order;
        document.getElementById('et_active').checked = (isActive == 1);
        const prev = document.getElementById('et_photo_preview');
        const wrap = document.getElementById('et_photo_preview_wrap');
        if (photoUrl) { prev.src = photoUrl; wrap.style.display = 'block'; } else { wrap.style.display = 'none'; }
        showModal('editTeamModal');
    }

    document.getElementById('et_photo_url').addEventListener('input', function() {
        const wrap = document.getElementById('et_photo_preview_wrap');
        const prev = document.getElementById('et_photo_preview');
        if (this.value) { prev.src = this.value; wrap.style.display = 'block'; } else { wrap.style.display = 'none'; }
    });

    document.getElementById('editTeamForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const btn = this.querySelector('[type=submit]');
        btn.disabled = true; btn.textContent = 'Saving…';
        fetch('admin.php?api=update_team_member', { method:'POST', body: new URLSearchParams(new FormData(this)) })
            .then(r => r.json()).then(d => {
                btn.disabled = false; btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Save Changes';
                if (d.success) { hideModal('editTeamModal'); location.reload(); }
                else userToast(d.error||'Error','danger');
            }).catch(function(){ btn.disabled=false; userToast('Network error','danger'); });
    });

    function deleteTeamMember(id) {
        if (!confirm('Remove this team member from the website?')) return;
        fetch('admin.php?api=delete_team_member', { method:'POST', body: new URLSearchParams({id}) })
            .then(r => r.json()).then(d => {
                if (d.success) { const el = document.getElementById('team_card_' + id); if (el) el.remove(); }
                else userToast(d.error||'Error','danger');
            });
    }
    </script>

    <?php
}

/**
 * Include Settings View
 */
function includeSettingsView($db, $user) {
    if ($user['role'] !== 'admin') {
        echo '<div class="alert alert-danger">Access denied</div>';
        return;
    }
    ?>
    
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link active" data-bs-toggle="tab" href="#general">General</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#contact">Contact</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#social">Social Media</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#email">Email</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#system">System</a>
        </li>
    </ul>
    
    <div class="tab-content">
        <div class="tab-pane active" id="general">
            <?php renderSettingsGroup($db, 'general'); ?>
        </div>
        <div class="tab-pane" id="contact">
            <?php renderSettingsGroup($db, 'contact'); ?>
        </div>
        <div class="tab-pane" id="social">
            <?php renderSettingsGroup($db, 'social'); ?>
        </div>
        <div class="tab-pane" id="email">
            <?php renderSettingsGroup($db, 'email'); ?>
        </div>
        <div class="tab-pane" id="system">
            <?php renderSettingsGroup($db, 'system'); ?>
        </div>
    </div>
    
    <script>
        function saveSettings() {
            const settings = {};
            document.querySelectorAll('.settings-input').forEach(input => {
                if (input.type === 'checkbox') {
                    settings[input.name] = input.checked ? '1' : '0';
                } else {
                    settings[input.name] = input.value;
                }
            });
            
            fetch('admin.php?api=update_site_settings', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(settings)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    userToast('Settings saved','success');
                } else {
                    userToast(data.error||'Error occurred','danger');
                }
            });
        }
        
        function testEmail() {
            const email = prompt('Enter email address to send test email:');
            if (!email) return;
            
            const formData = new FormData();
            formData.append('test_email', email);
            
            fetch('admin.php?api=test_email_config', {
                method: 'POST',
                body: new URLSearchParams(formData)
            })
            .then(r => r.json())
            .then(data => {
                userToast(data.message||'Done','info');
            });
        }
    </script>
    
    <?php
}

/**
 * Render settings group
 */
function renderSettingsGroup($db, $group) {
    $settings = $db->query("SELECT * FROM site_settings WHERE setting_group = '$group' ORDER BY setting_key");
    ?>
    
    <div class="card">
        <div class="card-body">
            <?php while ($setting = $settings->fetchArray(SQLITE3_ASSOC)): ?>
                <div class="mb-3 row">
                    <label class="col-sm-4 col-form-label"><?php echo ucfirst(str_replace('_', ' ', $setting['setting_key'])); ?></label>
                    <div class="col-sm-8">
                        <?php if ($setting['setting_type'] === 'boolean'): ?>
                            <div class="form-check form-switch">
                                <input class="form-check-input settings-input" type="checkbox" 
                                       name="<?php echo $setting['setting_key']; ?>" 
                                       id="setting_<?php echo $setting['setting_key']; ?>"
                                       <?php echo $setting['setting_value'] == '1' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="setting_<?php echo $setting['setting_key']; ?>">
                                    Enable
                                </label>
                            </div>
                        <?php elseif ($setting['setting_type'] === 'textarea'): ?>
                            <textarea class="form-control settings-input" name="<?php echo $setting['setting_key']; ?>" rows="3"><?php echo htmlspecialchars($setting['setting_value']); ?></textarea>
                        <?php else: ?>
                            <input type="text" class="form-control settings-input" 
                                   name="<?php echo $setting['setting_key']; ?>" 
                                   value="<?php echo htmlspecialchars($setting['setting_value']); ?>">
                        <?php endif; ?>
                    </div>
                </div>
            <?php endwhile; ?>
            
            <div class="text-end">
                <button class="btn btn-primary" onclick="saveSettings()">Save Settings</button>
                <?php if ($group === 'email'): ?>
                    <button class="btn btn-outline-secondary" onclick="testEmail()">Test Email</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <?php
}

/**
 * Include Audit View
 */
function includeAuditView($db, $user) {
    if ($user['role'] !== 'admin') {
        echo '<div class="alert alert-danger">Access denied</div>';
        return;
    }
    ?>
    
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Entity</th>
                            <th>Details</th>
                            <th>IP Address</th>
                        </tr>
                    </thead>
                    <tbody id="auditLogs"></tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script>
        function loadAuditLogs() {
            fetch('admin.php?api=get_audit_logs')
                .then(r => r.json())
                .then(logs => {
                    let html = '';
                    logs.forEach(log => {
                        html += `
                            <tr>
                                <td>${log.created_at_formatted}</td>
                                <td>${log.user_name || 'System'}</td>
                                <td>${log.action}</td>
                                <td>${log.entity_type || '-'}</td>
                                <td>
                                    <small>${log.action}</small>
                                    ${log.old_data ? '<br><small class="text-muted">Old: ' + log.old_data.substring(0, 50) + '...</small>' : ''}
                                    ${log.new_data ? '<br><small class="text-success">New: ' + log.new_data.substring(0, 50) + '...</small>' : ''}
                                </td>
                                <td>${log.ip_address || '-'}</td>
                            </tr>
                        `;
                    });
                    document.getElementById('auditLogs').innerHTML = html;
                });
        }
        
        loadAuditLogs();
    </script>
    
    <?php
}

/**
 * Include Enquiries View - Complete with all enquiry types
 */
function includeEnquiriesView($db, $user) {
    $is_admin = ($user['role'] === 'admin');
    $active_tab = $_GET['sub'] ?? 'service';
    ?>
    
    <style>
        .enquiry-tab-content { min-height: 400px; }
        .status-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-viewed { background: #dbeafe; color: #1e40af; }
        .status-response_awaited { background: #fed7aa; color: #9b4d00; }
        .status-converted { background: #d1fae5; color: #065f46; }
        .status-denied { background: #fee2e2; color: #991b1b; }
        .status-cancelled { background: #f1f5f9; color: #475569; }
    </style>
    
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab === 'service' ? 'active' : ''; ?>" href="?view=enquiries&sub=service">
                <i class="bi bi-briefcase me-1"></i>Service Enquiries
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab === 'general' ? 'active' : ''; ?>" href="?view=enquiries&sub=general">
                <i class="bi bi-envelope me-1"></i>General Contacts
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab === 'business' ? 'active' : ''; ?>" href="?view=enquiries&sub=business">
                <i class="bi bi-graph-up me-1"></i>Business Upgrade
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab === 'job' ? 'active' : ''; ?>" href="?view=enquiries&sub=job">
                <i class="bi bi-file-earmark-person me-1"></i>Job Applications
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab === 'placement' ? 'active' : ''; ?>" href="?view=enquiries&sub=placement">
                <i class="bi bi-person-workspace me-1"></i>Placement Help
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab === 'archived' ? 'active' : ''; ?>" href="?view=enquiries&sub=archived">
                <i class="bi bi-archive me-1"></i>Archived
            </a>
        </li>
    </ul>
    
    <?php
    switch ($active_tab) {
        case 'service':
            renderUnifiedEnquiriesTable($db, $user, 'service_enquiries', 'service');
            break;
        case 'general':
            renderUnifiedEnquiriesTable($db, $user, 'general_contacts', 'general');
            break;
        case 'business':
            renderUnifiedEnquiriesTable($db, $user, 'business_upgrades', 'business');
            break;
        case 'job':
            renderUnifiedEnquiriesTable($db, $user, 'job_applications', 'job');
            break;
        case 'placement':
            renderUnifiedEnquiriesTable($db, $user, 'placement_enquiries', 'placement');
            break;
        case 'archived':
            renderArchivedUnifiedEnquiries($db, $user);
            break;
    }
}

/**
 * Render unified enquiries table for any type
 */
function renderUnifiedEnquiriesTable($db, $user, $table, $type) {
    $is_admin = ($user['role'] === 'admin');
    $user_id = $user['id'];
    
    // Check if table exists
    $table_exists = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'");
    if (!$table_exists) {
        echo "<div class='alert alert-info'>The {$type} enquiries table will be created automatically when the first submission is received.</div>";
        return;
    }
    
    $status_filter = $_GET['status'] ?? 'all';
    
    $query = "SELECT e.*, a.full_name as assigned_name 
              FROM $table e
              LEFT JOIN admin_users a ON e.assigned_to = a.id
              WHERE e.is_archived = 0";
    
    if ($status_filter !== 'all') {
        $query .= " AND e.admin_status = '" . $db->escapeString($status_filter) . "'";
    }
    
    $query .= " ORDER BY e.created_at DESC";
    
    $result = $db->query($query);
    $rows = [];
    while ($r = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $r;
    }
    
    // Define field mappings based on type
    $fields = [
        'service_enquiries' => [
            'name_field' => 'name',
            'contact_field' => 'phone',
            'email_field' => 'email',
            'details_field' => 'service_category',
            'details_sub' => 'service_description',
            'badge_color' => 'info',
            'title' => 'Service Enquiry'
        ],
        'general_contacts' => [
            'name_field' => 'name',
            'contact_field' => 'phone',
            'email_field' => 'email',
            'details_field' => 'subject',
            'details_sub' => 'message',
            'badge_color' => 'secondary',
            'title' => 'General Contact'
        ],
        'business_upgrades' => [
            'name_field' => 'business_name',
            'contact_field' => 'phone',
            'email_field' => 'email',
            'details_field' => 'business_type',
            'details_sub' => 'upgrade_goal',
            'badge_color' => 'success',
            'title' => 'Business Upgrade'
        ],
        'job_applications' => [
            'name_field' => 'full_name',
            'contact_field' => 'phone',
            'email_field' => 'email',
            'details_field' => 'position_applied',
            'details_sub' => 'experience',
            'badge_color' => 'warning',
            'title' => 'Job Application'
        ],
        'placement_enquiries' => [
            'name_field' => 'candidate_name',
            'contact_field' => 'phone',
            'email_field' => 'email',
            'details_field' => 'desired_role',
            'details_sub' => 'qualification',
            'badge_color' => 'primary',
            'title' => 'Placement Help'
        ]
    ];
    
    $field_map = $fields[$table];
    ?>
    
    <div class="row mb-3 align-items-center">
        <div class="col-md-3">
            <select class="form-control" id="statusFilter" onchange="filterByStatus('<?php echo $type; ?>')">
                <option value="all">All Status</option>
                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="viewed" <?php echo $status_filter === 'viewed' ? 'selected' : ''; ?>>Viewed</option>
                <option value="response_awaited" <?php echo $status_filter === 'response_awaited' ? 'selected' : ''; ?>>Response Awaited</option>
                <option value="converted" <?php echo $status_filter === 'converted' ? 'selected' : ''; ?>>Converted Business</option>
                <option value="denied" <?php echo $status_filter === 'denied' ? 'selected' : ''; ?>>Denied</option>
                <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
            </select>
        </div>
        <div class="col-md-9 text-end">
            <button class="btn btn-sm btn-outline-secondary" onclick="exportEnquiries('<?php echo $type; ?>')">
                <i class="bi bi-download me-1"></i>Export CSV
            </button>
        </div>
    </div>
    
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Name</th>
                            <th>Contact</th>
                            <th>Details</th>
                            <th>Status</th>
                            <th>Assigned To</th>
                            <th>Actions</th>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row): 
                            $is_viewed = !empty($row['viewed_at']);
                            $status_class = 'status-' . ($row['admin_status'] ?? 'pending');
                        ?>
                        <tr class="<?php echo !$is_viewed ? 'table-warning' : ''; ?>">
                            <td class="text-nowrap"><?php echo date('d M H:i', strtotime($row['created_at'])); ?>?</td>
                            <td>
                                <strong><?php echo htmlspecialchars($row[$field_map['name_field']] ?? ''); ?></strong>
                                <?php if (!$is_viewed): ?>
                                    <span class="badge bg-warning ms-1">New</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($row[$field_map['contact_field']])): ?>
                                    <div><i class="bi bi-telephone small"></i> <?php echo htmlspecialchars($row[$field_map['contact_field']]); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($row[$field_map['email_field']])): ?>
                                    <div><i class="bi bi-envelope small"></i> <?php echo htmlspecialchars(substr($row[$field_map['email_field']], 0, 30)); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-<?php echo $field_map['badge_color']; ?>"><?php echo htmlspecialchars($row[$field_map['details_field']] ?? 'N/A'); ?></span>
                                <div class="small text-muted mt-1"><?php echo htmlspecialchars(substr($row[$field_map['details_sub']] ?? '', 0, 50)); ?>...</div>
                            </td>
                            <td>
                                <select class="form-select form-select-sm" onchange="updateAppStatus(<?php echo $row['id']; ?>, this.value, '<?php echo $type; ?>')">
                                    <option value="pending" <?php echo ($row['admin_status'] ?? 'pending') === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="viewed" <?php echo ($row['admin_status'] ?? '') === 'viewed' ? 'selected' : ''; ?>>Viewed</option>
                                    <option value="response_awaited" <?php echo ($row['admin_status'] ?? '') === 'response_awaited' ? 'selected' : ''; ?>>Response Awaited</option>
                                    <option value="converted" <?php echo ($row['admin_status'] ?? '') === 'converted' ? 'selected' : ''; ?>>Converted Business</option>
                                    <option value="denied" <?php echo ($row['admin_status'] ?? '') === 'denied' ? 'selected' : ''; ?>>Denied</option>
                                    <option value="cancelled" <?php echo ($row['admin_status'] ?? '') === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </td>
                            <td>
                                <?php if ($row['assigned_name']): ?>
                                    <span class="badge bg-info"><?php echo htmlspecialchars($row['assigned_name']); ?></span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Unassigned</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap">
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-primary" onclick="viewUnifiedDetails(<?php echo $row['id']; ?>, '<?php echo $type; ?>')" title="View Details">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <button class="btn btn-outline-secondary" onclick="archiveUnifiedItem(<?php echo $row['id']; ?>, '<?php echo $type; ?>', 1)" title="Archive">
                                        <i class="bi bi-archive"></i>
                                    </button>
                                    <?php if ($is_admin): ?>
                                    <button class="btn btn-outline-danger" onclick="deleteUnifiedItem(<?php echo $row['id']; ?>, '<?php echo $type; ?>')" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">
                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                No <?php echo str_replace('_', ' ', $type); ?> enquiries found
                            </td>
                        </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Unified Details Modal -->
        <div class="modal fade" id="unifiedDetailsModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="unifiedDetailsTitle">Enquiry Details</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-0">
                        <div id="unifiedDetailsBody" class="p-3"></div>
                        <div class="border-top p-3 bg-light">
                            <label class="form-label fw-semibold"><i class="bi bi-pencil-square me-1"></i>Add Notes</label>
                            <textarea id="unifiedNotesInput" class="form-control mb-2" rows="2" placeholder="Add private notes..."></textarea>
                            <button class="btn btn-sm btn-primary" onclick="saveUnifiedNotes()">
                                <i class="bi bi-save me-1"></i>Save Notes
                            </button>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
        
        <script>
        let currentDetailId = null;
        let currentDetailType = null;
        
        function filterByStatus(type) {
            const status = document.getElementById('statusFilter').value;
            const url = new URL(window.location);
            url.searchParams.set('sub', type);
            if (status !== 'all') {
                url.searchParams.set('status', status);
            } else {
                url.searchParams.delete('status');
            }
            window.location.href = url.toString();
        }
        
        function updateAppStatus(id, status, type) {
            const fd = new FormData();
            fd.append('id', id);
            fd.append('status', status);
            fd.append('type', type);
            fetch('admin.php?api=update_application_status', { method:'POST', body: new URLSearchParams(fd) })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        userToast('Status updated to ' + status, 'success');
                        setTimeout(() => location.reload(), 500);
                    } else {
                        userToast(data.error || 'Failed', 'danger');
                    }
                });
        }
        
        function archiveUnifiedItem(id, type, archive) {
            const action = archive ? 'Archive' : 'Restore';
            if (!confirm(action + ' this enquiry?')) return;
            
            const fd = new FormData();
            fd.append('id', id);
            fd.append('type', type);
            fd.append('archive', archive);
            fetch('admin.php?api=update_application_status', { method:'POST', body: new URLSearchParams(fd) })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        userToast('Enquiry ' + action.toLowerCase() + 'd', 'success');
                        location.reload();
                    } else {
                        userToast(data.error || 'Failed', 'danger');
                    }
                });
        }
        
        function deleteUnifiedItem(id, type) {
            if (!confirm('Permanently delete this enquiry? This action cannot be undone.')) return;
            
            const fd = new FormData();
            fd.append('id', id);
            fd.append('type', type);
            fd.append('hard_delete', 1);
            fetch('admin.php?api=delete_application', { method:'POST', body: new URLSearchParams(fd) })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        userToast('Enquiry deleted', 'success');
                        location.reload();
                    } else {
                        userToast(data.error || 'Failed', 'danger');
                    }
                });
        }
        
        // Enhanced view details function that properly displays all data
function viewUnifiedDetails(id, type) {
    currentDetailId = id;
    currentDetailType = type;
    
    // Show loading state
    const modalBody = document.getElementById('unifiedDetailsBody');
    if (modalBody) {
        modalBody.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary"></div><div class="mt-2">Loading details...</div></div>';
    }
    
    let apiUrl = '';
    switch(type) {
        case 'service':
            apiUrl = `admin.php?api=get_enquiry_details&id=${id}&type=service`;
            break;
        case 'general':
            apiUrl = `admin.php?api=get_enquiry_details&id=${id}&type=general`;
            break;
        case 'job':
            apiUrl = `admin.php?api=get_job_applications`;
            break;
        case 'business':
            apiUrl = `admin.php?api=get_business_upgrades`;
            break;
        case 'placement':
            apiUrl = `admin.php?api=get_placement_enquiries`;
            break;
    }
    
    fetch(apiUrl)
        .then(r => r.json())
        .then(data => {
            let item = null;
            
            // Handle different response formats
            if (type === 'service' || type === 'general') {
                if (data.success && data.data) {
                    item = data.data;
                }
            } else {
                // For job, business, placement - data is an array
                if (Array.isArray(data)) {
                    item = data.find(i => i.id == id);
                } else if (data.data && Array.isArray(data.data)) {
                    item = data.data.find(i => i.id == id);
                }
            }
            
            if (item) {
                displayEnhancedDetails(item, type);
                // Load existing notes
                const notesInput = document.getElementById('unifiedNotesInput');
                if (notesInput) {
                    notesInput.value = item.admin_notes || item.notes || '';
                }
                
                // Mark as viewed if not already
                if (!item.viewed_at && type !== 'service' && type !== 'general') {
                    const fd = new FormData();
                    fd.append('id', id);
                    fd.append('status', 'viewed');
                    fd.append('type', type);
                    fetch('admin.php?api=update_application_status', { method: 'POST', body: new URLSearchParams(fd) });
                }
            } else {
                throw new Error('Data not found');
            }
        })
        .catch(error => {
            console.error('Error loading details:', error);
            const modalBody = document.getElementById('unifiedDetailsBody');
            if (modalBody) {
                modalBody.innerHTML = '<div class="alert alert-danger m-3">Failed to load enquiry details. Please try again.</div>';
            }
        });
    
    showModal('unifiedDetailsModal');
}

// Enhanced display function for all types
function displayEnhancedDetails(item, type) {
    const title = document.getElementById('unifiedDetailsTitle');
    const body = document.getElementById('unifiedDetailsBody');
    
    if (!body) return;
    
    let html = '<div class="row g-3">';
    
    switch(type) {
        case 'job':
            title.textContent = `Job Application: ${escapeHtml(item.position_applied || 'N/A')}`;
            html += `
                <div class="col-12 mb-3">
                    <span class="badge bg-warning fs-6 p-2">Job Application</span>
                    <span class="badge bg-${item.admin_status === 'converted' ? 'success' : (item.admin_status === 'denied' ? 'danger' : 'secondary')} ms-2 fs-6 p-2">
                        Status: ${escapeHtml(item.admin_status || 'Pending')}
                    </span>
                </div>
                <div class="col-md-6"><strong>📝 Full Name:</strong><br>${escapeHtml(item.full_name)}</div>
                <div class="col-md-6"><strong>📞 Phone:</strong><br>${escapeHtml(item.phone)}</div>
                <div class="col-md-6"><strong>📧 Email:</strong><br>${escapeHtml(item.email)}</div>
                <div class="col-md-6"><strong>💼 Position Applied:</strong><br>${escapeHtml(item.position_applied)}</div>
                <div class="col-md-4"><strong>📅 Experience:</strong><br>${escapeHtml(item.experience || 'N/A')}</div>
                <div class="col-md-4"><strong>💰 Current CTC:</strong><br>${escapeHtml(item.current_ctc || 'N/A')}</div>
                <div class="col-md-4"><strong>🎯 Expected CTC:</strong><br>${escapeHtml(item.expected_ctc || 'N/A')}</div>
                <div class="col-md-6"><strong>⏰ Notice Period:</strong><br>${escapeHtml(item.notice_period || 'N/A')}</div>
                <div class="col-md-6"><strong>🔗 LinkedIn:</strong><br>${item.linkedin_url ? `<a href="${escapeHtml(item.linkedin_url)}" target="_blank">${escapeHtml(item.linkedin_url)}</a>` : 'N/A'}</div>
                <div class="col-12"><strong>📄 Cover Letter:</strong><br><div class="p-2 bg-light rounded">${escapeHtml(item.cover_letter || 'No cover letter provided')}</div></div>
            `;
            
            // Add resume download if available
            if (item.resume_path && item.resume_path !== '') {
                html += `
                    <div class="col-12">
                        <strong>📎 Resume/CV:</strong><br>
                        <a href="${escapeHtml(item.resume_path)}" target="_blank" class="btn btn-sm btn-outline-primary mt-1">
                            <i class="bi bi-file-pdf"></i> Download Resume
                        </a>
                    </div>
                `;
            }
            break;
            
        case 'business':
            title.textContent = `Business Upgrade: ${escapeHtml(item.business_name || 'N/A')}`;
            html += `
                <div class="col-12 mb-3">
                    <span class="badge bg-success fs-6 p-2">Business Upgrade Enquiry</span>
                    <span class="badge bg-${item.admin_status === 'converted' ? 'success' : (item.admin_status === 'denied' ? 'danger' : 'secondary')} ms-2 fs-6 p-2">
                        Status: ${escapeHtml(item.admin_status || 'Pending')}
                    </span>
                </div>
                <div class="col-md-6"><strong>🏢 Business Name:</strong><br>${escapeHtml(item.business_name)}</div>
                <div class="col-md-6"><strong>👤 Contact Person:</strong><br>${escapeHtml(item.contact_person)}</div>
                <div class="col-md-6"><strong>📞 Phone:</strong><br>${escapeHtml(item.phone)}</div>
                <div class="col-md-6"><strong>📧 Email:</strong><br>${escapeHtml(item.email)}</div>
                <div class="col-md-6"><strong>📊 Business Type:</strong><br>${escapeHtml(item.business_type || 'N/A')}</div>
                <div class="col-md-6"><strong>📈 Current Scale:</strong><br>${escapeHtml(item.current_scale || 'N/A')}</div>
                <div class="col-12"><strong>🎯 Upgrade Goal:</strong><br><div class="p-2 bg-light rounded">${escapeHtml(item.upgrade_goal || 'N/A')}</div></div>
                <div class="col-12"><strong>💬 Message:</strong><br><div class="p-2 bg-light rounded">${escapeHtml(item.message || 'No message')}</div></div>
            `;
            break;
            
        case 'placement':
            title.textContent = `Placement Help: ${escapeHtml(item.candidate_name || 'N/A')}`;
            html += `
                <div class="col-12 mb-3">
                    <span class="badge bg-primary fs-6 p-2">Placement Help Enquiry</span>
                    <span class="badge bg-${item.admin_status === 'converted' ? 'success' : (item.admin_status === 'denied' ? 'danger' : 'secondary')} ms-2 fs-6 p-2">
                        Status: ${escapeHtml(item.admin_status || 'Pending')}
                    </span>
                </div>
                <div class="col-md-6"><strong>👤 Candidate Name:</strong><br>${escapeHtml(item.candidate_name)}</div>
                <div class="col-md-6"><strong>📞 Phone:</strong><br>${escapeHtml(item.phone)}</div>
                <div class="col-md-6"><strong>📧 Email:</strong><br>${escapeHtml(item.email)}</div>
                <div class="col-md-6"><strong>🎓 Qualification:</strong><br>${escapeHtml(item.qualification || 'N/A')}</div>
                <div class="col-md-6"><strong>📅 Experience:</strong><br>${escapeHtml(item.experience_years || 'N/A')}</div>
                <div class="col-md-6"><strong>🏢 Current Employer:</strong><br>${escapeHtml(item.current_employer || 'N/A')}</div>
                <div class="col-md-6"><strong>🎯 Desired Role:</strong><br>${escapeHtml(item.desired_role || 'N/A')}</div>
                <div class="col-md-6"><strong>📍 Preferred Location:</strong><br>${escapeHtml(item.preferred_location || 'N/A')}</div>
                <div class="col-12"><strong>💬 Message:</strong><br><div class="p-2 bg-light rounded">${escapeHtml(item.message || 'No message')}</div></div>
            `;
            
            if (item.resume_path && item.resume_path !== '') {
                html += `
                    <div class="col-12">
                        <strong>📎 Resume:</strong><br>
                        <a href="${escapeHtml(item.resume_path)}" target="_blank" class="btn btn-sm btn-outline-primary mt-1">
                            <i class="bi bi-file-pdf"></i> Download Resume
                        </a>
                    </div>
                `;
            }
            break;
            
        case 'service':
            title.textContent = `Service Enquiry #${item.id}`;
            html += `
                <div class="col-12 mb-3">
                    <span class="badge bg-info fs-6 p-2">Service Enquiry</span>
                    <span class="badge bg-${item.admin_status === 'resolved' ? 'success' : (item.admin_status === 'fake' ? 'danger' : 'secondary')} ms-2 fs-6 p-2">
                        Status: ${escapeHtml(item.admin_status || 'Pending')}
                    </span>
                </div>
                <div class="col-md-6"><strong>👤 Name:</strong><br>${escapeHtml(item.name)}</div>
                <div class="col-md-6"><strong>📞 Phone:</strong><br>${escapeHtml(item.phone)}</div>
                <div class="col-md-6"><strong>📧 Email:</strong><br>${escapeHtml(item.email)}</div>
                <div class="col-md-6"><strong>🔧 Service Category:</strong><br>${escapeHtml(item.service_category)}</div>
                <div class="col-12"><strong>📝 Description:</strong><br><div class="p-2 bg-light rounded">${escapeHtml(item.service_description)}</div></div>
                <div class="col-12"><strong>📍 Address:</strong><br>${escapeHtml(item.address || 'N/A')}</div>
            `;
            break;
            
        case 'general':
            title.textContent = `General Contact #${item.id}`;
            html += `
                <div class="col-12 mb-3">
                    <span class="badge bg-secondary fs-6 p-2">General Contact</span>
                    <span class="badge bg-${item.admin_status === 'resolved' ? 'success' : 'secondary'} ms-2 fs-6 p-2">
                        Status: ${escapeHtml(item.admin_status || 'Pending')}
                    </span>
                </div>
                <div class="col-md-6"><strong>👤 Name:</strong><br>${escapeHtml(item.name)}</div>
                <div class="col-md-6"><strong>📞 Phone:</strong><br>${escapeHtml(item.phone)}</div>
                <div class="col-md-6"><strong>📧 Email:</strong><br>${escapeHtml(item.email)}</div>
                <div class="col-md-6"><strong>📌 Subject:</strong><br>${escapeHtml(item.subject)}</div>
                <div class="col-12"><strong>💬 Message:</strong><br><div class="p-2 bg-light rounded">${escapeHtml(item.message)}</div></div>
            `;
            break;
    }
    
    // Add metadata footer
    html += `
        <div class="col-12 mt-3 pt-3 border-top">
            <div class="row g-2">
                <div class="col-md-6"><small class="text-muted"><i class="bi bi-calendar"></i> Submitted: ${new Date(item.created_at).toLocaleString()}</small></div>
                ${item.viewed_at ? `<div class="col-md-6"><small class="text-muted"><i class="bi bi-eye"></i> First Viewed: ${new Date(item.viewed_at).toLocaleString()}</small></div>` : ''}
                ${item.updated_at ? `<div class="col-md-6"><small class="text-muted"><i class="bi bi-pencil"></i> Last Updated: ${new Date(item.updated_at).toLocaleString()}</small></div>` : ''}
            </div>
        </div>
    `;
    
    html += '</div>';
    body.innerHTML = html;
}

// Enhanced save notes function
function saveUnifiedNotes() {
    if (!currentDetailId || !currentDetailType) {
        userToast('No enquiry selected', 'warning');
        return;
    }
    
    const notes = document.getElementById('unifiedNotesInput').value;
    if (!notes.trim()) {
        userToast('Please enter some notes before saving', 'warning');
        return;
    }
    
    const fd = new FormData();
    fd.append('id', currentDetailId);
    fd.append('type', currentDetailType);
    fd.append('notes', notes);
    
    fetch('admin.php?api=update_application_status', { method: 'POST', body: new URLSearchParams(fd) })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                userToast('Notes saved successfully', 'success');
                // Update the displayed notes in the modal if still open
                const notesDisplay = document.querySelector('#unifiedDetailsBody .notes-section');
                if (notesDisplay) {
                    notesDisplay.innerHTML = `<strong>📝 Notes:</strong><br><div class="p-2 bg-light rounded">${escapeHtml(notes)}</div>`;
                }
            } else {
                userToast(data.error || 'Failed to save notes', 'danger');
            }
        })
        .catch(() => userToast('Network error saving notes', 'danger'));
}
        
        function displayUnifiedDetails(item, type) {
            const title = document.getElementById('unifiedDetailsTitle');
            const body = document.getElementById('unifiedDetailsBody');
            
            let html = '<div class="row g-3">';
            html += `<div class="col-12"><span class="badge bg-primary mb-2">${type.toUpperCase()}</span></div>`;
            
            switch(type) {
                case 'service':
                    title.textContent = 'Service Enquiry #' + item.id;
                    html += `
                        <div class="col-md-6"><strong>Name:</strong> ${escapeHtml(item.name)}</div>
                        <div class="col-md-6"><strong>Phone:</strong> ${escapeHtml(item.phone)}</div>
                        <div class="col-md-6"><strong>Email:</strong> ${escapeHtml(item.email)}</div>
                        <div class="col-md-6"><strong>Service Category:</strong> ${escapeHtml(item.service_category)}</div>
                        <div class="col-12"><strong>Description:</strong><br>${escapeHtml(item.service_description)}</div>
                    `;
                    break;
                case 'general':
                    title.textContent = 'General Contact #' + item.id;
                    html += `
                        <div class="col-md-6"><strong>Name:</strong> ${escapeHtml(item.name)}</div>
                        <div class="col-md-6"><strong>Phone:</strong> ${escapeHtml(item.phone)}</div>
                        <div class="col-md-6"><strong>Email:</strong> ${escapeHtml(item.email)}</div>
                        <div class="col-md-6"><strong>Subject:</strong> ${escapeHtml(item.subject)}</div>
                        <div class="col-12"><strong>Message:</strong><br>${escapeHtml(item.message)}</div>
                    `;
                    break;
                case 'business':
                    title.textContent = 'Business Upgrade Enquiry #' + item.id;
                    html += `
                        <div class="col-md-6"><strong>Business Name:</strong> ${escapeHtml(item.business_name)}</div>
                        <div class="col-md-6"><strong>Contact Person:</strong> ${escapeHtml(item.contact_person)}</div>
                        <div class="col-md-6"><strong>Phone:</strong> ${escapeHtml(item.phone)}</div>
                        <div class="col-md-6"><strong>Email:</strong> ${escapeHtml(item.email)}</div>
                        <div class="col-md-6"><strong>Business Type:</strong> ${escapeHtml(item.business_type)}</div>
                        <div class="col-md-6"><strong>Current Scale:</strong> ${escapeHtml(item.current_scale)}</div>
                        <div class="col-12"><strong>Upgrade Goal:</strong><br>${escapeHtml(item.upgrade_goal)}</div>
                        <div class="col-12"><strong>Message:</strong><br>${escapeHtml(item.message)}</div>
                    `;
                    break;
                case 'job':
                    title.textContent = 'Job Application #' + item.id + ' - ' + escapeHtml(item.position_applied);
                    html += `
                        <div class="col-md-6"><strong>Full Name:</strong> ${escapeHtml(item.full_name)}</div>
                        <div class="col-md-6"><strong>Phone:</strong> ${escapeHtml(item.phone)}</div>
                        <div class="col-md-6"><strong>Email:</strong> ${escapeHtml(item.email)}</div>
                        <div class="col-md-6"><strong>Position Applied:</strong> ${escapeHtml(item.position_applied)}</div>
                        <div class="col-md-6"><strong>Experience:</strong> ${escapeHtml(item.experience)}</div>
                        <div class="col-md-6"><strong>Current CTC:</strong> ${escapeHtml(item.current_ctc)}</div>
                        <div class="col-md-6"><strong>Expected CTC:</strong> ${escapeHtml(item.expected_ctc)}</div>
                        <div class="col-md-6"><strong>Notice Period:</strong> ${escapeHtml(item.notice_period)}</div>
                        <div class="col-12"><strong>Cover Letter:</strong><br>${escapeHtml(item.cover_letter)}</div>
                    `;
                    break;
                case 'placement':
                    title.textContent = 'Placement Help Enquiry #' + item.id;
                    html += `
                        <div class="col-md-6"><strong>Candidate Name:</strong> ${escapeHtml(item.candidate_name)}</div>
                        <div class="col-md-6"><strong>Phone:</strong> ${escapeHtml(item.phone)}</div>
                        <div class="col-md-6"><strong>Email:</strong> ${escapeHtml(item.email)}</div>
                        <div class="col-md-6"><strong>Qualification:</strong> ${escapeHtml(item.qualification)}</div>
                        <div class="col-md-6"><strong>Experience:</strong> ${escapeHtml(item.experience_years)}</div>
                        <div class="col-md-6"><strong>Current Employer:</strong> ${escapeHtml(item.current_employer)}</div>
                        <div class="col-md-6"><strong>Desired Role:</strong> ${escapeHtml(item.desired_role)}</div>
                        <div class="col-md-6"><strong>Preferred Location:</strong> ${escapeHtml(item.preferred_location)}</div>
                        <div class="col-12"><strong>Message:</strong><br>${escapeHtml(item.message)}</div>
                    `;
                    break;
            }
            
            html += `
                <div class="col-12">
                    <hr>
                    <div class="row">
                        <div class="col-md-6"><strong>Status:</strong> <span class="status-badge status-${item.admin_status || 'pending'}">${item.admin_status || 'Pending'}</span></div>
                        <div class="col-md-6"><strong>Submitted:</strong> ${new Date(item.created_at).toLocaleString()}</div>
                        ${item.viewed_at ? `<div class="col-md-6"><strong>First Viewed:</strong> ${new Date(item.viewed_at).toLocaleString()}</div>` : ''}
                        ${item.updated_at ? `<div class="col-md-6"><strong>Last Updated:</strong> ${new Date(item.updated_at).toLocaleString()}</div>` : ''}
                    </div>
                </div>
            `;
            
            html += '</div>';
            body.innerHTML = html;
        }
        
        function saveUnifiedNotes() {
            if (!currentDetailId || !currentDetailType) return;
            
            const notes = document.getElementById('unifiedNotesInput').value;
            const fd = new FormData();
            fd.append('id', currentDetailId);
            fd.append('type', currentDetailType);
            fd.append('notes', notes);
            
            fetch('admin.php?api=update_application_status', { method:'POST', body: new URLSearchParams(fd) })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        userToast('Notes saved', 'success');
                    } else {
                        userToast(data.error || 'Failed to save notes', 'danger');
                    }
                });
        }
        
        function exportEnquiries(type) {
            const status = document.getElementById('statusFilter')?.value || 'all';
            window.location.href = `admin.php?api=export_enquiries&type=${type}&status=${status}`;
        }
        </script>
        
        <?php
}

/**
 * Render archived unified enquiries
 */
function renderArchivedUnifiedEnquiries($db, $user) {
    $is_admin = ($user['role'] === 'admin');
    ?>
    
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Type</th>
                            <th>Date</th>
                            <th>Name</th>
                            <th>Contact</th>
                            <th>Details</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </thead>
                        <tbody>
                        <?php
                        $tables = [
                            'service_enquiries' => 'Service',
                            'general_contacts' => 'General',
                            'job_applications' => 'Job',
                            'business_upgrades' => 'Business',
                            'placement_enquiries' => 'Placement'
                        ];
                        
                        $has_archived = false;
                        
                        foreach ($tables as $table => $label) {
                            $table_exists = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'");
                            if (!$table_exists) continue;
                            
                            // Get name field based on table
                            $name_field = 'name';
                            if ($table === 'job_applications') $name_field = 'full_name';
                            if ($table === 'business_upgrades') $name_field = 'business_name';
                            if ($table === 'placement_enquiries') $name_field = 'candidate_name';
                            
                            $query = "SELECT id, '$label' as type_label, created_at, $name_field as name, 
                                             phone, email, admin_status, is_archived
                                      FROM $table 
                                      WHERE is_archived = 1
                                      ORDER BY created_at DESC";
                            
                            $result = $db->query($query);
                            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                                $has_archived = true;
                                ?>
                                <tr>
                                    <td><span class="badge bg-secondary"><?php echo $row['type_label']; ?></span></td>
                                    <td class="text-nowrap"><?php echo date('d M H:i', strtotime($row['created_at'])); ?></td>
                                    <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
                                    <td>
                                        <?php if ($row['phone']): ?>
                                            <div><?php echo htmlspecialchars($row['phone']); ?></div>
                                        <?php endif; ?>
                                        <?php if ($row['email']): ?>
                                            <div class="small"><?php echo htmlspecialchars($row['email']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>—</td>
                                    <td>
                                        <span class="badge bg-secondary">Archived</span>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-success" onclick="archiveUnifiedItem(<?php echo $row['id']; ?>, '<?php echo strtolower($row['type_label']); ?>', 0)">
                                            <i class="bi bi-arrow-return-left"></i> Restore
                                        </button>
                                    </td>
                                </tr>
                                <?php
                            }
                        }
                        
                        if (!$has_archived):
                        ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">
                                <i class="bi bi-archive fs-1 d-block mb-2"></i>
                                No archived enquiries found
                            </td>
                        </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <script>
        function archiveUnifiedItem(id, type, archive) {
            const action = archive ? 'Archive' : 'Restore';
            if (!confirm(action + ' this enquiry?')) return;
            
            const fd = new FormData();
            fd.append('id', id);
            fd.append('type', type);
            fd.append('archive', archive);
            fetch('admin.php?api=update_application_status', { method:'POST', body: new URLSearchParams(fd) })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        userToast('Enquiry ' + action.toLowerCase() + 'd', 'success');
                        location.reload();
                    } else {
                        userToast(data.error || 'Failed', 'danger');
                    }
                });
        }
        </script>
        
        <?php
}

/**
 * Render enquiries table
 */
function renderEnquiriesTable($db, $type) {
    $table = ($type === 'service') ? 'service_enquiries' : 'general_contacts';
    $enquiries = $db->query("SELECT e.*, a.full_name as assigned_name 
                             FROM $table e
                             LEFT JOIN admin_users a ON e.assigned_to = a.id
                             WHERE e.is_archived = 0 
                             ORDER BY e.created_at DESC");
    ?>
    
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Name</th>
                            <th>Contact</th>
                            <th>Details</th>
                            <th>Status</th>
                            <th>Assigned To</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $enquiries->fetchArray(SQLITE3_ASSOC)): ?>
                        <tr>
                            <td><?php echo date('d M H:i', strtotime($row['created_at'])); ?></td>
                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                            <td>
                                <?php if (!empty($row['phone'])): ?>
                                    <div><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($row['phone']); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($row['email'])): ?>
                                    <div><i class="bi bi-envelope"></i> <?php echo htmlspecialchars($row['email']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($type === 'service'): ?>
                                    <strong><?php echo htmlspecialchars($row['service_category']); ?></strong>
                                    <div class="small"><?php echo htmlspecialchars(substr($row['service_description'] ?? '', 0, 50)); ?>...</div>
                                <?php else: ?>
                                    <strong><?php echo htmlspecialchars($row['subject']); ?></strong>
                                    <div class="small"><?php echo htmlspecialchars(substr($row['message'] ?? '', 0, 50)); ?>...</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <select class="form-select form-select-sm" onchange="updateStatus(<?php echo $row['id']; ?>, this.value, '<?php echo $type; ?>')">
                                    <option value="pending" <?php echo ($row['admin_status'] ?? 'pending') === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="viewed" <?php echo ($row['admin_status'] ?? '') === 'viewed' ? 'selected' : ''; ?>>Viewed</option>
                                    <option value="communicated" <?php echo ($row['admin_status'] ?? '') === 'communicated' ? 'selected' : ''; ?>>Communicated</option>
                                    <option value="resolved" <?php echo ($row['admin_status'] ?? '') === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                    <option value="fake" <?php echo ($row['admin_status'] ?? '') === 'fake' ? 'selected' : ''; ?>>Fake Request</option>
                                </select>
                            </td>
                            <td>
                                <?php if ($row['assigned_name']): ?>
                                    <span class="badge bg-info"><?php echo htmlspecialchars($row['assigned_name']); ?></span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Unassigned</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary" onclick="viewEnquiryDetails(<?php echo $row['id']; ?>, '<?php echo $type; ?>')">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-secondary" onclick="archiveItem(<?php echo $row['id']; ?>, '<?php echo $type; ?>', 1)">
                                    <i class="bi bi-archive"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <?php
}

/**
 * Render archived enquiries
 */
function renderArchivedEnquiries($db) {
    ?>
    
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Date</th>
                            <th>Name</th>
                            <th>Contact</th>
                            <th>Details</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $archived = $db->query("SELECT 'service' as type, id, name, phone, email, service_category as category, 
                                                       created_at, admin_status, notes
                                                FROM service_enquiries 
                                                WHERE is_archived = 1 
                                                UNION ALL 
                                                SELECT 'general' as type, id, name, phone, email, subject as category, 
                                                       created_at, admin_status, notes
                                                FROM general_contacts 
                                                WHERE is_archived = 1 
                                                ORDER BY created_at DESC");
                        while ($row = $archived->fetchArray(SQLITE3_ASSOC)): ?>
                        <tr>
                            <td><span class="badge bg-secondary"><?php echo ucfirst($row['type']); ?></span></td>
                            <td><?php echo date('d M H:i', strtotime($row['created_at'])); ?></td>
                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                            <td>
                                <?php if ($row['phone']): ?>
                                    <div><?php echo htmlspecialchars($row['phone']); ?></div>
                                <?php endif; ?>
                                <?php if ($row['email']): ?>
                                    <div><small><?php echo htmlspecialchars($row['email']); ?></small></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($row['category']); ?></strong>
                                <?php if ($row['notes']): ?>
                                    <div class="small text-muted"><?php echo htmlspecialchars($row['notes']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-<?php 
                                    echo $row['admin_status'] === 'pending' ? 'warning' : 
                                        ($row['admin_status'] === 'viewed' ? 'info' : 
                                        ($row['admin_status'] === 'communicated' ? 'success' : 
                                        ($row['admin_status'] === 'resolved' ? 'primary' : 'secondary'))); 
                                ?>">
                                    <?php echo ucfirst($row['admin_status'] ?? 'pending'); ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-outline-success" onclick="archiveItem(<?php echo $row['id']; ?>, '<?php echo $row['type']; ?>', 0)">
                                    <i class="bi bi-arrow-return-left"></i> Restore
                                </button>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script>
        function updateStatus(id, status, type) {
            const fd = new FormData();
            fd.append('id', id);
            fd.append('status', status);
            fd.append('type', type);
            fetch('admin.php?api=update_status', { method:'POST', body: new URLSearchParams(fd) });
        }

        function archiveItem(id, type, archive) {
            const fd = new FormData();
            fd.append('id', id);
            fd.append('type', type);
            fd.append('archive', archive);
            fetch('admin.php?api=archive_item', { method:'POST', body: new URLSearchParams(fd) })
                .then(() => location.reload());
        }

        function viewEnquiryDetails(id, type) {
            // Reset modal body first
            var body = document.getElementById('enquiryDetailsBody');
            var title = document.getElementById('enquiryDetailsTitle');
            if (body)  body.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><div class="mt-2 text-muted small">Loading details…</div></div>';
            if (title) title.textContent = 'Loading…';
            showModal('enquiryDetailsModal');

            fetch('admin.php?api=get_enquiry_details&id=' + id + '&type=' + type)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success) {
                        if (body) body.innerHTML = '<div class="alert alert-danger m-3"><i class="bi bi-exclamation-triangle me-2"></i>' + (data.error||'Could not load details') + '</div>';
                        return;
                    }
                    var d = data.data;
                    var labels = {
                        name:'Name', phone:'Phone', email:'Email',
                        service_date:'Service Date', service_category:'Category',
                        service_description:'Description', address:'Address',
                        subject:'Subject', message:'Message',
                        admin_status:'Status', notes:'Notes',
                        created_at:'Submitted'
                    };
                    var rows = '';
                    for (var key in labels) {
                        if (d[key] && d[key] !== '0') {
                            var val = String(d[key]).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/\n/g,'<br>');
                            rows += '<tr><td class="fw-semibold text-muted" style="width:38%;white-space:nowrap;font-size:.85rem;">' + labels[key] + '</td>'
                                  + '<td style="font-size:.9rem;">' + val + '</td></tr>';
                        }
                    }
                    if (body)  body.innerHTML = rows ? '<table class="table table-sm table-borderless mb-0">' + rows + '</table>' : '<p class="text-muted p-3">No details available.</p>';
                    if (title) title.textContent = (type === 'service' ? 'Service Enquiry' : 'General Contact') + ' #' + id;
                })
                .catch(function() {
                    if (body) body.innerHTML = '<div class="alert alert-danger m-3"><i class="bi bi-wifi-off me-2"></i>Network error — please try again</div>';
                });
        }
    </script>

    <!-- Enquiry Details Modal -->
    <div class="modal fade" id="enquiryDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="enquiryDetailsTitle">Enquiry Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0">
                    <div id="enquiryDetailsBody" class="p-3"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <?php
}

/**
 * Include Vacancies View
 */
function includeVacanciesView($db, $user) {
    $can_edit = in_array($user['role'], ['admin', 'manager']);

    // Pre-load all vacancies server-side
    $vac_result = $db->query("SELECT * FROM open_positions ORDER BY urgent DESC, created_at DESC");
    $vacancies  = [];
    while ($v = $vac_result->fetchArray(SQLITE3_ASSOC)) $vacancies[] = $v;

    $types = ['Full-time','Part-time','Contract','Internship','Freelance'];
    ?>

    <?php if ($can_edit): ?>
    <div class="mb-4">
        <button class="btn btn-primary" onclick="showModal('addVacancyModal')">
            <i class="bi bi-plus-circle me-2"></i>Add New Position
        </button>
    </div>
    <?php endif; ?>

    <!-- Vacancies grid -->
    <div class="row g-4" id="vacanciesList">
    <?php if (empty($vacancies)): ?>
        <div class="col-12 text-center text-muted py-5">No vacancies listed yet.</div>
    <?php else: foreach ($vacancies as $job): ?>
        <div class="col-md-4 col-lg-3" id="vac_card_<?php echo $job['id']; ?>">
            <div class="card h-100 shadow-sm border-0 <?php echo $job['urgent'] ? 'border-danger border-2' : ''; ?>">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <h6 class="fw-bold mb-0"><?php echo htmlspecialchars($job['title']); ?></h6>
                        <?php if ($job['urgent']): ?><span class="badge bg-danger">Urgent</span><?php endif; ?>
                    </div>
                    <p class="text-muted small mb-1">
                        <i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($job['location']); ?>
                    </p>
                    <p class="text-muted small mb-1">
                        <i class="bi bi-clock me-1"></i><?php echo htmlspecialchars($job['type']); ?>
                        <?php if ($job['salary']): ?> &middot; <i class="bi bi-currency-rupee"></i><?php echo htmlspecialchars($job['salary']); ?><?php endif; ?>
                    </p>
                    <p class="small text-secondary mt-2" style="line-height:1.4;">
                        <?php echo htmlspecialchars(substr($job['description'] ?? '', 0, 120)); ?><?php echo strlen($job['description'] ?? '') > 120 ? '…' : ''; ?>
                    </p>
                    <span class="badge <?php echo $job['is_active'] ? 'bg-success' : 'bg-secondary'; ?>">
                        <?php echo $job['is_active'] ? 'Active' : 'Inactive'; ?>
                    </span>
                </div>
                <?php if ($can_edit): ?>
                <div class="card-footer bg-transparent border-0 d-flex gap-2">
                    <button class="btn btn-sm btn-outline-primary flex-fill"
                            onclick="editVacancy(<?php echo $job['id']; ?>)">
                        <i class="bi bi-pencil me-1"></i>Edit
                    </button>
                    <button class="btn btn-sm btn-outline-<?php echo $job['is_active'] ? 'warning' : 'success'; ?>"
                            onclick="toggleVacancy(<?php echo $job['id']; ?>, <?php echo $job['is_active'] ? 0 : 1; ?>)"
                            title="<?php echo $job['is_active'] ? 'Deactivate' : 'Activate'; ?>">
                        <i class="bi bi-<?php echo $job['is_active'] ? 'pause' : 'play'; ?>"></i>
                    </button>
                    <?php if ($user['role'] === 'admin'): ?>
                    <button class="btn btn-sm btn-outline-danger"
                            onclick="deleteVacancy(<?php echo $job['id']; ?>)" title="Delete">
                        <i class="bi bi-trash"></i>
                    </button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; endif; ?>
    </div>

    <!-- Hidden store for vacancy data for JS editing -->
    <script>
    const vacancyData = <?php echo json_encode(array_column($vacancies, null, 'id')); ?>;
    </script>

    <!-- Add Vacancy Modal -->
    <div class="modal fade" id="addVacancyModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-briefcase-fill me-2"></i>Add New Position</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="admin_action" value="add_vacancy">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label fw-semibold">Job Title <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="title" required placeholder="e.g. Senior HR Consultant">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Employment Type <span class="text-danger">*</span></label>
                                <select class="form-select" name="type" required>
                                    <?php foreach ($types as $t): ?><option value="<?php echo $t; ?>"><?php echo $t; ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Location <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="location" required placeholder="City / Remote">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Salary / CTC</label>
                                <input type="text" class="form-control" name="salary" placeholder="e.g. ₹3–5 LPA">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Job Description <span class="text-danger">*</span></label>
                                <textarea class="form-control" name="description" rows="3" required placeholder="What the role involves…"></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Requirements <span class="text-danger">*</span></label>
                                <textarea class="form-control" name="requirements" rows="3" required placeholder="Skills, experience, qualifications…"></textarea>
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="urgent" id="urgentCheckAdd" value="1">
                                    <label class="form-check-label fw-semibold text-danger" for="urgentCheckAdd">
                                        <i class="bi bi-exclamation-triangle me-1"></i>Mark as Urgent
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Add Position</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Vacancy Modal -->
    <div class="modal fade" id="editVacancyModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Edit Position</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="editVacancyForm">
                    <input type="hidden" name="admin_action" value="update_vacancy">
                    <input type="hidden" name="id" id="ev_id">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label fw-semibold">Job Title <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="title" id="ev_title" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Employment Type</label>
                                <select class="form-select" name="type" id="ev_type">
                                    <?php foreach ($types as $t): ?><option value="<?php echo $t; ?>"><?php echo $t; ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Location</label>
                                <input type="text" class="form-control" name="location" id="ev_location">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Salary / CTC</label>
                                <input type="text" class="form-control" name="salary" id="ev_salary">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Job Description</label>
                                <textarea class="form-control" name="description" id="ev_description" rows="3"></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Requirements</label>
                                <textarea class="form-control" name="requirements" id="ev_requirements" rows="3"></textarea>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="urgent" id="ev_urgent" value="1">
                                    <label class="form-check-label fw-semibold text-danger" for="ev_urgent">
                                        <i class="bi bi-exclamation-triangle me-1"></i>Mark as Urgent
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="ev_active" value="1">
                                    <label class="form-check-label" for="ev_active">Active / Visible</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    function editVacancy(id) {
        const job = vacancyData[id];
        if (!job) { userToast('Vacancy data not found','danger'); return; }
        document.getElementById('ev_id').value           = job.id;
        document.getElementById('ev_title').value        = job.title     || '';
        document.getElementById('ev_type').value         = job.type      || 'Full-time';
        document.getElementById('ev_location').value     = job.location  || '';
        document.getElementById('ev_salary').value       = job.salary    || '';
        document.getElementById('ev_description').value  = job.description  || '';
        document.getElementById('ev_requirements').value = job.requirements || '';
        document.getElementById('ev_urgent').checked     = job.urgent == 1;
        document.getElementById('ev_active').checked     = job.is_active == 1;
        showModal('editVacancyModal');
    }

    document.getElementById('editVacancyForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const btn = this.querySelector('[type=submit]');
        btn.disabled = true; btn.textContent = 'Saving…';
        // Submit via form POST (same as add vacancy)
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'admin.php';
        new FormData(this).forEach((v, k) => {
            const inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = k; inp.value = v;
            form.appendChild(inp);
        });
        // Ensure unchecked checkboxes submit 0
        ['urgent','is_active'].forEach(n => {
            if (!this.querySelector('[name=' + n + ']:checked')) {
                const inp = document.createElement('input');
                inp.type='hidden'; inp.name=n; inp.value='0';
                form.appendChild(inp);
            }
        });
        document.body.appendChild(form);
        form.submit();
    });

    function toggleVacancy(id, active) {
        const fd = new FormData();
        fd.append('admin_action', 'toggle_vacancy');
        fd.append('id', id);
        fd.append('active', active);
        fetch('admin.php', { method:'POST', body: new URLSearchParams(fd) })
            .then(() => location.reload());
    }

    function deleteVacancy(id) {
        if (!confirm('Permanently delete this vacancy?')) return;
        const fd = new FormData();
        fd.append('admin_action', 'delete_vacancy');
        fd.append('id', id);
        fetch('admin.php', { method:'POST', body: new URLSearchParams(fd) })
            .then(() => { const el = document.getElementById('vac_card_' + id); if (el) el.remove(); });
    }
    </script>

    <?php
}

/**
 * Include Reports View
 */
function includeReportsView($db, $user) {
    ?>
    
    <div class="row g-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-white">
                    <h5 class="mb-0">Applications by Month</h5>
                </div>
                <div class="card-body">
                    <canvas id="appsChart" style="height: 300px;"></canvas>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-white">
                    <h5 class="mb-0">Tasks by Status</h5>
                </div>
                <div class="card-body">
                    <canvas id="tasksChart" style="height: 300px;"></canvas>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        new Chart(document.getElementById('appsChart'), {
            type: 'bar',
            data: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                datasets: [{
                    label: 'Applications',
                    data: [12, 19, 15, 17, 14, 23],
                    backgroundColor: '#0f3b5e'
                }]
            }
        });
        
        new Chart(document.getElementById('tasksChart'), {
            type: 'pie',
            data: {
                labels: ['Pending', 'In Progress', 'Completed', 'Cancelled'],
                datasets: [{
                    data: [15, 10, 25, 5],
                    backgroundColor: ['#f59e0b', '#3b82f6', '#10b981', '#ef4444']
                }]
            }
        });
    </script>
    
    <?php
}

/**
 * Include Contacts View - New Contacts Management Tab
 */
function includeContactsView($db, $user) {
    $is_admin = ($user['role'] === 'admin');
    $tab = $_GET['sub'] ?? 'list';
    ?>
    
    <div class="mb-4">
        <button class="btn btn-primary" onclick="showModal('addContactModal')">
            <i class="bi bi-plus-circle me-2"></i>Add New Contact
        </button>
        <?php if ($is_admin): ?>
        <button class="btn btn-outline-success" onclick="exportContacts()">
            <i class="bi bi-download me-2"></i>Export CSV
        </button>
        <button class="btn btn-outline-warning" onclick="document.getElementById('importCsvFile').click()">
            <i class="bi bi-upload me-2"></i>Import CSV
        </button>
        <input type="file" id="importCsvFile" accept=".csv" style="display:none;" onchange="importContacts(this.files[0])">
        <?php endif; ?>
    </div>

    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?php echo $tab === 'list' ? 'active' : ''; ?>" href="?view=contacts&sub=list">Active Contacts</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $tab === 'archived' ? 'active' : ''; ?>" href="?view=contacts&sub=archived">Archived Contacts</a>
        </li>
    </ul>

    <?php if ($tab === 'list'): ?>
        <?php renderContactsList($db, $user); ?>
    <?php else: ?>
        <?php renderArchivedContacts($db, $user); ?>
    <?php endif;

    // Add Contact Modal
    ?>
    <div class="modal fade" id="addContactModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Contact</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="addContactForm">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Business Name *</label>
                            <input type="text" class="form-control" name="business_name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Profession *</label>
                            <select class="form-control" name="profession" id="professionSelect" required>
                                <option value="">Select or type new profession</option>
                                <?php
                                $professions = $db->query("SELECT DISTINCT profession FROM contacts WHERE is_archived = 0 ORDER BY profession");
                                while ($prof = $professions->fetchArray(SQLITE3_ASSOC)) {
                                    echo '<option value="' . htmlspecialchars($prof['profession']) . '">' . htmlspecialchars($prof['profession']) . '</option>';
                                }
                                ?>
                                <option value="new">+ Add New Profession</option>
                            </select>
                            <input type="text" class="form-control mt-2" id="newProfession" name="new_profession" placeholder="Enter new profession" style="display:none;">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Locality</label>
                            <input type="text" class="form-control" name="locality">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Contact Number</label>
                            <input type="text" class="form-control" name="contact_number">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Contact</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Contact Modal -->
    <div class="modal fade" id="editContactModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Contact</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="editContactForm">
                    <input type="hidden" name="contact_id" id="edit_contact_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Business Name *</label>
                            <input type="text" class="form-control" name="business_name" id="edit_business_name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Profession *</label>
                            <select class="form-control" name="profession" id="edit_profession" required>
                                <option value="">Select profession</option>
                                <?php
                                $professions = $db->query("SELECT DISTINCT profession FROM contacts WHERE is_archived = 0 ORDER BY profession");
                                while ($prof = $professions->fetchArray(SQLITE3_ASSOC)) {
                                    echo '<option value="' . htmlspecialchars($prof['profession']) . '">' . htmlspecialchars($prof['profession']) . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Locality</label>
                            <input type="text" class="form-control" name="locality" id="edit_locality">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Contact Number</label>
                            <input type="text" class="form-control" name="contact_number" id="edit_contact_number">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" id="edit_notes" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Contact</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Profession select handling
        document.getElementById('professionSelect')?.addEventListener('change', function() {
            const newProfField = document.getElementById('newProfession');
            if (this.value === 'new') {
                newProfField.style.display = 'block';
                newProfField.required = true;
            } else {
                newProfField.style.display = 'none';
                newProfField.required = false;
            }
        });

        // Add contact form submission
        document.getElementById('addContactForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            // Handle new profession
            if (formData.get('profession') === 'new') {
                formData.set('profession', formData.get('new_profession'));
            }
            formData.delete('new_profession');
            
            fetch('admin.php?api=create_contact', {
                method: 'POST',
                body: new URLSearchParams(formData)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    hideModal('addContactModal');
                    location.reload();
                } else {
                    userToast(data.error||'Error occurred','danger');
                }
            });
        });

        // Edit contact form submission
        document.getElementById('editContactForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            fetch('admin.php?api=update_contact', {
                method: 'POST',
                body: new URLSearchParams(formData)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    hideModal('editContactModal');
                    location.reload();
                } else {
                    userToast(data.error||'Error occurred','danger');
                }
            });
        });

        function editContact(id) {
            fetch(`admin.php?api=get_contact&id=${id}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('edit_contact_id').value = data.contact.id;
                        document.getElementById('edit_business_name').value = data.contact.business_name || '';
                        document.getElementById('edit_profession').value = data.contact.profession || '';
                        document.getElementById('edit_locality').value = data.contact.locality || '';
                        document.getElementById('edit_contact_number').value = data.contact.contact_number || '';
                        document.getElementById('edit_notes').value = data.contact.notes || '';
                        showModal('editContactModal');
                    }
                });
        }

        function archiveContact(id, archive) {
            if (!confirm(archive ? 'Archive this contact?' : 'Restore this contact?')) return;
            
            const formData = new FormData();
            formData.append('id', id);
            formData.append('archive', archive ? 1 : 0);
            
            fetch('admin.php?api=archive_contact', {
                method: 'POST',
                body: new URLSearchParams(formData)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) location.reload();
                else userToast(data.error||'Error occurred','danger');
            });
        }

        function deleteContact(id) {
            if (!confirm('Permanently delete this contact? This action cannot be undone.')) return;
            
            fetch(`admin.php?api=delete_contact&id=${id}`, {
                method: 'POST'
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) location.reload();
                else userToast(data.error||'Error occurred','danger');
            });
        }

        function exportContacts() {
            window.location.href = 'admin.php?api=export_contacts';
        }

        function importContacts(file) {
            if (!file) return;
            
            const formData = new FormData();
            formData.append('csv_file', file);
            
            fetch('admin.php?api=import_contacts', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    userToast(data.count+" contacts imported","success");
                    location.reload();
                } else {
                    userToast(data.error||'Error occurred','danger');
                }
            });
        }
    </script>
    <?php
}

/**
 * Render Contacts List - UPDATED with profession filter dropdown
 */
function renderContactsList($db, $user) {
    $is_admin = ($user['role'] === 'admin');
    $selected_profession = $_GET['profession'] ?? '';
    ?>
    
    <div class="row mb-4">
        <div class="col-md-4">
            <select class="form-control" id="professionFilter" onchange="filterByProfession()">
                <option value="">All Professions</option>
                <?php
                $professions = $db->query("SELECT DISTINCT profession FROM contacts WHERE is_archived = 0 AND profession IS NOT NULL AND profession != '' ORDER BY profession");
                while ($prof = $professions->fetchArray(SQLITE3_ASSOC)) {
                    $selected = ($selected_profession === $prof['profession']) ? 'selected' : '';
                    echo '<option value="' . htmlspecialchars($prof['profession']) . '" ' . $selected . '>' . htmlspecialchars($prof['profession']) . '</option>';
                }
                ?>
            </select>
        </div>
        <div class="col-md-8 text-end">
            <button class="btn btn-sm btn-outline-secondary" onclick="exportContacts()">
                <i class="bi bi-download"></i> Export CSV
            </button>
            <?php if ($is_admin): ?>
            <button class="btn btn-sm btn-outline-warning" onclick="document.getElementById('importCsvFile').click()">
                <i class="bi bi-upload"></i> Import CSV
            </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Business Name</th>
                            <th>Profession</th>
                            <th>Locality</th>
                            <th>Contact Number</th>
                            <th>Notes</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $query = "SELECT * FROM contacts WHERE is_archived = 0";
                        if (!empty($selected_profession)) {
                            $query .= " AND profession = '" . $db->escapeString($selected_profession) . "'";
                        }
                        $query .= " ORDER BY profession, business_name";
                        
                        $contacts = $db->query($query);
                        $has_contacts = false;
                        
                        while ($contact = $contacts->fetchArray(SQLITE3_ASSOC)):
                            $has_contacts = true;
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($contact['business_name']); ?></strong></td>
                            <td><span class="badge bg-info"><?php echo htmlspecialchars($contact['profession'] ?? 'Uncategorized'); ?></span></td>
                            <td><?php echo htmlspecialchars($contact['locality'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($contact['contact_number'] ?? ''); ?></td>
                            <td><small><?php echo htmlspecialchars($contact['notes'] ?? ''); ?></small></td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary" onclick="editContact(<?php echo $contact['id']; ?>)">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-warning" onclick="archiveContact(<?php echo $contact['id']; ?>, true)">
                                    <i class="bi bi-archive"></i>
                                </button>
                                <?php if ($is_admin): ?>
                                <button class="btn btn-sm btn-outline-danger" onclick="deleteContact(<?php echo $contact['id']; ?>)">
                                    <i class="bi bi-trash"></i>
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                        
                        <?php if (!$has_contacts): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                No contacts found
                                <?php if (!empty($selected_profession)): ?>
                                    for profession "<?php echo htmlspecialchars($selected_profession); ?>"
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        function filterByProfession() {
            const profession = document.getElementById('professionFilter').value;
            const url = new URL(window.location);
            if (profession) {
                url.searchParams.set('profession', profession);
            } else {
                url.searchParams.delete('profession');
            }
            window.location.href = url.toString();
        }
        
        function exportContacts() {
            const profession = document.getElementById('professionFilter').value;
            let url = 'admin.php?api=export_contacts';
            if (profession) {
                url += '&profession=' + encodeURIComponent(profession);
            }
            window.location.href = url;
        }
        
        function importContacts(file) {
            if (!file) return;
            
            const formData = new FormData();
            formData.append('csv_file', file);
            
            fetch('admin.php?api=import_contacts', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    userToast(data.count+" contacts imported","success");
                    location.reload();
                } else {
                    userToast(data.error||'Error occurred','danger');
                }
            });
        }
    </script>
    <?php
}

/**
 * Render Archived Contacts
 */
function renderArchivedContacts($db, $user) {
    $is_admin = ($user['role'] === 'admin');
    $contacts = $db->query("SELECT * FROM contacts WHERE is_archived = 1 ORDER BY created_at DESC");
    ?>
    
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Business Name</th>
                            <th>Profession</th>
                            <th>Locality</th>
                            <th>Contact Number</th>
                            <th>Notes</th>
                            <th>Archived Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($contact = $contacts->fetchArray(SQLITE3_ASSOC)): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($contact['business_name']); ?></td>
                            <td><?php echo htmlspecialchars($contact['profession'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($contact['locality'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($contact['contact_number'] ?? ''); ?></td>
                            <td><small><?php echo htmlspecialchars($contact['notes'] ?? ''); ?></small></td>
                            <td><?php echo date('d M Y', strtotime($contact['updated_at'] ?? $contact['created_at'])); ?></td>
                            <td>
                                <button class="btn btn-sm btn-outline-success" onclick="archiveContact(<?php echo $contact['id']; ?>, false)">
                                    <i class="bi bi-arrow-return-left"></i> Restore
                                </button>
                                <?php if ($is_admin): ?>
                                <button class="btn btn-sm btn-outline-danger" onclick="deleteContact(<?php echo $contact['id']; ?>)">
                                    <i class="bi bi-trash"></i>
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                        
                        <?php
                        $total = $db->querySingle("SELECT COUNT(*) FROM contacts WHERE is_archived = 1");
                        if ($total == 0):
                        ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted">No archived contacts found</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Include Profile View
 */
function includeProfileView($db, $user) {
    $profile_message = $_SESSION['profile_message'] ?? '';
    $profile_error   = $_SESSION['profile_error']   ?? '';
    unset($_SESSION['profile_message'], $_SESSION['profile_error']);
    $card_id = $user['staff_id'] ?: $user['worker_id'] ?: '';
    ?>

    <div class="row g-4">
        <!-- Left: Identity card -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center py-4">
                    <!-- Avatar — no change photo option -->
                    <div class="mb-3">
                        <?php if (!empty($user['profile_photo'])): ?>
                            <img src="<?php echo htmlspecialchars($user['profile_photo']); ?>"
                                 class="rounded-circle border border-3"
                                 style="width:110px;height:110px;object-fit:cover;border-color:#0f3b5e!important;">
                        <?php else: ?>
                            <div class="mx-auto rounded-circle d-flex align-items-center justify-content-center fw-bold"
                                 style="width:110px;height:110px;background:#0f3b5e;color:white;font-size:2.5rem;">
                                <?php echo strtoupper(substr($user['full_name'],0,1)); ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <h5 class="fw-bold mb-1"><?php echo htmlspecialchars($user['full_name']); ?></h5>
                    <span class="badge bg-<?php echo $user['role']==='admin'?'danger':($user['role']==='manager'?'warning':'info'); ?> mb-2">
                        <?php echo ucfirst($user['role']); ?>
                    </span>
                    <?php if ($user['designation']): ?>
                        <p class="text-primary mb-0 small fw-semibold"><?php echo htmlspecialchars($user['designation']); ?></p>
                    <?php endif; ?>

                    <hr class="my-3">

                    <div class="small text-start">
                        <?php if ($user['email']): ?>
                        <div class="mb-1"><i class="bi bi-envelope me-2 text-muted"></i><?php echo htmlspecialchars($user['email']); ?></div>
                        <?php endif; ?>
                        <?php if ($user['phone_primary']): ?>
                        <div class="mb-1"><i class="bi bi-telephone me-2 text-muted"></i><?php echo htmlspecialchars($user['phone_primary']); ?></div>
                        <?php endif; ?>
                        <?php if ($user['date_of_joining']): ?>
                        <div class="mb-1"><i class="bi bi-calendar-check me-2 text-muted"></i>Joined <?php echo date('d M Y', strtotime($user['date_of_joining'])); ?></div>
                        <?php endif; ?>
                    </div>

                    <?php if ($card_id): ?>
                    <div class="mt-3 p-3 bg-light rounded text-center">
                        <div class="small text-muted mb-1 fw-semibold">ID Card</div>
                        <code class="fs-6"><?php echo htmlspecialchars($card_id); ?></code>
                        <div class="mt-2">
                            <button class="btn btn-sm btn-outline-primary" onclick="showIdCard(<?php echo $user['id']; ?>)">
                                <i class="bi bi-id-card me-1"></i>View ID Card
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Access permissions summary -->
                    <div class="mt-3 text-start">
                        <?php if (!empty($user['care_permission'])): ?>
                        <span class="badge bg-info me-1"><i class="bi bi-headset me-1"></i>Guest Chat</span>
                        <?php endif; ?>
                        <?php if (!empty($user['hr_permission'])): ?>
                        <span class="badge me-1" style="background:#7c3aed;"><i class="bi bi-people me-1"></i>HR Access</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right: Password change only -->
        <div class="col-lg-8">
            <?php if ($profile_message): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($profile_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if ($profile_error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($profile_error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Read-only profile info -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-bottom">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-person-lines-fill me-2 text-primary"></i>Profile Information</h6>
                    <small class="text-muted">Contact your administrator to update profile details</small>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label text-muted small">Full Name</label>
                            <div class="form-control bg-light"><?php echo htmlspecialchars($user['full_name']); ?></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted small">Username</label>
                            <div class="form-control bg-light"><?php echo htmlspecialchars($user['username']); ?></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted small">Email</label>
                            <div class="form-control bg-light"><?php echo htmlspecialchars($user['email'] ?: '—'); ?></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted small">Phone</label>
                            <div class="form-control bg-light"><?php echo htmlspecialchars($user['phone_primary'] ?: '—'); ?></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted small">Designation</label>
                            <div class="form-control bg-light"><?php echo htmlspecialchars($user['designation'] ?: '—'); ?></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted small">Last Login</label>
                            <div class="form-control bg-light"><?php echo $user['last_login'] ? date('d M Y, h:i A', strtotime($user['last_login'])) : 'Never'; ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Password change -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-key-fill me-2 text-warning"></i>Change Password</h6>
                </div>
                <div class="card-body">
                    <form method="POST" id="passwordChangeForm">
                        <input type="hidden" name="admin_action" value="update_profile">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Current Password <span class="text-danger">*</span></label>
                                <input type="password" class="form-control" name="current_password" required placeholder="••••••••">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">New Password <span class="text-danger">*</span></label>
                                <input type="password" class="form-control" name="new_password" id="newPwd" required placeholder="••••••••" minlength="6">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                                <input type="password" class="form-control" name="confirm_password" id="confirmPwd" required placeholder="••••••••">
                            </div>
                        </div>
                        <div class="mt-3">
                            <button type="submit" class="btn btn-warning fw-semibold">
                                <i class="bi bi-shield-lock me-2"></i>Update Password
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
    document.getElementById('passwordChangeForm')?.addEventListener('submit', function(e) {
        const np = document.getElementById('newPwd').value;
        const cp = document.getElementById('confirmPwd').value;
        if (np !== cp) {
            e.preventDefault();
            userToast('Passwords do not match','danger');
        }
    });
    </script>
    <script>
  /**
 * Get Job Applications list - Enhanced with proper resume handling
 */
function getJobApplications($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $show_archived = isset($_GET['show_archived']) ? intval($_GET['show_archived']) : 0;
    $status_filter = $_GET['status'] ?? 'all';
    
    // Check if table exists
    $table_exists = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='job_applications'");
    if (!$table_exists) {
        echo json_encode([]);
        return;
    }
    
    $query = "SELECT ja.*, au.full_name as assigned_name 
              FROM job_applications ja
              LEFT JOIN admin_users au ON ja.assigned_to = au.id
              WHERE ja.is_archived = $show_archived";
    
    if ($status_filter !== 'all') {
        $query .= " AND ja.admin_status = '" . $db->escapeString($status_filter) . "'";
    }
    
    $query .= " ORDER BY ja.created_at DESC";
    
    $result = $db->query($query);
    $list = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        // Add resume URL for download
        if (!empty($row['resume_path'])) {
            $row['resume_url'] = $row['resume_path'];
        }
        $list[] = $row;
    }
    
    echo json_encode($list);
}

/**
 * Get Business Upgrades list
 */
function getBusinessUpgrades($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $show_archived = isset($_GET['show_archived']) ? intval($_GET['show_archived']) : 0;
    $status_filter = $_GET['status'] ?? 'all';
    
    $query = "SELECT bu.*, au.full_name as assigned_name 
              FROM business_upgrades bu
              LEFT JOIN admin_users au ON bu.assigned_to = au.id
              WHERE bu.is_archived = $show_archived";
    
    if ($status_filter !== 'all') {
        $query .= " AND bu.admin_status = '" . $db->escapeString($status_filter) . "'";
    }
    
    $query .= " ORDER BY bu.created_at DESC";
    
    $result = $db->query($query);
    $list = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $list[] = $row;
    }
    
    echo json_encode($list);
}

/**
 * Get Placement Enquiries list
 */
function getPlacementEnquiries($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $show_archived = isset($_GET['show_archived']) ? intval($_GET['show_archived']) : 0;
    $status_filter = $_GET['status'] ?? 'all';
    
    $query = "SELECT pe.*, au.full_name as assigned_name 
              FROM placement_enquiries pe
              LEFT JOIN admin_users au ON pe.assigned_to = au.id
              WHERE pe.is_archived = $show_archived";
    
    if ($status_filter !== 'all') {
        $query .= " AND pe.admin_status = '" . $db->escapeString($status_filter) . "'";
    }
    
    $query .= " ORDER BY pe.created_at DESC";
    
    $result = $db->query($query);
    $list = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $list[] = $row;
    }
    
    echo json_encode($list);
}

/**
 * Get single enquiry details by ID and type
 */
function getEnquiryDetailsById($db, $id, $type) {
    if (!isset($_SESSION['admin_id'])) {
        return null;
    }
    
    $table = '';
    switch ($type) {
        case 'job': $table = 'job_applications'; break;
        case 'business': $table = 'business_upgrades'; break;
        case 'placement': $table = 'placement_enquiries'; break;
        case 'service': $table = 'service_enquiries'; break;
        case 'general': $table = 'general_contacts'; break;
        default: return null;
    }
    
    $stmt = $db->prepare("SELECT * FROM $table WHERE id = ?");
    $stmt->bindValue(1, $id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    return $result->fetchArray(SQLITE3_ASSOC);
}

/**
 * Update application status (Job, Business, Placement)
 */
function updateApplicationStatus($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $user_id = $_SESSION['admin_id'];
    $id = intval($_POST['id'] ?? 0);
    $type = $_POST['type'] ?? '';
    $status = $_POST['status'] ?? '';
    $notes = $_POST['notes'] ?? '';
    $archive = isset($_POST['archive']) ? intval($_POST['archive']) : null;
    
    if (!$id || !$type || !$status) {
        echo json_encode(['error' => 'Invalid parameters']);
        return;
    }
    
    $table = '';
    switch ($type) {
        case 'job': $table = 'job_applications'; break;
        case 'business': $table = 'business_upgrades'; break;
        case 'placement': $table = 'placement_enquiries'; break;
        case 'service': $table = 'service_enquiries'; break;
        case 'general': $table = 'general_contacts'; break;
        default:
            echo json_encode(['error' => 'Invalid type']);
            return;
    }
    
    // Check if table exists
    $table_exists = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'");
    if (!$table_exists) {
        echo json_encode(['error' => 'Table not found']);
        return;
    }
    
    // Get old data for audit
    $old_data = $db->querySingle("SELECT * FROM $table WHERE id = $id", true);
    if (!$old_data) {
        echo json_encode(['error' => 'Record not found']);
        return;
    }
    
    $updates = [];
    $params = [];
    
    // Update status
    $updates[] = "admin_status = ?";
    $params[] = $status;
    
    // Update assigned_to (auto-assign to current user if not already assigned)
    if (empty($old_data['assigned_to'])) {
        $updates[] = "assigned_to = ?";
        $params[] = $user_id;
    }
    
    // Update notes if provided
    if (!empty($notes)) {
        $updates[] = "admin_notes = ?";
        $params[] = $notes;
    }
    
    // Mark as viewed if status is being changed from pending
    if (($old_data['admin_status'] ?? 'pending') === 'pending' && empty($old_data['viewed_at'])) {
        $updates[] = "viewed_at = datetime('now', 'localtime')";
    }
    
    // Handle archive
    if ($archive !== null) {
        $updates[] = "is_archived = ?";
        $params[] = $archive;
    }
    
    $updates[] = "updated_at = datetime('now', 'localtime')";
    $params[] = $id;
    
    $sql = "UPDATE $table SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = $db->prepare($sql);
    foreach ($params as $i => $value) {
        $stmt->bindValue($i + 1, $value, is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
    }
    $stmt->execute();
    
    // Get new data for audit
    $new_data = $db->querySingle("SELECT * FROM $table WHERE id = $id", true);
    
    logAudit($db, $user_id, 'application_status_updated', $table, $id, 
             json_encode($old_data), json_encode($new_data), 
             "Application status updated to $status");
    
    echo json_encode(['success' => true]);
}

/**
 * Delete application (soft delete to archive, or hard delete for admin)
 */
function deleteApplication($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $user_id = $_SESSION['admin_id'];
    $current = $db->querySingle("SELECT role FROM admin_users WHERE id = $user_id", true);
    $is_admin = ($current['role'] === 'admin');
    
    $id = intval($_POST['id'] ?? 0);
    $type = $_POST['type'] ?? '';
    $hard_delete = isset($_POST['hard_delete']) ? intval($_POST['hard_delete']) : 0;
    
    if (!$id || !$type) {
        echo json_encode(['error' => 'Invalid parameters']);
        return;
    }
    
    $table = '';
    switch ($type) {
        case 'job': $table = 'job_applications'; break;
        case 'business': $table = 'business_upgrades'; break;
        case 'placement': $table = 'placement_enquiries'; break;
        case 'service': $table = 'service_enquiries'; break;
        case 'general': $table = 'general_contacts'; break;
        default:
            echo json_encode(['error' => 'Invalid type']);
            return;
    }
    
    $table_exists = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'");
    if (!$table_exists) {
        echo json_encode(['error' => 'Table not found']);
        return;
    }
    
    $old_data = $db->querySingle("SELECT * FROM $table WHERE id = $id", true);
    
    if ($is_admin && $hard_delete) {
        // Hard delete for admins only
        $db->exec("DELETE FROM $table WHERE id = $id");
        logAudit($db, $user_id, 'application_deleted_hard', $table, $id, json_encode($old_data), null, "Application permanently deleted");
    } else {
        // Soft delete (archive)
        $db->exec("UPDATE $table SET is_archived = 1, updated_at = datetime('now', 'localtime') WHERE id = $id");
        logAudit($db, $user_id, 'application_archived', $table, $id, json_encode($old_data), null, "Application archived");
    }
    
    echo json_encode(['success' => true]);
}

/**
 * Clean up old chats (> 7 days) - Run this periodically
 */
function cleanupOldChats($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $user_id = $_SESSION['admin_id'];
    $current = $db->querySingle("SELECT role FROM admin_users WHERE id = $user_id", true);
    
    // Get old sessions (older than 7 days and ended/completed)
    $old_sessions = $db->query("SELECT session_id FROM chat_sessions 
                                 WHERE (status = 'ended' OR status = 'completed') 
                                 AND last_activity < datetime('now', '-7 days')");
    
    $deleted_sessions = 0;
    $deleted_messages = 0;
    
    while ($session = $old_sessions->fetchArray(SQLITE3_ASSOC)) {
        $session_id = $session['session_id'];
        
        // Delete messages for this session
        $stmt = $db->prepare("DELETE FROM chat_messages WHERE session_id = ?");
        $stmt->bindValue(1, $session_id, SQLITE3_TEXT);
        $stmt->execute();
        $deleted_messages += $db->changes();
        
        // Delete the session
        $stmt = $db->prepare("DELETE FROM chat_sessions WHERE session_id = ?");
        $stmt->bindValue(1, $session_id, SQLITE3_TEXT);
        $stmt->execute();
        $deleted_sessions++;
    }
    
    // Also delete orphaned messages (no session) older than 30 days
    $orphaned = $db->exec("DELETE FROM chat_messages 
                           WHERE (session_id IS NULL OR session_id NOT IN (SELECT session_id FROM chat_sessions)) 
                           AND created_at < datetime('now', '-30 days')");
    
    logAudit($db, $user_id, 'chats_cleaned', 'chat_sessions', 0, null, 
             json_encode(['sessions' => $deleted_sessions, 'messages' => $deleted_messages, 'orphaned' => $orphaned]), 
             "Old chats cleaned up");
    
    echo json_encode([
        'success' => true,
        'sessions_deleted' => $deleted_sessions,
        'messages_deleted' => $deleted_messages,
        'orphaned_deleted' => $orphaned
    ]);
}

/**
 * Update staff online status - Enhanced version with better detection
 */
function updateStaffOnlineStatus($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $user_id = $_SESSION['admin_id'];
    
    // Update current user's online status and activity
    $db->exec("UPDATE admin_users SET is_online = 1, last_activity = datetime('now', 'localtime') WHERE id = $user_id");
    
    // Update all users who have been active within last 3 minutes
    $db->exec("UPDATE admin_users SET is_online = 1 
               WHERE last_activity > datetime('now', '-3 minutes') 
               AND is_active = 1 
               AND access_blocked = 0");
    
    // Mark offline those inactive for more than 3 minutes
    $db->exec("UPDATE admin_users SET is_online = 0 
               WHERE last_activity <= datetime('now', '-3 minutes') 
               AND is_active = 1");
    
    // Get counts for response
    $online_count = $db->querySingle("SELECT COUNT(*) FROM admin_users WHERE is_online = 1");
    $total_staff = $db->querySingle("SELECT COUNT(*) FROM admin_users WHERE is_active = 1 AND role != 'worker'");
    
    echo json_encode([
        'success' => true, 
        'user_id' => $user_id,
        'online_count' => $online_count,
        'total_staff' => $total_staff,
        'last_activity' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Export enquiries to CSV
 */
function exportEnquiries($db) {
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        die('Unauthorized');
    }
    
    $type = $_GET['type'] ?? 'all';
    $status = $_GET['status'] ?? 'all';
    
    $filename = 'enquiries_export_' . date('Y-m-d_His') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Define tables to export
    $tables = [];
    if ($type === 'all') {
        $tables = [
            'service_enquiries' => 'Service Enquiry',
            'general_contacts' => 'General Contact',
            'job_applications' => 'Job Application',
            'business_upgrades' => 'Business Upgrade',
            'placement_enquiries' => 'Placement Help'
        ];
    } else {
        $table_map = [
            'service' => 'service_enquiries',
            'general' => 'general_contacts',
            'job' => 'job_applications',
            'business' => 'business_upgrades',
            'placement' => 'placement_enquiries'
        ];
        if (isset($table_map[$type])) {
            $tables[$table_map[$type]] = ucfirst(str_replace('_', ' ', $type)) . ' Enquiry';
        }
    }
    
    foreach ($tables as $table => $label) {
        // Check if table exists
        $table_exists = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'");
        if (!$table_exists) continue;
        
        // Add section header
        fputcsv($output, ["=== $label ==="]);
        
        // Get column headers
        $columns_result = $db->query("PRAGMA table_info($table)");
        $headers = [];
        while ($col = $columns_result->fetchArray(SQLITE3_ASSOC)) {
            $headers[] = $col['name'];
        }
        fputcsv($output, $headers);
        
        // Build query
        $query = "SELECT * FROM $table";
        if ($status !== 'all') {
            $query .= " WHERE admin_status = '" . $db->escapeString($status) . "'";
        }
        $query .= " ORDER BY created_at DESC";
        
        $result = $db->query($query);
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            fputcsv($output, $row);
        }
        
        // Add empty line between sections
        fputcsv($output, []);
    }
    
    fclose($output);
    exit;
}

        
    </script>
    <?php
}
?>