<?php
declare(strict_types=1);

/**
 * Router สำหรับรันทดสอบบนเครื่องด้วย PHP Built-in Web Server:
 *   php -S localhost:8000 router.php
 */

$rawUri = $_SERVER['REQUEST_URI'] ?? '/';
$uriPath = rawurldecode((string) parse_url($rawUri, PHP_URL_PATH));
if ($uriPath === '') {
    $uriPath = '/';
}

// 1. บล็อกการเข้าถึงไฟล์ตั้งค่า สคริปต์ CLI และไฟล์ระบบโดยตรง (จำลองกฎใน .htaccess)
$blockedPatterns = [
    '#^/(?:config\.php|config\.example\.php|router\.php|database-mysql\.sql)$#i',
    '#^/scripts(?:/|$)#i',
    '#(?:^|/)\.#', // บล็อกไฟล์ขึ้นต้นด้วยจุด เช่น .htaccess, .gitignore, .git
];
foreach ($blockedPatterns as $pattern) {
    if (preg_match($pattern, $uriPath)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo '403 Forbidden';
        return true;
    }
}

// 2. จัดการไฟล์ใน /uploads/ อย่างปลอดภัย (อนุญาตเฉพาะไฟล์ภาพและ PDF ที่มีอยู่จริง ห้ามรันสคริปต์เด็ดขาด)
if (preg_match('#^/uploads/([^/]+)$#', $uriPath, $m)) {
    $filename = $m[1];
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $allowedMime = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'pdf'  => 'application/pdf',
    ];
    if (!isset($allowedMime[$ext])) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo '403 Forbidden';
        return true;
    }
    $fullPath = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($fullPath)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo '404 Not Found';
        return true;
    }
    header('Content-Type: ' . $allowedMime[$ext]);
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . (string) filesize($fullPath));
    readfile($fullPath);
    return true;
}

// 3. ส่งคำขอ /api และ /api/... ทั้งหมดเข้าสู่ api.php
if ($uriPath === '/api' || str_starts_with($uriPath, '/api/')) {
    $subPath = substr($uriPath, 4);
    $_SERVER['KKICT_API_PATH'] = ($subPath === '' || $subPath === false) ? '/' : $subPath;
    require __DIR__ . '/api.php';
    return true;
}

// 4. รองรับหน้าแชร์ Open Graph (/share.php?id=...)
if ($uriPath === '/share.php') {
    require __DIR__ . '/share.php';
    return true;
}

// 5. หน้าหลัก / หรือ /index.html
if ($uriPath === '/' || $uriPath === '/index.html') {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    readfile(__DIR__ . '/index.html');
    return true;
}

// 6. ไฟล์ Static อื่นที่มีอยู่จริงและไม่ใช่ไฟล์ .php
$candidate = realpath(__DIR__ . DIRECTORY_SEPARATOR . ltrim($uriPath, '/\\'));
$rootReal = realpath(__DIR__);
if (
    $candidate !== false &&
    $rootReal !== false &&
    str_starts_with($candidate, $rootReal . DIRECTORY_SEPARATOR) &&
    is_file($candidate) &&
    strtolower(pathinfo($candidate, PATHINFO_EXTENSION)) !== 'php'
) {
    return false;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo '404 Not Found';
return true;
