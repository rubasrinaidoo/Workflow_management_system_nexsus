=====================================================================
NEXSUS BUSINESS SOLUTIONS - WORKFLOW MANAGEMENT SYSTEM (WMS)
README - Implementation Phase, Group 5, 2026
=====================================================================

Group members : Ayakhula Ngidi,Kaylen Govender,Scelo Mthethwa,Ahmed Patel,Zamokhule Vezi,Sboniso Gumede,Lulama Dlamini,Olwethu Khanyezi and Rubasri Naidoo.
Course / Module: IT PROJECT 600(2026S2ITP600B)
Supervisor      :  Mr Ajith Rampursad

---------------------------------------------------------------------
1. WHAT THIS SYSTEM IS
---------------------------------------------------------------------
A web-based workflow management system for NEXSUS Business Solutions
(accounting, taxation and payroll services). It has two parts:
 
  A) PUBLIC WEBSITE
     - Home page (index.php) with the company services and a
       "Send Us a Message" contact request form.
 
  B) INTERNAL SYSTEM (login required, role based)
     - OWNER DASHBOARD: contact requests, clients, service requests,
       tasks, documents, service catalogue, employees, reports,
       notifications, audit log, settings and database backup.
     - EMPLOYEE DASHBOARD: only the work assigned to that employee
       (My Tasks, My Service Requests, My Clients, My Documents,
       Notifications, My Settings).
 
---------------------------------------------------------------------
2. TECHNOLOGY USED
---------------------------------------------------------------------
  Front end : HTML5, CSS3, JavaScript
  Back end  : PHP  (MySQLi, prepared statements)
  Database  : MySQL / MariaDB (utf8mb4)
  Server    : XAMPP (Apache + PHP + MySQL + phpMyAdmin) on Windows
  Libraries : Chart.js 4.6.1, Bootstrap 5.3.8, Bootstrap Icons 1.13.2
              (all three load from the jsDelivr CDN), PHPMailer
              (included in the PHPMailer/ folder)
 
---------------------------------------------------------------------
---------------------------------------------------------------------
3. REQUIREMENTS
---------------------------------------------------------------------
  - XAMPP with PHP 
    - Internet connection (page icons, charts and password reset emails)
	Download or clone the NEXSUS repository.

Copy the nexsus application folder into:

C:\xampp\htdocs\

The final application directory should be:

C:\xampp\htdocs\nexsus

Ensure all PHP, CSS, JavaScript and supporting files remain inside their correct project folders.
---------------------------------------------------------------------
4. PROJECT FILE
---------------------------------------------------------------------
  README.txt                     this file
  database/nexsus_database.sql   database structure + data
  nexsus/                        the application (copy to htdocs)
      PHPMailer/src/             Exception.php, PHPMailer.php, SMTP.php
      config.php                 settings (database, email, URL)
      testConnection.php         checks the database connection
      setup.php                  one-time owner account setup

  Main application files inside nexsus/ :

  Public / login
      index.php                  home page + contact request form
      login.php  logout.php  forgotPassword.php  resetPassword.php
      privacyAcknowledgement.php
      style.css  script.js  nexsus_logo.png

  Owner pages
      ownerDashboard.php  contactRequests.php  clients.php
      clientProfile.php  serviceRequests.php  tasks.php  services.php
      documents.php  employee.php  reports.php  report_export.php
      notifications.php  auditLog.php  settings.php

  Employee pages
      employeeDashboard.php  myTasks.php  myServiceRequests.php
      myClients.php  myDocuments.php  mySettings.php

  Shared code
      authorization.php   sessions, CSRF, role checks, password rules
      conn_db.php         database connection
      nexsus_shell.php    shared sidebar / top bar / footer
      nexsus_urgency.php  overdue / due-soon / ageing deadline flags
      nexsus_modules.php, nexus_helpers.php   database helper functions
      client_lib.php  task_lib.php  report_lib.php
      notification_bell.php  mailer.php
      layout.css  layout.js
---------------------------------------------------------------------
5. FOLDERS: INSIDE AND OUTSIDE htdocs   (IMPORTANT)
---------------------------------------------------------------------
Uploaded documents, employee photos and backups must be stored
OUTSIDE the web folder (htdocs), so nobody can open them from a
web link. The system saves them next to htdocs, not inside it:

   C:\xampp\
   |
   |-- htdocs\                           <-- WEB FOLDER (public)
   |     \-- nexsus\                     <-- the application goes here
   |
   |-- nexsus_private_documents\         <-- OUTSIDE: client documents
   |-- nexsus_private\                   <-- OUTSIDE: employee photos
   \-- nexsus_backups\                   <-- OUTSIDE: database backups

These three folders are created automatically the first time they
are needed. If they are not, create them yourself in C:\xampp\
(NOT inside htdocs).

The application folder must be exactly C:\xampp\htdocs\nexsus,
because the system finds the private folders from that location.
If the application is placed anywhere else, uploads fail with the
message "Unsafe storage path".

---------------------------------------------------------------------
6. INSTALLATION
---------------------------------------------------------------------
STEP 1 - Start the services
   Open the XAMPP Control Panel and start Apache and MySQL.
STEP 2 – Copy the application
Download the GitHub repository using Code → Download ZIP and extract it.
Create the folder:
C:\xampp\htdocs\nexsus
Copy the application PHP, CSS, JavaScript, images and PHPMailer folder into this directory. Preserve the original file and folder structure. Keep the SQL file outside the web directory.

