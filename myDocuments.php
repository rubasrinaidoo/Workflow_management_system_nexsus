<?php
declare(strict_types=1);

require_once __DIR__ . '/authorization.php';

require_logged_in('employee');

require __DIR__ . '/documents.php';
