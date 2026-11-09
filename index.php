<?php
session_start();

// Safely display entered information
function h($text) {
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Security token
if (empty($_SESSION['contact_token'])) {
    $_SESSION['contact_token'] = bin2hex(random_bytes(32));
}

$values = [
    'name' => '',
    'email' => '',
    'phone' => '',
    'preferred_contact' => '',
    'message' => ''
];

$errors = [];
$success = $_SESSION['contact_success'] ?? '';
unset($_SESSION['contact_success']);

// PHP FORM VALIDATION

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    foreach ($values as $field => $unused) {
        $input = $_POST[$field] ?? '';
        $values[$field] = is_string($input) ? trim($input) : '';
    }

    // Check security token
    $token = $_POST['contact_token'] ?? '';

    if (!is_string($token) ||
        !hash_equals($_SESSION['contact_token'], $token)) {
        $errors['form'] = 'Please refresh the page and try again.';
    }

    // Validate full name (letters, spaces, dots, hyphens, straight and curly apostrophes)
    if (!preg_match("/^[\p{L}][\p{L} .'’-]{1,99}$/u", $values['name'])) {
        $errors['name'] = 'Please enter a valid full name.';
    }

    // Validate email
    if (strlen($values['email']) > 200 ||
        !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    // Validate phone number (South African cellphone)
    // Remove spaces, dashes, dots and brackets
    $phone = preg_replace('/[\s\-().]/', '', $values['phone']);

    // Convert +27 / 0027 / 27 prefixes (with or without a stray 0) to local format
    $phone = preg_replace('/^(?:\+27|0027|27)0?/', '0', $phone);

    if (!preg_match('/^0[6-8][0-9]{8}$/', $phone)) {
        $errors['phone'] = 'Enter a valid South African cellphone number, e.g. 082 123 4567 or +27 82 123 4567.';
    } else {
        $values['phone'] = $phone; // store the cleaned version
    }

    // Validate communication preference
    if (!in_array($values['preferred_contact'], ['phone', 'email'], true)) {
        $errors['preferred_contact'] = 'Please select Phone or Email.';
    }

    // Validate message
    if (strlen($values['message']) < 5 ||
        strlen($values['message']) > 2000) {
        $errors['message'] = 'Your message must be 5 to 2000 characters.';
    }

    // DATABASE INSERTION

    if (empty($errors)) {
        try {
            require_once __DIR__ . '/conn_db.php';

            $sql = "INSERT INTO contact_requests
                    (name, email, phone, preferred_contact, message)
                    VALUES (?, ?, ?, ?, ?)";

            $stmt = $conn->prepare($sql);

            $stmt->bind_param(
                'sssss',
                $values['name'],
                $values['email'],
                $values['phone'],
                $values['preferred_contact'],
                $values['message']
            );

            $stmt->execute();
            $stmt->close();

            $_SESSION['contact_token'] = bin2hex(random_bytes(32));
            $_SESSION['contact_success'] =
                'Your message has been sent successfully!';

            header('Location: index.php#contact', true, 303);
            exit;

        } catch (mysqli_sql_exception $e) {
            error_log('Contact form error: ' . $e->getMessage());
            $errors['form'] =
                'Could not save your message. Please try again.';
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Nexsus Business Solutions</title>
    <meta name="description" content="Nexsus Business Solutions offers accounting, taxation and payroll services.">

    <style>
        /* BASIC SETTINGS */

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #273449;
        }

        .container {
            width: 90%;
            max-width: 1100px;
            margin: auto;
        }

        section {
            padding: 60px 0;
        }

        h1, h2, h3 {
            color: #0a1931;
            line-height: 1.3;
        }

        p {
            margin: 12px 0;
        }

        a {
            text-decoration: none;
        }

        .button {
            display: inline-block;
            padding: 12px 22px;
            background: #00a0d2;
            color: white;
            border-radius: 4px;
            font-weight: bold;
            border: none;
            cursor: pointer;
        }

        .button:hover {
            background: #0085b0;
        }

        /* TOP CONTACT BAR */

        .top-bar {
            background: #0a1931;
            color: white;
            padding: 10px 0;
            font-size: 13px;
        }

        .top-bar .container {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }

        .top-bar a {
            color: white;
        }

        /* HEADER AND NAVIGATION */

        header {
            background: white;
            border-bottom: 1px solid #e2e8f0;
        }

        .header-content {
            min-height: 100px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
        }

        .logo img {
            width: 160px;
            height: auto;
        }

        nav {
            display: flex;
            align-items: center;
            gap: 22px;
            flex-wrap: wrap;
        }

        nav a {
            color: #0a1931;
            font-size: 14px;
            font-weight: bold;
        }

        nav a:hover {
            color: #00a0d2;
        }

        /* HOME SECTION */

        .hero {
            background: #0a1931;
            color: white;
            padding: 80px 0;
        }

        .hero-content {
            max-width: 700px;
        }

        .hero h1 {
            color: white;
            font-size: clamp(32px, 5vw, 48px);
            margin-bottom: 20px;
        }

        .hero h1 span {
            color: #50d2ef;
        }

        .hero p {
            color: #d1dbea;
            margin-bottom: 25px;
        }

        /* SECTION HEADINGS */

        .section-heading {
            text-align: center;
            max-width: 650px;
            margin: 0 auto 35px;
        }

        .section-heading h2 {
            font-size: 30px;
            margin-bottom: 15px;
        }

        .section-heading p {
            color: #64748b;
        }

        .small-heading {
            color: #00a0d2;
            font-size: 13px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 2px;
        }

        /* SERVICES */

        .services {
            background: #f7f9fc;
        }

        .service-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 22px;
        }

        .service-card {
            background: white;
            padding: 28px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
        }

        .service-card h3 {
            margin-bottom: 12px;
        }

        .service-card p {
            color: #64748b;
            font-size: 14px;
        }

        .service-card a {
            display: inline-block;
            margin-top: 10px;
            color: #0085b0;
            font-weight: bold;
            font-size: 13px;
        }

        /* ABOUT US */

        .about {
            background: white;
        }

        .about-content {
            max-width: 750px;
            margin: auto;
            text-align: center;
        }

        .about-content p {
            color: #64748b;
        }

        /* CONTACT SECTION */

        .contact {
            background: #f7f9fc;
        }

        .contact-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 35px;
        }

        .contact-details,
        .contact-form {
            background: white;
            padding: 28px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
        }

        .contact-details h2,
        .contact-form h2 {
            margin-bottom: 15px;
        }

        .contact-details a {
            color: #0085b0;
            overflow-wrap: anywhere;
        }

        .map {
            width: 100%;
            height: 250px;
            border: 0;
            margin-top: 20px;
        }

        /* CONTACT FORM */

        .contact-form label {
            display: block;
            font-size: 14px;
            font-weight: bold;
            margin-top: 15px;
        }

        .contact-form input,
        .contact-form textarea {
            width: 100%;
            padding: 12px;
            margin-top: 6px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            font: inherit;
        }

        .contact-form textarea {
            min-height: 120px;
            resize: vertical;
        }

        .contact-form button {
            margin-top: 20px;
        }

        /* COMMUNICATION PREFERENCE */

        .contact-preference {
            border: 0;
            padding: 0;
            margin-top: 18px;
        }

        .contact-preference legend {
            font-size: 14px;
            font-weight: bold;
        }

        .contact-preference-options {
            display: flex;
            flex-wrap: wrap;
            gap: 22px;
            margin-top: 10px;
        }

        .contact-form .contact-preference-option {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 0;
            font-weight: normal;
            cursor: pointer;
        }

        .contact-form input[type="radio"] {
            width: auto;
            margin: 0;
            padding: 0;
            accent-color: #0085b0;
        }

        /* REQUIRED FIELDS AND FORM MESSAGES */

        .required {
            color: #dc2626;
            font-weight: bold;
        }

        .field-error {
            color: #b91c1c;
            font-size: 13px;
            margin-top: 5px;
        }

        .form-error,
        .form-success {
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 15px;
        }

        .form-error {
            background: #fee2e2;
            color: #991b1b;
        }

        .form-success {
            background: #dcfce7;
            color: #166534;
        }

        /* FOOTER */

        footer {
            background: #0a1931;
            color: #d1dbea;
            padding: 30px 0;
            text-align: center;
            font-size: 13px;
        }

        footer a {
            color: white;
        }

        /* MOBILE RESPONSIVENESS */

        @media (max-width: 750px) {
            .header-content {
                flex-direction: column;
                padding: 20px 0;
            }

            nav {
                justify-content: center;
                gap: 15px;
            }

            .hero {
                padding: 55px 0;
            }

            .service-grid,
            .contact-grid {
                grid-template-columns: 1fr;
            }

            section {
                padding: 45px 0;
            }
        }
    </style>
</head>

<body>

    <!-- HEADER AND NAVIGATION -->

    <header>
        <div class="container header-content">

            <a href="index.php" class="logo">
                <img src="nexsus_logo.png"
                     alt="Nexsus Business Solutions logo">
            </a>

            <nav aria-label="Main navigation">
                <a href="#home">Home</a>
                <a href="#services">Services</a>
                <a href="#about">About Us</a>
                <a href="#contact">Contact</a>
                <a href="login.php" class="button">Login</a>
            </nav>

        </div>
    </header>

    <main>

        <!-- HOME SECTION -->

        <section class="hero" id="home">
            <div class="container hero-content">

                <p class="small-heading">
                    Smarter Business Management
                </p>

                <h1>
                    Simplify Your Business.
                    <span>Focus on Growth.</span>
                </h1>

                <p>
                    Nexsus Business Solutions helps businesses manage
                    accounting, taxation and payroll. Our aim is to
                    support your business needs.
                </p>

                <a href="#services" class="button">
                    Explore Our Services
                </a>

            </div>
        </section>

        <!-- SERVICES SECTION -->

        <section class="services" id="services">
            <div class="container">

                <div class="section-heading">

                    <p class="small-heading">Our Services</p>

                    <h2>How We Can Help Your Business</h2>

                    <p>
                        Explore our business services and find out
                        how we can support your business needs.
                    </p>

                </div>

                <div class="service-grid">

                    <div class="service-card">

                        <h3>Accounting</h3>

                        <p>
                            Support your financial administration
                            and keep your accounting activities organised.
                        </p>

                        <a href="#contact">Enquire Now &rarr;</a>

                    </div>

                    <div class="service-card">

                        <h3>Taxation</h3>

                        <p>
                            Get assistance with your business's
                            tax-related administration and processes.
                        </p>

                        <a href="#contact">Enquire Now &rarr;</a>

                    </div>

                    <div class="service-card">

                        <h3>Payroll</h3>

                        <p>
                            Organise payroll activities and support
                            your employee payment administration.
                        </p>

                        <a href="#contact">Enquire Now &rarr;</a>

                    </div>

                </div>
            </div>
        </section>

        <!-- ABOUT US SECTION -->

        <section class="about" id="about">
            <div class="container about-content">

                <p class="small-heading">About Nexsus</p>

                <h2>Supporting Your Business Needs</h2>

                <p>
                    Nexsus Business Solutions focuses on accounting,
                    taxation and payroll services to help businesses
                    manage their financial needs.
                </p>

                <a href="#contact" class="button">
                    Contact Our Team
                </a>

            </div>
        </section>

        <!-- CONTACT SECTION -->

        <section class="contact" id="contact">
            <div class="container">

                <div class="section-heading">

                    <p class="small-heading">Get In Touch</p>

                    <h2>Let's Talk About Your Business</h2>

                    <p>
                        Contact us to learn more about our services
                        or ask a question.
                    </p>

                </div>

                <div class="contact-grid">

                    <!-- CONTACT DETAILS -->

                    <div class="contact-details">

                        <h2>Contact Details</h2>

                        <p><strong>WhatsApp Business:</strong></p>

                        <p>
                            <a href="https://wa.me/27645072274"
                               target="_blank"
                               rel="noopener noreferrer">
                                064 507 2274
                            </a>
                        </p>

                        <p><strong>Email:</strong></p>

                        <p>
                            <a href="mailto:info@nexsus.co.za">
                                info@nexsus.co.za
                            </a>
                        </p>

                        <p><strong>Address:</strong></p>

                        <p>
                            29 Coedmore Road, Sea View,
                            Durban, 4094, South Africa
                        </p>

                        <a
                            href="https://www.google.com/maps/search/?api=1&query=29%20Coedmore%20Road%2C%20Sea%20View%2C%20Durban%2C%204094%2C%20South%20Africa"
                            target="_blank"
                            rel="noopener noreferrer">
                            Get Directions
                        </a>

                        <iframe
                            class="map"
                            src="https://maps.google.com/maps?q=29%20Coedmore%20Road%2C%20Sea%20View%2C%20Durban%2C%204094%2C%20South%20Africa&output=embed"
                            title="Nexsus Business Solutions location"
                            loading="lazy">
                        </iframe>

                    </div>

                    <!-- CONTACT FORM WITH PHP -->

                    <div class="contact-form">

                        <h2>Send Us a Message</h2>

                        <?php if ($success): ?>
                            <p class="form-success" role="status">
                                <?= h($success) ?>
                            </p>
                        <?php endif; ?>

                        <?php if ($errors): ?>
                            <p class="form-error" role="alert">
                                <?= h($errors['form'] ?? 'Please correct the fields below.') ?>
                            </p>
                        <?php endif; ?>

                        <form action="index.php#contact" method="post">

                            <input
                                type="hidden"
                                name="contact_token"
                                value="<?= h($_SESSION['contact_token']) ?>">

                            <label for="name">
                                Full Name <span class="required">*</span>
                            </label>

                            <input
                                type="text"
                                id="name"
                                name="name"
                                autocomplete="name"
                                minlength="2"
                                maxlength="100"
                                value="<?= h($values['name']) ?>"
                                required>

                            <?php if (isset($errors['name'])): ?>
                                <p class="field-error">
                                    <?= h($errors['name']) ?>
                                </p>
                            <?php endif; ?>

                            <label for="email">
                                Email Address <span class="required">*</span>
                            </label>

                            <input
                                type="email"
                                id="email"
                                name="email"
                                autocomplete="email"
                                maxlength="200"
                                value="<?= h($values['email']) ?>"
                                required>

                            <?php if (isset($errors['email'])): ?>
                                <p class="field-error">
                                    <?= h($errors['email']) ?>
                                </p>
                            <?php endif; ?>

                            <label for="phone">
                                Phone Number <span class="required">*</span>
                            </label>

                            <input
                                type="tel"
                                id="phone"
                                name="phone"
                                autocomplete="tel"
                                inputmode="tel"
                                maxlength="20"
                                
                                value="<?= h($values['phone']) ?>"
                                required>

                            <?php if (isset($errors['phone'])): ?>
                                <p class="field-error">
                                    <?= h($errors['phone']) ?>
                                </p>
                            <?php endif; ?>

                            <fieldset class="contact-preference">

                                <legend>
                                    Preferred Method of Communication
                                    <span class="required">*</span>
                                </legend>

                                <div class="contact-preference-options">

                                    <label class="contact-preference-option">
                                        <input
                                            type="radio"
                                            name="preferred_contact"
                                            value="phone"
                                            <?= $values['preferred_contact'] === 'phone' ? 'checked' : '' ?>
                                            required>
                                        Phone
                                    </label>

                                    <label class="contact-preference-option">
                                        <input
                                            type="radio"
                                            name="preferred_contact"
                                            value="email"
                                            <?= $values['preferred_contact'] === 'email' ? 'checked' : '' ?>
                                            required>
                                        Email
                                    </label>

                                </div>

                            </fieldset>

                            <?php if (isset($errors['preferred_contact'])): ?>
                                <p class="field-error">
                                    <?= h($errors['preferred_contact']) ?>
                                </p>
                            <?php endif; ?>

                            <label for="message">
                                Your Message <span class="required">*</span>
                            </label>

                            <textarea
                                id="message"
                                name="message"
                                minlength="5"
                                maxlength="2000"
                                required><?= h($values['message']) ?></textarea>

                            <?php if (isset($errors['message'])): ?>
                                <p class="field-error">
                                    <?= h($errors['message']) ?>
                                </p>
                            <?php endif; ?>

                            <button type="submit" class="button">
                                Send Message
                            </button>

                        </form>

                    </div>

                </div>
            </div>
        </section>

    </main>

    <!-- FOOTER -->

    <footer>
        <div class="container">

            <p>
                &copy; <span id="year">2026</span>
                Nexsus Business Solutions. All rights reserved.
            </p>

            <p>
                <a href="mailto:info@nexsus.co.za">
                    Contact Us
                </a>
            </p>

        </div>
    </footer>

    <!-- AUTOMATIC COPYRIGHT YEAR -->

    <script>
        document.getElementById("year").textContent =
            new Date().getFullYear();
    </script>

</body>
</html>