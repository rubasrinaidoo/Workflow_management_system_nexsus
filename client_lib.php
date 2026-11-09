<?php
declare(strict_types=1);

// Shared helpers for clients.php and clientProfile.php.
// NEXSUS deliberately does NOT collect tax numbers or ID numbers on the client record.

function client_bind(mysqli_stmt $stmt, array $params): void {
    if (!$params) return;
    $types = '';
    foreach ($params as $param) { $types .= is_int($param) ? 'i' : 's'; }
    $stmt->bind_param($types, ...$params);
}

function client_rows(mysqli $conn, string $sql, array $params = []): array {
    $stmt = $conn->prepare($sql);
    client_bind($stmt, $params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function client_one(mysqli $conn, string $sql, array $params = []): ?array {
    return client_rows($conn, $sql, $params)[0] ?? null;
}

function client_exec(mysqli $conn, string $sql, array $params = []): int {
    $stmt = $conn->prepare($sql);
    client_bind($stmt, $params);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $affected;
}

function client_contact_person(array $client): string {
    $parts = [
        trim((string)($client['first_name'] ?? '')),
        trim((string)($client['middle_name'] ?? '')),
        trim((string)($client['last_name'] ?? '')),
    ];
    return implode(' ', array_filter($parts, static fn(string $part): bool => $part !== ''));
}

/** The ways a client can ask to be contacted (keys match the preferred_contact_method ENUM). */
function client_contact_methods(): array {
    return ['phone' => 'Phone call', 'whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'email' => 'Email'];
}

function client_display_name(array $client): string {
    if (($client['client_type'] ?? '') === 'business' && trim((string)($client['business_name'] ?? '')) !== '') {
        return trim((string)$client['business_name']);
    }
    $person = client_contact_person($client);
    return $person !== '' ? $person : 'Unnamed client';
}

/**
 * Checks a phone number and returns it in a clean international form (+27821234567),
 * or null when it is not a believable real number.
 *
 * Accepted:  082 123 4567 | 031-555-1234 | +27 82 123 4567 | 0027821234567
 *            and other countries as +<country code><number> (8-15 digits in total).
 * Rejected:  letters, too short/long, South African numbers that cannot exist,
 *            and obvious fakes such as 0000000000 or 0123456789.
 */
function client_normalize_phone(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '' || !preg_match('/^\+?[0-9 ()\-]+$/', $raw)) return null;

    $plus   = $raw[0] === '+';
    $digits = (string)preg_replace('/\D/', '', $raw);

    if (!$plus && str_starts_with($digits, '00')) {   // 0027... style
        $digits = substr($digits, 2);
        $plus = true;
    }

    if ($plus) {
        if (!preg_match('/^[1-9][0-9]{7,14}$/', $digits)) return null;
        if (str_starts_with($digits, '27') && !preg_match('/^27[1-8][0-9]{8}$/', $digits)) return null;
    } else {
        // Local South African number: 0 + 9 digits, area/network code 01x-08x.
        if (!preg_match('/^0[1-8][0-9]{8}$/', $digits)) return null;
        $digits = '27' . substr($digits, 1);
    }

    if (preg_match('/(\d)\1{6,}/', $digits)) return null;                       // 7+ identical digits in a row
    if (str_contains('01234567890123456789', substr($digits, -9))) return null; // 123456789 style sequences

    return '+' . $digits;
}

/** Display helper: +27821234567 becomes +27 82 123 4567. Other values are shown as stored. */
function client_format_phone(?string $phone): string {
    $phone = trim((string)$phone);
    if ($phone === '') return '—';
    if (preg_match('/^\+27([1-8][0-9])([0-9]{3})([0-9]{4})$/', $phone, $m)) {
        return '+27 ' . $m[1] . ' ' . $m[2] . ' ' . $m[3];
    }
    return $phone;
}

/**
 * Cleans and validates the client form. Returns [cleanValues, errors].
 * $errors is keyed by field name (first_name, middle_name, last_name, business_name, email,
 * phone_number, preferred_contact_method, client_type) so the page can colour each box red/green.
 * Other messages use numeric keys.
 * Every rule is enforced here in PHP; the browser only gives early hints.
 */
function client_clean_input(array $post): array {
    $errors = [];
    $get = static function (string $key) use ($post): string {
        $raw = $post[$key] ?? '';
        if (!is_string($raw) || !mb_check_encoding($raw, 'UTF-8')) return '';
        return trim((string)preg_replace('/\s+/u', ' ', $raw));
    };

    $type     = $get('client_type');
    $first    = $get('first_name');
    $middle   = $get('middle_name');
    $last     = $get('last_name');
    $business = $get('business_name');
    $email    = mb_strtolower($get('email'));
    $phone    = $get('phone_number');
	$position = $get('contact_position');

    // Forms that do not send this field yet (the profile edit form) keep the database default.
    $methods = client_contact_methods();
    $method  = array_key_exists('preferred_contact_method', $post) ? $get('preferred_contact_method') : 'phone';
    if (!isset($methods[$method])) {
        $errors['preferred_contact_method'] = 'Choose how the client prefers to be contacted.';
        $method = 'phone';
    }

    if (!in_array($type, ['individual', 'business'], true)) {
        $errors['client_type'] = 'Choose whether the client is an individual or a business.';
        $type = 'individual';
    }

    // Names: letters only. One space, hyphen or apostrophe is allowed BETWEEN letters
    // (Mary-Jane, O'Brien, van der Merwe). No digits, no symbols.
    $namePattern = '/^\p{L}+(?:[ \'’-]\p{L}+)*$/u';
    $names = ['first_name' => ['First name', $first], 'last_name' => ['Last name', $last]];
    foreach ($names as $key => [$label, $value]) {
        $length = mb_strlen($value);
        if ($value === '') {
            $errors[$key] = $label . ' is required.';
        } elseif ($length < 2 || $length > 80) {
            $errors[$key] = $label . ' must be 2–80 characters.';
        } elseif (!preg_match($namePattern, $value)) {
            $errors[$key] = $label . ' may only contain letters (spaces, hyphens and apostrophes between words are fine).';
        }
    }

    // Middle name is optional; a single initial (J) is fine.
    if ($middle !== '') {
        if (mb_strlen($middle) > 80) {
            $errors['middle_name'] = 'Middle name must be 80 characters or fewer.';
        } elseif (!preg_match($namePattern, $middle)) {
            $errors['middle_name'] = 'Middle name may only contain letters (spaces, hyphens and apostrophes between words are fine).';
        }
    }

    if ($type === 'business') {
        $length = mb_strlen($business);
        if ($length < 2 || $length > 150) {
            $errors['business_name'] = 'Business name must be 2–150 characters.';
        } elseif (!preg_match('/^[\p{L}\p{N}][\p{L}\p{N} &.,\'’()\/+-]*$/u', $business) || !preg_match('/\p{L}/u', $business)) {
            $errors['business_name'] = 'Business name may only use letters, numbers, spaces and & . , \' ( ) / + - and must contain a letter.';
        }
       } else {
        $business = '';
    }

    // Contact position: business clients only, optional.
    if ($type === 'business' && $position !== '') {
        if (mb_strlen($position) > 100) {
            $errors['contact_position'] = 'Contact position must be 100 characters or fewer.';
        } elseif (!preg_match('/^\p{L}[\p{L}\p{N} &.,\'’()\/-]*$/u', $position)) {
            $errors['contact_position'] = 'Contact position may only use letters, numbers, spaces and & . , \' ( ) / -';
        }
    }
    if ($type !== 'business') $position = '';

    // Phone is compulsory and must be a believable real number.
    $normalizedPhone = null;
    if ($phone === '') {
        $errors['phone_number'] = 'Contact number is required.';
    } else {
        $normalizedPhone = client_normalize_phone($phone);
        if ($normalizedPhone === null) {
            $errors['phone_number'] = 'Enter a real phone number, for example 082 123 4567 or +27 82 123 4567.';
        } elseif (in_array($method, ['whatsapp', 'sms'], true)
            && str_starts_with($normalizedPhone, '+27')
            && !preg_match('/^\+27(?:[67][0-9]|8[1-5])[0-9]{7}$/', $normalizedPhone)) {
            $errors['phone_number'] = 'WhatsApp and SMS need a mobile number (starting 06, 07 or 08).';
        }
    }

    $clean = [
        'client_type'              => $type,
        'first_name'               => $first !== '' ? $first : null,
        'middle_name'              => $middle !== '' ? $middle : null,
        'last_name'                => $last !== '' ? $last : null,
        'business_name'            => $business !== '' ? $business : null,
		'contact_position' => $position !== '' ? $position : null,
        'email'                    => $email !== '' ? $email : null,
        // Saved in one standard format; if invalid, keep what was typed so the form can show it again.
        'phone_number'             => $normalizedPhone ?? ($phone !== '' ? $phone : null),
        'preferred_contact_method' => $method,
    ];
    return [$clean, $errors];
}

/**
 * Looks for an existing client that matches the business name or email address
 * (or, for an individual with no email, the same name and phone number).
 */
function client_find_duplicate(mysqli $conn, array $clean, int $excludeId = 0): ?array {
    $conditions = [];
    $params = [$excludeId];

    if ($clean['client_type'] === 'business' && $clean['business_name'] !== null) {
        $conditions[] = "(client_type='business' AND LOWER(business_name) = LOWER(?))";
        $params[] = $clean['business_name'];
    }
    if ($clean['email'] !== null) {
        $conditions[] = 'LOWER(email) = LOWER(?)';
        $params[] = $clean['email'];
    }
    if ($clean['client_type'] === 'individual' && $clean['email'] === null && $clean['phone_number'] !== null) {
        $conditions[] = "(client_type='individual' AND LOWER(first_name) = LOWER(?) AND LOWER(last_name) = LOWER(?) AND phone_number = ?)";
        array_push($params, $clean['first_name'], $clean['last_name'], $clean['phone_number']);
    }
    if (!$conditions) return null;

    $row = client_one($conn,
        'SELECT client_id, client_type, business_name, first_name, last_name, email
         FROM clients WHERE client_id <> ? AND (' . implode(' OR ', $conditions) . ') LIMIT 1', $params);
    if (!$row) return null;

    if ($clean['business_name'] !== null && mb_strtolower((string)$row['business_name']) === mb_strtolower($clean['business_name'])) {
        $row['reason'] = 'business name';
    } elseif ($clean['email'] !== null && mb_strtolower((string)$row['email']) === $clean['email']) {
        $row['reason'] = 'email address';
    } else {
        $row['reason'] = 'name and phone number';
    }
    return $row;
}

function client_duplicate_message(array $duplicate): string {
    return 'A client with the same ' . $duplicate['reason'] . ' already exists: '
        . client_display_name($duplicate) . ' (client #' . (int)$duplicate['client_id']
        . '). Open that profile instead of creating a duplicate.';
}