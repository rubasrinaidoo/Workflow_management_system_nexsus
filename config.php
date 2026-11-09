<?php
declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'nexsus';
const DB_USER = 'root';
const DB_PASS = '';

// Local project URL
const APP_URL = 'http://localhost/nexsus';

// Private key for initial Owner setup C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
const SETUP_KEY = '';

// Gmail SMTP Configuration
const SMTP_USER = '';
const SMTP_PASS = '';
const SMTP_FROM_NAME = 'NEXSUS Business Solutions';

// Password reset link expires after 30 minutes
const RESET_EXPIRY_MINUTES = 30;

// Privacy acknowledgement version
const PRIVACY_VERSION = '1.0';
