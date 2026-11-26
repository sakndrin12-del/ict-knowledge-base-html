<?php
declare(strict_types=1);

/**
 * scripts/create-admin.php — สคริปต์สร้างหรือกู้รหัสผ่านผู้ดูแลระบบ (อนุญาตเฉพาะ CLI เท่านั้น)
 *
 * วิธีใช้งานผ่าน Command Line / PowerShell:
 *   php scripts/create-admin.php <username> <password> "<fullname>"
 * ตัวอย่าง:
 *   php scripts/create-admin.php kkict-staff "StrongPass@2026!" "เจ้าหน้าที่ศูนย์ ICT"
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('403 Forbidden: This script can only be executed via CLI.');
}

$rootDir    = dirname(__DIR__);
$configFile = $rootDir . DIRECTORY_SEPARATOR . 'config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "[ERROR] ไม่พบไฟล์ config.php กรุณาคัดลอกจาก config.example.php ก่อน\n");
    exit(1);
}
require_once $configFile;

$username = $argv[1] ?? '';
$password = $argv[2] ?? '';
$fullname = $argv[3] ?? '';

if ($username === '') {
    echo "ชื่อผู้ใช้ (Username): ";
    $username = trim((string) fgets(STDIN));
}
if ($password === '') {
    echo "รหัสผ่าน (12-200 ตัวอักษร): ";
    $password = rtrim((string) fgets(STDIN), "\r\n");
}
if ($fullname === '') {
    echo "ชื่อ-นามสกุลเจ้าหน้าที่ (กด Enter เพื่อใช้ค่าเริ่มต้น): ";
    $fullname = trim((string) fgets(STDIN));
    if ($fullname === '') {
        $fullname = 'เจ้าหน้าที่ศูนย์ ICT';
    }
}

if (!preg_match('/^[a-zA-Z0-9._-]{3,80}$/', $username)) {
    fwrite(STDERR, "[ERROR] ชื่อผู้ใช้ต้องมีความยาว 3-80 ตัวอักษร (ภาษาอังกฤษ ตัวเลข จุด ขีดกลาง หรือขีดล่าง)\n");
    exit(1);
}

$passLen = mb_strlen($password, 'UTF-8');
if ($passLen < 12 || $passLen > 200) {
    fwrite(STDERR, "[ERROR] รหัสผ่านต้องมีความยาวตั้งแต่ 12 ถึง 200 ตัวอักษร (ปัจจุบัน {$passLen} ตัวอักษร)\n");
    exit(1);
}

if (mb_strlen($fullname, 'UTF-8') > 160) {
    fwrite(STDERR, "[ERROR] ชื่อ-นามสกุลต้องไม่เกิน 160 ตัวอักษร\n");
    exit(1);
}

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        DB_HOST,
        (int) DB_PORT,
        DB_NAME,
        DB_CHARSET
    );
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    $chk = $pdo->prepare('SELECT admin_id FROM admins WHERE username = :u LIMIT 1');
    $chk->execute([':u' => $username]);
    $existing = $chk->fetch();

    if ($existing) {
        $upd = $pdo->prepare('UPDATE admins SET password_hash = :h, fullname = :f WHERE admin_id = :id');
        $upd->execute([
            ':h'  => $hash,
            ':f'  => $fullname,
            ':id' => (int) $existing['admin_id'],
        ]);
        $actionMsg = "อัปเดตรหัสผ่านและข้อมูลของบัญชี '{$username}' เรียบร้อยแล้ว";
    } else {
        $ins = $pdo->prepare('INSERT INTO admins (username, password_hash, fullname, created_at) VALUES (:u, :h, :f, NOW(6))');
        $ins->execute([
            ':u' => $username,
            ':h' => $hash,
            ':f' => $fullname,
        ]);
        $actionMsg = "สร้างบัญชีผู้ดูแลระบบ '{$username}' เรียบร้อยแล้ว";
    }

    // ล้างประวัติการล็อกอินผิดพลาดเพื่อปลดล็อกบัญชีทันที
    $clr = $pdo->prepare('DELETE FROM login_attempts WHERE LOWER(username) = LOWER(:u)');
    $clr->execute([':u' => $username]);

    echo "[SUCCESS] {$actionMsg}\n";
    exit(0);
} catch (PDOException $e) {
    fwrite(STDERR, "[ERROR] เชื่อมต่อหรือบันทึกฐานข้อมูลไม่สำเร็จ: " . $e->getMessage() . "\n");
    exit(1);
}