STEP 3 – Create and import the database
1. Open http://localhost/phpmyadmin.
2. Create a database named nexsus.
3. Select the database and click Import.
4. Choose nexsus_database.sql from the extracted GitHub repository.
5. Click Go to import the tables and fictional demonstration records.

STEP 4 - Create the private folders (if they do not exist)
   Create these folders inside C:\xampp\ (see section 5):
        C:\xampp\nexsus_private_documents
        C:\xampp\nexsus_private
        C:\xampp\nexsus_backups

	  
---------------------------------------------------------------------
7. CONFIGURATION
---------------------------------------------------------------------
Open  C:\xampp\htdocs\nexsus\config.php  in a text editor and set:

   DB_HOST   = localhost
   DB_PORT   = 3306
   DB_NAME   = nexsus   (the name created in step 3)
   DB_USER   = root               (XAMPP default)
   DB_PASS   =                    (XAMPP default is empty)
   APP_URL   = http://localhost/nexsus

   Email settings (only needed for "Forgot password" emails):
   SMTP_USER = the Gmail address that sends the emails
   SMTP_PASS = a Gmail APP PASSWORD (not the normal Gmail password;
               it needs 2-Step Verification on that Google account)
   SMTP_FROM_NAME = NEXSUS Business Solutions
Password-reset emails require valid SMTP configuration. All other system modules remain accessible without SMTP credentials.
Email Functionality – Demo Database vs Original System

The original NEXSUS Workflow Management System uses email-based password recovery with PHPMailer and Gmail SMTP. The database contains the users’ emails and password reset data and PHPMailer is used to send the recovery emails.

The demo database provided is fictional and contains fictional user information to protect privacy and security. SMTP credentials have been omitted. To get password-reset emails working, 
valid SMTP credentials and accessible email addresses are required. All other system functions are available for testing.
---------------------------------------------------------------------
8. TEST THE DATABASE CONNECTION
---------------------------------------------------------------------
After saving config.php, open:

      http://localhost/nexsus/testConnection.php

   - A success message means the system can reach the database.
   - An error means DB_HOST, DB_NAME, DB_USER or DB_PASS in
     config.php is wrong, or MySQL is not running.

---------------------------------------------------------------------
9. ONE-TIME OWNER SETUP
---------------------------------------------------------------------
The system has exactly ONE owner account (the database does not allow
a second one). The owner account is created by a one-time setup page:

      http://localhost/nexsus/setup.php

   - If you imported nexsus_database.sql, the demonstration Owner account already exists. Skip the initial Owner setup and use the demonstration credentials provided in the Implementation Phase PDF.
   - If you start with an EMPTY database, open setup.php once and
     follow the screen to create the owner account.
   - Employee accounts are created later by the owner, inside the
     system (Employees page).

---------------------------------------------------------------------
10. RUN THE SYSTEM (Testing and Verification) 
---------------------------------------------------------------------
After installation, verify the following
1.Apache and MySQL start successfully.
2.The NEXSUS public website is accessible by localhost.
3.Database connection is OK.
4.Only authorised Owners and Employees can log in. Users are redirected to their respective dashboards according to their assigned roles.
5.The Privacy Acknowledgement page is shown.
6.The proper role based dashboard appears.
7.Client, service request and task records can be accessed.
8.Documents uploaded can be placed in the setup private folder.
9.Database backup generation is done via Owner Settings.
10.Password-reset emails work when valid SMTP settings are configured.

   Home page : http://localhost/nexsus/index.php
   Login     : http://localhost/nexsus/login.php

   After logging in, tick the Privacy Acknowledgement to continue.
   You are taken to the Owner or Employee dashboard automatically.

      If SMTP settings are properly configured, then the password-reset e-mail will be working.

To troubleshoot database problems, review the connection information in the local configuration file and ensure that MySQL is running.

If the application includes a database connection test utility, then it should be eliminated or limited in use after development, for example as a local development tool.
---------------------------------------------------------------------------------------------------------------
11. Database Backup

The owner can manually generate timestamped SQL database backups through Settings → Backup & Recovery.

Generated backup files are stored in the configured private backup directory.

Database restoration can be performed through phpMyAdmin when required.

The SQL backup function protects database records but does not automatically provide a complete backup of the application source code or privately uploaded files.

These additional resources must be backed up separately where necessary.
---------------------------------------------------------------------------------------------------------------
 12. Third-Party Libraries and Acknowledgements

This project uses third-party open-source libraries to support user interface design, data visualisation and email functionality.

PHPMailer

PHPMailer is used to implement email-based password recovery.

PHPMailer is developed and maintained by its respective contributors and is distributed under the GNU Lesser General Public License (LGPL) version 2.1.

Official repository:

https://github.com/PHPMailer/PHPMailer

Licence:

https://github.com/PHPMailer/PHPMailer/blob/master/LICENSE

The PHPMailer library is included as a third-party dependency and is not claimed as original work developed by Group 5. Original copyright and licensing notices must be retained when redistributing the library.

Other Libraries

Bootstrap, Bootstrap Icons and Chart.js are third-party libraries used for interface components and data visualisation. They remain the work of their respective authors and contributors.
=====================================================================
END OF README
=====================================================================
