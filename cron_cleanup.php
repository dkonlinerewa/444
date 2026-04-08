<?php
// ============================================
// CRON JOB - Auto cleanup old chats
// Run daily to delete chats older than 7 days
// Add to crontab: 0 2 * * * php /path/to/cron_cleanup.php
// ============================================

// Include config to get database connection
require_once 'config.php';

// Create logs directory if it doesn't exist
if (!file_exists('logs')) {
    mkdir('logs', 0755, true);
}

// Start cleanup
echo "[" . date('Y-m-d H:i:s') . "] Starting chat cleanup...\n";

try {
    // Get old sessions (older than 7 days and ended/completed)
    $old_sessions = $db->query("SELECT session_id, created_at FROM chat_sessions 
                                 WHERE (status = 'ended' OR status = 'completed') 
                                 AND last_activity < datetime('now', '-7 days')");
    
    $deleted_sessions = 0;
    $deleted_messages = 0;
    $deleted_sessions_list = [];
    
    while ($session = $old_sessions->fetchArray(SQLITE3_ASSOC)) {
        $session_id = $session['session_id'];
        $deleted_sessions_list[] = $session_id;
        
        // Count messages before deletion
        $msg_count = $db->querySingle("SELECT COUNT(*) FROM chat_messages WHERE session_id = '$session_id'");
        
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
        
        echo "  Deleted session: $session_id ($msg_count messages)\n";
    }
    
    // Also delete orphaned messages (no session) older than 30 days
    $orphaned = $db->exec("DELETE FROM chat_messages 
                           WHERE (session_id IS NULL OR session_id NOT IN (SELECT session_id FROM chat_sessions)) 
                           AND created_at < datetime('now', '-30 days')");
    
    // Log the results
    $log_message = sprintf(
        "[%s] Cleanup completed: %d sessions, %d messages, %d orphaned messages\n",
        date('Y-m-d H:i:s'),
        $deleted_sessions,
        $deleted_messages,
        $orphaned
    );
    
    file_put_contents('logs/chat_cleanup.log', $log_message, FILE_APPEND);
    
    echo "\n✅ Cleanup completed!\n";
    echo "   - Sessions deleted: $deleted_sessions\n";
    echo "   - Messages deleted: $deleted_messages\n";
    echo "   - Orphaned messages: $orphaned\n";
    
} catch (Exception $e) {
    $error_message = "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n";
    file_put_contents('logs/chat_cleanup.log', $error_message, FILE_APPEND);
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>