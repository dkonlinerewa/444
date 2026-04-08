<?php
// ============================================
// SITE DEFAULTS - D K Associates
// Central configuration for default values
// ============================================

// Site Identity
define('DEFAULT_SITE_LOGO', 'https://careers.hidk.in/logo.png');
define('DEFAULT_SITE_FAVICON', 'https://careers.hidk.in/minilogo.png');
define('DEFAULT_SITE_TITLE', 'D K Associates');
define('DEFAULT_SITE_TAGLINE', 'Workforce for Everyday Help');

// Contact Information
define('DEFAULT_COMPANY_PHONE', '07662-455311');
define('DEFAULT_COMPANY_WHATSAPP', '+919329578335');
define('DEFAULT_COMPANY_EMAIL', 'care@hidk.in');
define('DEFAULT_COMPANY_ADDRESS', '2nd Floor, Utopia Tower, Above Shriram Finance, Near College Chowk Flyover, Rewa, MP - 486001');
define('DEFAULT_COMPANY_HOURS', '7 Days: 10:00 AM - 6:30 PM');
define('DEFAULT_FOUNDED_YEAR', '2025');

// System Settings
define('DEFAULT_TIMEZONE', 'Asia/Kolkata');
define('DEFAULT_DATE_FORMAT', 'd M Y');
define('DEFAULT_TIME_FORMAT', 'h:i A');

// Social Media
define('DEFAULT_FACEBOOK_URL', '');
define('DEFAULT_TWITTER_URL', '');
define('DEFAULT_INSTAGRAM_URL', '');
define('DEFAULT_LINKEDIN_URL', '');
define('DEFAULT_YOUTUBE_URL', '');
define('DEFAULT_ENABLE_SOCIAL_LINKS', '1');

// Email Settings
define('DEFAULT_SMTP_HOST', '');
define('DEFAULT_SMTP_PORT', '587');
define('DEFAULT_SMTP_USER', '');
define('DEFAULT_SMTP_PASS', '');
define('DEFAULT_FROM_EMAIL', 'noreply@hidk.in');
define('DEFAULT_FROM_NAME', 'D K Associates');
define('DEFAULT_ENABLE_EMAIL', '0');

// Geofence Defaults
define('DEFAULT_GEOFENCE_ENABLED', '0');
define('DEFAULT_GEOFENCE_LAT', '24.5374');
define('DEFAULT_GEOFENCE_LNG', '81.2978');
define('DEFAULT_GEOFENCE_RADIUS', '500');
define('DEFAULT_GEOFENCE_ADDRESS', 'Head Office, Rewa, MP');

// User Defaults
define('DEFAULT_MAX_CONCURRENT_CHATS', '2');
define('DEFAULT_CARE_PERMISSION', '0');

// Sample Team Members
$DEFAULT_TEAM_MEMBERS = [
    ['Jyoti Mishra', 'Owner & CEO', 'Visionary leader driving company growth', 'https://via.placeholder.com/300x250?text=Jyoti', 1],
    ['Deepak Mishra', 'Tech Manager', 'Technology strategist and innovation expert', 'https://via.placeholder.com/300x250?text=Deepak', 2],
    ['Puneet Tiwari', 'Operations Manager', 'Ensuring smooth day-to-day operations', 'https://via.placeholder.com/300x250?text=Puneet', 3],
    ['Diksha Mishra', 'Business Development', 'Driving growth and new partnerships', 'https://via.placeholder.com/300x250?text=Diksha', 5],
    ['Pankaj Tiwari', 'Recruitment', 'Connecting the right talent with opportunities', 'https://via.placeholder.com/300x250?text=Pankaj', 4],
    ['Rahul Sharma', 'Advisor & Consultant', 'Providing strategic guidance and expertise', 'https://via.placeholder.com/300x250?text=Rahul', 6]
];

// Sample Job Openings
$DEFAULT_JOB_OPENINGS = [
    
];

// Default Settings Array
$DEFAULT_SITE_SETTINGS = [
    ['site_logo', DEFAULT_SITE_LOGO, 'text', 'general', 1],
    ['site_favicon', DEFAULT_SITE_FAVICON, 'text', 'general', 1],
    ['site_title', DEFAULT_SITE_TITLE, 'text', 'general', 1],
    ['site_tagline', DEFAULT_SITE_TAGLINE, 'text', 'general', 1],
    ['company_phone', DEFAULT_COMPANY_PHONE, 'text', 'contact', 1],
    ['company_whatsapp', DEFAULT_COMPANY_WHATSAPP, 'text', 'contact', 1],
    ['company_email', DEFAULT_COMPANY_EMAIL, 'text', 'contact', 1],
    ['company_address', DEFAULT_COMPANY_ADDRESS, 'text', 'contact', 1],
    ['company_hours', DEFAULT_COMPANY_HOURS, 'text', 'contact', 1],
    ['founded_year', DEFAULT_FOUNDED_YEAR, 'text', 'general', 1],
    ['facebook_url', DEFAULT_FACEBOOK_URL, 'text', 'social', 1],
    ['twitter_url', DEFAULT_TWITTER_URL, 'text', 'social', 1],
    ['instagram_url', DEFAULT_INSTAGRAM_URL, 'text', 'social', 1],
    ['linkedin_url', DEFAULT_LINKEDIN_URL, 'text', 'social', 1],
    ['youtube_url', DEFAULT_YOUTUBE_URL, 'text', 'social', 1],
    ['enable_social_links', DEFAULT_ENABLE_SOCIAL_LINKS, 'boolean', 'social', 1],
    ['smtp_host', DEFAULT_SMTP_HOST, 'text', 'email', 0],
    ['smtp_port', DEFAULT_SMTP_PORT, 'text', 'email', 0],
    ['smtp_user', DEFAULT_SMTP_USER, 'text', 'email', 0],
    ['smtp_pass', DEFAULT_SMTP_PASS, 'text', 'email', 0],
    ['from_email', DEFAULT_FROM_EMAIL, 'text', 'email', 0],
    ['from_name', DEFAULT_FROM_NAME, 'text', 'email', 0],
    ['enable_email', DEFAULT_ENABLE_EMAIL, 'boolean', 'email', 0],
    ['timezone', DEFAULT_TIMEZONE, 'text', 'system', 1],
    ['date_format', DEFAULT_DATE_FORMAT, 'text', 'system', 1],
    ['time_format', DEFAULT_TIME_FORMAT, 'text', 'system', 1],
    ['geofence_enabled', DEFAULT_GEOFENCE_ENABLED, 'boolean', 'geofence', 0],
    ['geofence_lat', DEFAULT_GEOFENCE_LAT, 'text', 'geofence', 0],
    ['geofence_lng', DEFAULT_GEOFENCE_LNG, 'text', 'geofence', 0],
    ['geofence_radius', DEFAULT_GEOFENCE_RADIUS, 'number', 'geofence', 0],
    ['geofence_address', DEFAULT_GEOFENCE_ADDRESS, 'text', 'geofence', 0]
];
?>