MEDICINE SCANNER - setup and use
================================
Keep the project files together and open the site through PHP. Do not double-click
an HTML page: static file serving cannot enforce account sessions or protect the API.

START THE SITE
--------------
1. Install PHP 7.4 or newer (XAMPP is an easy option). Enable pdo_sqlite and curl
   in php.ini.
2. On Windows, double-click start.bat. On Linux/macOS, run ./start.sh.
   The scripts start PHP's built-in server with auth_router.php on port 8000.
   The server listens on all network interfaces so a local ESP32 can connect.
   Allow it through the firewall only on a trusted private network. Do not expose
   PHP's development server directly to the Internet.
3. Open http://localhost:8000/. The first page is the account sign-in/create-account
   screen. Create a patient account, then sign in to access Home, About, Chatbot,
   Report, Appointments, and profile settings.

ACCOUNTS AND ACCESS
-------------------
Create an account with a name, username, email address, and password. Phone is
optional. Usernames and email addresses must be unique. Passwords are hashed before
they are saved; account creation does not sign you in automatically. Sign in with
either your username or email address. Sign-out ends the session; Remember Me uses
an HttpOnly, SameSite cookie that expires after 30 days.

The PHP router protects every HTML page except account.html. The API also requires
an authenticated session for scanner, report, and chatbot operations. The ESP32
medicine-verification endpoint instead requires MEDICINE_SCANNER_DEVICE_KEY. Configure
the same private value in the server environment and DEVICE_API_KEY in the firmware.
Use HTTPS on a deployed site.

The API connects to medicine_system.sqlite in this folder and additively creates the
appointment schema, including patient/doctor roles, specialties, availability,
appointments, and notifications. It restores archived doctor profiles and legacy role
links without removing archived appointment data or changing scanner records. Doctor
schedules are empty until a doctor or administrator sets working hours; old, expired
slots are not reused as current availability. Back up the database before deployment
and do not expose or publicly download it.

APPOINTMENTS AND ADMINISTRATION
-------------------------------
Patients book and track requests on Appointments. Doctors sign in with their doctor
account to manage requests and working hours. An administrator adds or edits doctor
profiles and specialties on the same page. Set MEDICINE_SCANNER_ADMIN_KEY in the PHP
server environment to enable the administrator dashboard; enter that value on the
page when needed. Never put the key in HTML or JavaScript. Imported doctor profiles
without a linked doctor login must be linked to a new secure doctor account by an
administrator before they can receive bookings.

Appointment updates are stored as in-app notifications. If MEDICINE_SCANNER_MAIL_FROM
and PHP mail transport are configured, booking notifications are also emailed. The
SQLite migration runs automatically when the API is used; do not drop or replace the
database to install it.

MEDICINE SCANNING
-----------------
1. Open Home and enter the ESP32 IP address shown on its LCD.
2. Scan a medicine QR code with the camera, or enter its code manually.
3. The web page sends the code to the ESP32, which queries the PHP verification API.
   The result is displayed and recorded in scan history.
4. The Home dashboard reports the latest scan and ESP32-reported LED/buzzer state.
   These GPIO values are not sensors and do not prove that a physical component works.
5. To test without hardware, enable the demo checkbox. The sample catalog contains
   MED001 (unexpired) and MED003 (expired); unknown codes return NOT_FOUND.
6. The Report page shows scan history and can export a PDF. Administrative report
   actions require MEDICINE_SCANNER_ADMIN_KEY on the PHP server.

ESP32 FIRMWARE
--------------
Open esp32_firmware/MedicineScanner.ino in Arduino IDE. Install the ESP32 board
package, ArduinoJson, and LiquidCrystal_I2C. Set your Wi-Fi name/password and the
server's LAN address in API_URL, for example:
http://192.168.1.20:8000/api.php?action=verify

Set MEDICINE_SCANNER_DEVICE_KEY in the PHP server environment and the same value in
DEVICE_API_KEY in the firmware before compiling. Never commit or share the real key.
Do not use localhost in API_URL: on the ESP32, localhost means the ESP32 itself.

CHATBOT AND PASSWORD RESET
--------------------------
The chatbot uses Wikipedia's official Action API for optional reference searches.
It needs PHP cURL and Internet access. AI answers also need at least one provider key:
ANTHROPIC_API_KEY, OPENAI_API_KEY, or GEMINI_API_KEY. Set keys as PHP server
environment variables; .env.example is a reference and is not loaded automatically.
Never put live keys in HTML, JavaScript, or the firmware.

Password reset requires MEDICINE_SCANNER_MAIL_FROM, a secure
MEDICINE_SCANNER_BASE_URL, and a working PHP mail transport. Requests are rate-limited
and do not reveal whether an email address is registered. See .env.example for the
supported server environment variables.

DATABASE
--------
The application uses SQLite; no MySQL database or user is needed. database.sql
describes a fresh scanner and appointment database, while the running API applies a
preserving migration to an existing one. Keep medicine_system.sqlite writable by PHP
and maintain backups.

Apache deployments need mod_rewrite and AllowOverride enabled for .htaccess to send
HTML pages through auth_router.php. For public or multi-user deployment, use a
properly configured PHP hosting environment rather than the built-in development
server.
