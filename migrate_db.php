<?php
// Safe Database Migration - Run once
// Place this file in your root directory, access it once, then delete it

require_once 'config.php';

echo "<pre>";
echo "Starting safe database migration...\n\n";

// Check if tables exist before creating
$tables_to_create = [
    'job_applications' => "
        CREATE TABLE IF NOT EXISTS job_applications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            full_name TEXT NOT NULL,
            email TEXT NOT NULL,
            phone TEXT NOT NULL,
            position_applied TEXT NOT NULL,
            experience TEXT,
            current_ctc TEXT,
            expected_ctc TEXT,
            notice_period TEXT,
            resume_path TEXT,
            cover_letter TEXT,
            portfolio_url TEXT,
            linkedin_url TEXT,
            status TEXT DEFAULT 'pending',
            viewed_at DATETIME,
            admin_status TEXT DEFAULT 'pending',
            admin_notes TEXT,
            assigned_to INTEGER,
            is_archived INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME,
            FOREIGN KEY (assigned_to) REFERENCES admin_users(id)
        )
    ",
    
    'business_upgrades' => "
        CREATE TABLE IF NOT EXISTS business_upgrades (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            business_name TEXT NOT NULL,
            contact_person TEXT NOT NULL,
            email TEXT NOT NULL,
            phone TEXT NOT NULL,
            business_type TEXT,
            current_scale TEXT,
            upgrade_goal TEXT,
            message TEXT,
            status TEXT DEFAULT 'pending',
            viewed_at DATETIME,
            admin_status TEXT DEFAULT 'pending',
            admin_notes TEXT,
            assigned_to INTEGER,
            is_archived INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME,
            FOREIGN KEY (assigned_to) REFERENCES admin_users(id)
        )
    ",
    
    'placement_enquiries' => "
        CREATE TABLE IF NOT EXISTS placement_enquiries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            candidate_name TEXT NOT NULL,
            email TEXT NOT NULL,
            phone TEXT NOT NULL,
            qualification TEXT,
            experience_years TEXT,
            current_employer TEXT,
            desired_role TEXT,
            preferred_location TEXT,
            resume_path TEXT,
            message TEXT,
            status TEXT DEFAULT 'pending',
            viewed_at DATETIME,
            admin_status TEXT DEFAULT 'pending',
            admin_notes TEXT,
            assigned_to INTEGER,
            is_archived INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME,
            FOREIGN KEY (assigned_to) REFERENCES admin_users(id)
        )
    "
];

foreach ($tables_to_create as $table_name => $sql) {
    try {
        $db->exec($sql);
        echo "✓ Table '$table_name' created or already exists\n";
    } catch (Exception $e) {
        echo "✗ Error creating table '$table_name': " . $e->getMessage() . "\n";
    }
}

// Add missing columns to existing tables safely
echo "\nChecking for missing columns in existing tables...\n";

$existing_tables = [
    'admin_users' => [
        'columns' => [
            'is_online' => "ALTER TABLE admin_users ADD COLUMN is_online INTEGER DEFAULT 0",
            'last_activity' => "ALTER TABLE admin_users ADD COLUMN last_activity DATETIME",
            'active_chats' => "ALTER TABLE admin_users ADD COLUMN active_chats INTEGER DEFAULT 0",
            'current_chat_session' => "ALTER TABLE admin_users ADD COLUMN current_chat_session TEXT",
            'max_concurrent_chats' => "ALTER TABLE admin_users ADD COLUMN max_concurrent_chats INTEGER DEFAULT 2"
        ]
    ],
    'chat_sessions' => [
        'columns' => [
            'assigned_to' => "ALTER TABLE chat_sessions ADD COLUMN assigned_to INTEGER DEFAULT 0",
            'assigned_at' => "ALTER TABLE chat_sessions ADD COLUMN assigned_at DATETIME",
            'device_id' => "ALTER TABLE chat_sessions ADD COLUMN device_id TEXT",
            'last_activity' => "ALTER TABLE chat_sessions ADD COLUMN last_activity DATETIME DEFAULT CURRENT_TIMESTAMP",
            'status' => "ALTER TABLE chat_sessions ADD COLUMN status TEXT DEFAULT 'active'"
        ]
    ],
    'chat_messages' => [
        'columns' => [
            'is_read' => "ALTER TABLE chat_messages ADD COLUMN is_read INTEGER DEFAULT 0",
            'receiver_type' => "ALTER TABLE chat_messages ADD COLUMN receiver_type TEXT DEFAULT 'staff'",
            'chat_type' => "ALTER TABLE chat_messages ADD COLUMN chat_type TEXT DEFAULT 'internal'"
        ]
    ]
];

foreach ($existing_tables as $table => $config) {
    // Check if table exists
    $table_exists = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'");
    if (!$table_exists) {
        echo "⚠ Table '$table' doesn't exist yet, skipping column checks\n";
        continue;
    }
    
    // Get existing columns
    $columns_result = $db->query("PRAGMA table_info($table)");
    $existing_columns = [];
    while ($col = $columns_result->fetchArray(SQLITE3_ASSOC)) {
        $existing_columns[] = $col['name'];
    }
    
    foreach ($config['columns'] as $col_name => $alter_sql) {
        if (!in_array($col_name, $existing_columns)) {
            try {
                $db->exec($alter_sql);
                echo "✓ Added column '$col_name' to table '$table'\n";
            } catch (Exception $e) {
                echo "✗ Error adding column '$col_name' to '$table': " . $e->getMessage() . "\n";
            }
        } else {
            echo "  Column '$col_name' already exists in '$table'\n";
        }
    }
}

// Create indexes for better performance
echo "\nCreating performance indexes...\n";

$indexes = [
    "CREATE INDEX IF NOT EXISTS idx_chat_messages_created_at ON chat_messages(created_at)",
    "CREATE INDEX IF NOT EXISTS idx_chat_messages_session_id ON chat_messages(session_id)",
    "CREATE INDEX IF NOT EXISTS idx_chat_sessions_status ON chat_sessions(status)",
    "CREATE INDEX IF NOT EXISTS idx_job_applications_status ON job_applications(admin_status)",
    "CREATE INDEX IF NOT EXISTS idx_business_upgrades_status ON business_upgrades(admin_status)",
    "CREATE INDEX IF NOT EXISTS idx_placement_enquiries_status ON placement_enquiries(admin_status)",
    "CREATE INDEX IF NOT EXISTS idx_admin_users_last_activity ON admin_users(last_activity)",
    "CREATE INDEX IF NOT EXISTS idx_admin_users_is_online ON admin_users(is_online)"
];

foreach ($indexes as $index_sql) {
    try {
        $db->exec($index_sql);
        echo "✓ Created index\n";
    } catch (Exception $e) {
        echo "✗ Index creation warning: " . $e->getMessage() . "\n";
    }
}

// Update existing users to have default values
echo "\nUpdating existing users with default values...\n";
try {
    $db->exec("UPDATE admin_users SET is_online = 0 WHERE is_online IS NULL");
    $db->exec("UPDATE admin_users SET active_chats = 0 WHERE active_chats IS NULL");
    $db->exec("UPDATE admin_users SET max_concurrent_chats = 2 WHERE max_concurrent_chats IS NULL");
    echo "✓ Default values set for existing users\n";
} catch (Exception $e) {
    echo "✗ Error setting defaults: " . $e->getMessage() . "\n";
}

echo "\n✅ Migration completed successfully!\n";
echo "IMPORTANT: Delete this file (migrate_db.php) after confirming everything works.\n";
echo "</pre>";
?>