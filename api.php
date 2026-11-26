<?php
declare(strict_types=1);

/**
 * api.php — Backend API ครบวงจร (ไฟล์เดียว)
 * โครงการ: คลังความรู้ด้าน ICT — ศูนย์พัฒนาทักษะและการเรียนรู้ ICT เทศบาลนครขอนแก่น (KKICT)
 *
 * หมวดหมู่ภายในไฟล์:
 *   1. BOOTSTRAP & CONFIG
 *   2. SESSION & CSRF SECURITY
 *   3. DATABASE (PDO + UTF8MB4)
 *   4. COMMON HELPERS & DATA FORMATTERS
 *   5. FILE UPLOAD VALIDATION (EXT + MIME + MAGIC BYTES)
 *   6. PUBLIC API HANDLERS
 *   7. AUTH API HANDLERS (SESSION + RATE LIMIT + CHANGE PASSWORD)
 *   8. ADMIN API HANDLERS (DASHBOARD, 6M CHART, REPORTS, CRUD, SEARCH LOGS, CSV)
 *   9. ROUTER DISPATCH
 */

// =====================================================================
// ===== 1. BOOTSTRAP & CONFIG =====
// =====================================================================
date_default_timezone_set('Asia/Bangkok');
mb_internal_encoding('UTF-8');

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'      => false,
        'message' => 'ไม่พบไฟล์ config.php กรุณาคัดลอกจาก config.example.php และตั้งค่าฐานข้อมูล',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
require_once $configPath;

// =====================================================================
// ===== 2. SESSION & CSRF SECURITY =====
// =====================================================================
function startStaffSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 80) === 443);

    session_name(defined('SESSION_NAME') ? SESSION_NAME : 'kkict_staff_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function getCsrfToken(): string
{
    startStaffSession();
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(): void
{
    startStaffSession();
    $expected = $_SESSION['csrf_token'] ?? '';
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');
    if (!is_string($expected) || $expected === '' || !is_string($provided) || !hash_equals($expected, $provided)) {
        sendJson([
            'ok'      => false,
            'message' => 'CSRF token ไม่ถูกต้องหรือหมดอายุ กรุณารีเฟรชหน้าเว็บแล้วลองใหม่อีกครั้ง',
        ], 403);
    }
}

function getLoggedInAdmin(PDO $pdo): ?array
{
    startStaffSession();
    $adminId = $_SESSION['admin_id'] ?? null;
    if (!is_int($adminId) && !ctype_digit((string) $adminId)) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT admin_id, username, fullname, password_hash FROM admins WHERE admin_id = :id LIMIT 1');
    $stmt->execute([':id' => (int) $adminId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function requireStaffAuth(PDO $pdo): array
{
    $admin = getLoggedInAdmin($pdo);
    if ($admin === null) {
        sendJson(['ok' => false, 'message' => 'กรุณาเข้าสู่ระบบสำหรับเจ้าหน้าที่ก่อนดำเนินการ'], 401);
    }
    return $admin;
}

// =====================================================================
// ===== 3. DATABASE (PDO + UTF8MB4) =====
// =====================================================================
function getPdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        DB_HOST,
        (int) DB_PORT,
        DB_NAME,
        DB_CHARSET
    );

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ]);
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (PDOException $e) {
        sendJson([
            'ok'      => false,
            'status'  => 'error',
            'message' => 'ไม่สามารถเชื่อมต่อฐานข้อมูล MySQL ได้ กรุณาตรวจสอบค่าเชื่อมต่อใน config.php',
        ], 500);
    }

    return $pdo;
}

// =====================================================================
// ===== 4. COMMON HELPERS & DATA FORMATTERS =====
// =====================================================================
function sendJson(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function getApiRoutePath(): string
{
    if (!empty($_SERVER['KKICT_API_PATH'])) {
        $path = (string) $_SERVER['KKICT_API_PATH'];
    } else {
        $reqPath = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
        $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        if ($scriptDir !== '' && $scriptDir !== '/' && str_starts_with($reqPath, $scriptDir)) {
            $reqPath = substr($reqPath, strlen($scriptDir));
        }
        if (str_starts_with($reqPath, '/api.php')) {
            $reqPath = substr($reqPath, strlen('/api.php'));
        } elseif ($reqPath === '/api' || str_starts_with($reqPath, '/api/')) {
            $reqPath = substr($reqPath, 4);
        }
        $path = ($reqPath === '' || $reqPath === false) ? '/' : $reqPath;
    }

    $normalized = '/' . trim($path, '/');
    return $normalized === '//' ? '/' : $normalized;
}

function getEffectiveMethod(): string
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'POST') {
        $override = $_POST['_method'] ?? ($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? '');
        if (is_string($override) && in_array(strtoupper($override), ['PUT', 'PATCH', 'DELETE'], true)) {
            return strtoupper($override);
        }
    }
    return $method;
}

function readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function getClientIp(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return '127.0.0.1';
    }
    return substr($ip, 0, 45);
}

function getVoterKey(): string
{
    $cookieName = 'kkict_voter_id';
    $token = $_COOKIE[$cookieName] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/', $token)) {
        $token = bin2hex(random_bytes(16));
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((int) ($_SERVER['SERVER_PORT'] ?? 80) === 443);
        setcookie($cookieName, $token, [
            'expires'  => time() + (365 * 86400),
            'path'     => '/',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[$cookieName] = $token;
    }
    $salt = defined('VOTER_HASH_SALT') ? VOTER_HASH_SALT : 'kkict-voter-salt';
    return hash('sha256', $salt . '|voter:' . $token);
}

function toIsoDate(?string $mysqlDate): ?string
{
    if ($mysqlDate === null || trim($mysqlDate) === '') {
        return null;
    }
    $clean = preg_replace('/\.\d+$/', '', trim($mysqlDate)) ?? trim($mysqlDate);
    $ts = strtotime($clean);
    if ($ts === false) {
        return $mysqlDate;
    }
    return date('c', $ts);
}

function decodeJsonList(mixed $raw): array
{
    if (is_array($raw)) {
        return array_values(array_filter(array_map('strval', $raw), static fn(string $s): bool => trim($s) !== ''));
    }
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    $result = [];
    foreach ($decoded as $item) {
        if (is_scalar($item)) {
            $trimmed = trim((string) $item);
            if ($trimmed !== '') {
                $result[] = $trimmed;
            }
        }
    }
    return $result;
}

function resolveExistingUploadPath(?string $path): ?string
{
    if ($path === null) {
        return null;
    }
    $trimmed = trim($path);
    if ($trimmed === '') {
        return null;
    }
    if (preg_match('#^https?://#i', $trimmed)) {
        return $trimmed;
    }
    if (str_starts_with($trimmed, '/uploads/') || str_starts_with($trimmed, 'uploads/')) {
        $filename = basename($trimmed);
        $fullPath = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($fullPath)) {
            return null;
        }
        return '/uploads/' . $filename;
    }
    return null;
}

function resolveEffectiveBaseUrl(): string
{
    $configured = rtrim((string) BASE_URL, '/');
    if (preg_match('#^https?://(?:localhost|127\.0\.0\.1)(?::\d+)?$#i', $configured)) {
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host !== '' && preg_match('/^[a-zA-Z0-9.\-]+(?::\d+)?$/', $host)) {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || ((int) ($_SERVER['SERVER_PORT'] ?? 80) === 443);
            return ($isHttps ? 'https://' : 'http://') . $host;
        }
    }
    return $configured;
}

function formatArticleRow(array $row): array
{
    $rawImages = decodeJsonList($row['images'] ?? null);
    if (empty($rawImages) && !empty($row['image'])) {
        $rawImages = [(string) $row['image']];
    }

    $validImages = [];
    foreach ($rawImages as $img) {
        $resolved = resolveExistingUploadPath($img);
        if ($resolved !== null && !in_array($resolved, $validImages, true)) {
            $validImages[] = $resolved;
        }
        if (count($validImages) >= MAX_IMAGES_PER_ARTICLE) {
            break;
        }
    }

    $primaryImage = $validImages[0] ?? resolveExistingUploadPath(isset($row['image']) ? (string) $row['image'] : null);
    if ($primaryImage !== null && empty($validImages)) {
        $validImages = [$primaryImage];
    }

    $pdfFile  = resolveExistingUploadPath(isset($row['pdf_file']) ? (string) $row['pdf_file'] : null);
    $videoUrl = isset($row['video_url']) && trim((string) $row['video_url']) !== ''
        ? trim((string) $row['video_url'])
        : null;

    $articleId = (int) $row['article_id'];
    $baseUrl   = resolveEffectiveBaseUrl();

    $formatted = [
        'articleId'     => $articleId,
        'categoryId'    => (int) $row['category_id'],
        'title'         => (string) $row['title'],
        'content'       => (string) $row['content'],
        'steps'         => decodeJsonList($row['steps'] ?? null),
        'keywords'      => decodeJsonList($row['keywords'] ?? null),
        'image'         => $primaryImage,
        'images'        => $validImages,
        'pdfFile'       => $pdfFile,
        'videoUrl'      => $videoUrl,
        'viewCount'     => (int) ($row['view_count'] ?? 0),
        'status'        => (string) ($row['status'] ?? 'DRAFT'),
        'featured'      => (bool) ($row['featured'] ?? false),
        'createdByName' => isset($row['created_by_name']) && trim((string) $row['created_by_name']) !== ''
            ? trim((string) $row['created_by_name'])
            : 'ICT Administrator',
        'updatedByName' => isset($row['updated_by_name']) && trim((string) $row['updated_by_name']) !== ''
            ? trim((string) $row['updated_by_name'])
            : 'ICT Administrator',
        'publishedAt'   => toIsoDate($row['published_at'] ?? null),
        'createdAt'     => toIsoDate($row['created_at'] ?? null),
        'updatedAt'     => toIsoDate($row['updated_at'] ?? null),
        'shareUrl'      => $baseUrl . '/share.php?id=' . $articleId,
    ];

    if (isset($row['category_name'])) {
        $formatted['category'] = [
            'categoryId'   => (int) $row['category_id'],
            'categoryName' => (string) $row['category_name'],
            'description'  => $row['category_description'] ?? null,
        ];
    }

    return $formatted;
}

function formatThaiMonthLabel(string $ym, bool $short = false): string
{
    $parts = explode('-', $ym);
    if (count($parts) !== 2) {
        return $ym;
    }
    $yearAd = (int) $parts[0];
    $month  = (int) $parts[1];
    $yearBe = $yearAd + 543;

    $fullMonths = [
        1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน',
        5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม',
        9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม',
    ];
    $shortMonths = [
        1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.',
        5 => 'พ.ค.', 6 => 'มิ.ย.', 7 => 'ก.ค.', 8 => 'ส.ค.',
        9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.',
    ];

    if ($short) {
        return ($shortMonths[$month] ?? $ym) . ' ' . substr((string) $yearBe, -2);
    }
    return ($fullMonths[$month] ?? $ym) . ' ' . $yearBe . ' (' . $yearAd . ')';
}

function getDefaultContactSettings(): array
{
    return [
        'phone'    => '084-583-0208',
        'lineId'   => 'KKICT-Training',
        'lineUrl'  => 'https://line.me/R/ti/g/sTeVI-fA9K',
        'email'    => 'info.kkict@gmail.com',
        'location' => 'ชั้น 2 ตลาดสดเทศบาล 1 ถนนกลางเมือง ต.ในเมือง อ.เมืองขอนแก่น จ.ขอนแก่น 40000',
        'hours'    => 'อังคาร – อาทิตย์ เวลา 09:00 – 17:00 น. (หยุดวันจันทร์และวันหยุดนักขัตฤกษ์)',
        'note'     => 'ศูนย์พัฒนาทักษะและการเรียนรู้ ICT เทศบาลนครขอนแก่น บริการพื้นที่การเรียนรู้ ห้องอบรมคอมพิวเตอร์ และให้คำปรึกษาด้านไอที',
    ];
}

function getDefaultAnnouncementSettings(): array
{
    return [
        'enabled'   => false,
        'level'     => 'warning',
        'title'     => 'แจ้งประชาสัมพันธ์จากศูนย์ ICT เทศบาลนครขอนแก่น',
        'message'   => '',
        'linkUrl'   => '',
        'linkLabel' => 'ดูรายละเอียด',
        'updatedBy' => '',
        'updatedAt' => null,
    ];
}

function getAnnouncementSettings(PDO $pdo): array
{
    $defaults = getDefaultAnnouncementSettings();
    try {
        $stmt = $pdo->prepare("SELECT `value`, updatedAt FROM sitesetting WHERE `key` = 'announcement' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row && is_string($row['value']) && $row['value'] !== '') {
            $decoded = json_decode($row['value'], true);
            if (is_array($decoded)) {
                $merged = array_merge($defaults, $decoded);
                $merged['enabled'] = (bool) ($merged['enabled'] ?? false);
                $merged['level'] = in_array($merged['level'], ['warning', 'info', 'urgent'], true)
                    ? $merged['level']
                    : 'warning';
                if (empty($merged['updatedAt']) && !empty($row['updatedAt'])) {
                    $merged['updatedAt'] = toIsoDate((string) $row['updatedAt']);
                }
                return $merged;
            }
        }
    } catch (Throwable) {
        // กรณีตารางยังไม่ได้อัปเดต ให้คืนค่าเริ่มต้นอย่างปลอดภัย
    }
    return $defaults;
}

function formatAdminActorLabel(array $admin): string
{
    $full = trim((string) ($admin['fullname'] ?? ''));
    $user = trim((string) ($admin['username'] ?? ''));
    if ($full !== '' && $user !== '' && mb_strtolower($full, 'UTF-8') !== mb_strtolower($user, 'UTF-8')) {
        return mb_substr("{$full} ({$user})", 0, 160, 'UTF-8');
    }
    $fallback = $full !== '' ? $full : ($user !== '' ? $user : 'ICT Administrator');
    return mb_substr($fallback, 0, 160, 'UTF-8');
}

function logAdminAction(PDO $pdo, array $admin, string $action, string $targetType, ?int $targetId = null, ?string $detail = null): void
{
    try {
        $stmt = $pdo->prepare('
            INSERT INTO audit_logs (admin_id, admin_name, action, target_type, target_id, detail, created_at)
            VALUES (:aid, :aname, :act, :ttype, :tid, :det, NOW())
        ');
        $stmt->execute([
            ':aid'   => isset($admin['admin_id']) ? (int) $admin['admin_id'] : null,
            ':aname' => formatAdminActorLabel($admin),
            ':act'   => mb_substr($action, 0, 80, 'UTF-8'),
            ':ttype' => mb_substr($targetType, 0, 60, 'UTF-8'),
            ':tid'   => $targetId,
            ':det'   => $detail !== null ? mb_substr(trim($detail), 0, 500, 'UTF-8') : null,
        ]);
    } catch (Throwable) {
        // ไม่ให้กระทบการทำงานหลักหากโฮสต์ยังไม่ได้สร้างตาราง audit_logs
    }
}

function expandSearchQueryTerms(string $q): array
{
    $qLower   = mb_strtolower(trim($q), 'UTF-8');
    $qCompact = str_replace(['-', ' ', '_'], '', $qLower);
    if ($qLower === '') {
        return [];
    }

    $terms = [$qLower];
    $synonymGroups = [
        [
            'triggers' => ['ปริ้น', 'ปริ๊น', 'ปริ้นท์', 'พริ้น', 'พริ้นท์', 'ปริ้นเตอร์', 'พริ้นเตอร์', 'print', 'printer', 'เครื่องพิมพ์', 'สั่งพิมพ์'],
            'expands'  => ['printer', 'เครื่องพิมพ์', 'พิมพ์'],
        ],
        [
            'triggers' => ['ไวไฟ', 'วายฟาย', 'wifi', 'wi-fi', 'ไวเลส', 'wireless'],
            'expands'  => ['wifi', 'wi-fi', 'ไร้สาย'],
        ],
        [
            'triggers' => ['สแกน', 'แสกน', 'สแกนเนอร์', 'แสกนเนอร์', 'scan', 'scanner'],
            'expands'  => ['สแกน', 'scanner'],
        ],
        [
            'triggers' => ['เน็ต', 'เนต', 'อินเตอร์เน็ต', 'อินเทอร์เนต', 'internet'],
            'expands'  => ['อินเทอร์เน็ต', 'เครือข่าย'],
        ],
        [
            'triggers' => ['แลน', 'สายแลน', 'lan', 'ethernet'],
            'expands'  => ['ethernet', 'เครือข่าย', 'ip'],
        ],
        [
            'triggers' => ['พาสเวิร์ด', 'พาสเวิด', 'พาสเวิร์ดหาย', 'ลืมรหัส', 'password'],
            'expands'  => ['รหัสผ่าน', 'บัญชี'],
        ],
        [
            'triggers' => ['ซูม', 'มีท', 'ไมค์', 'เว็บแคม', 'webcam', 'ออนไลน์'],
            'expands'  => ['ประชุมออนไลน์', 'ไมโครโฟน', 'กล้อง', 'zoom', 'meet'],
        ],
        [
            'triggers' => ['แฮงค์', 'กระตุก', 'ค้าง', 'ช้า'],
            'expands'  => ['เครื่องช้า', 'เครื่องค้าง'],
        ],
        [
            'triggers' => ['จอดำ', 'จอมืด', 'จอฟ้า', 'เปิดไม่ติด'],
            'expands'  => ['จอภาพ', 'no signal'],
        ],
        [
            'triggers' => ['แบ็คอัพ', 'แบคอัพ', 'กู้ไฟล์', 'ไฟล์หาย', 'backup'],
            'expands'  => ['สำรองข้อมูล', 'กู้คืนไฟล์', 'backup'],
        ],
    ];

    foreach ($synonymGroups as $grp) {
        foreach ($grp['triggers'] as $trig) {
            if (str_contains($qLower, $trig) || ($qCompact !== '' && str_contains($qCompact, str_replace(['-', ' '], '', $trig)))) {
                foreach ($grp['expands'] as $exp) {
                    $terms[] = $exp;
                }
                break;
            }
        }
    }

    return array_slice(array_values(array_unique($terms)), 0, 6);
}

// =====================================================================
// ===== 5. FILE UPLOAD VALIDATION (EXT + MIME + MAGIC BYTES) =====
// =====================================================================
function ensureUploadsDir(): string
{
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        sendJson(['ok' => false, 'message' => 'ไม่สามารถสร้างโฟลเดอร์ uploads บนเซิร์ฟเวอร์ได้'], 500);
    }
    return $dir;
}

function normalizeMultiFileUpload(string $fieldName): array
{
    if (!isset($_FILES[$fieldName])) {
        return [];
    }
    $f = $_FILES[$fieldName];
    if (!is_array($f['name'])) {
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [];
        }
        return [$f];
    }

    $files = [];
    $count = count($f['name']);
    for ($i = 0; $i < $count; $i++) {
        if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $files[] = [
            'name'     => (string) ($f['name'][$i] ?? ''),
            'type'     => (string) ($f['type'][$i] ?? ''),
            'tmp_name' => (string) ($f['tmp_name'][$i] ?? ''),
            'error'    => (int) ($f['error'][$i] ?? UPLOAD_ERR_ERR_CANT_WRITE),
            'size'     => (int) ($f['size'][$i] ?? 0),
        ];
    }
    return $files;
}

function validateAndSaveImage(array $file, array &$savedFilesTracker): string
{
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException('ไฟล์รูปภาพมีขนาดเกินกำหนด (สูงสุดไม่เกิน 5 MB ต่อรูป)');
    }
    if ($err !== UPLOAD_ERR_OK) {
        throw new RuntimeException('เกิดข้อผิดพลาดระหว่างอัปโหลดรูปภาพ (รหัส ' . $err . ')');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > MAX_IMAGE_BYTES) {
        throw new RuntimeException('ไฟล์รูปภาพต้องมีขนาดไม่เกิน 5 MB ต่อรูป');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('ไฟล์รูปภาพที่อัปโหลดไม่ถูกต้อง');
    }

    // 1. ตรวจนามสกุลไฟล์เดิม
    $origName = (string) ($file['name'] ?? '');
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $extToMime = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
    ];
    if (!isset($extToMime[$ext])) {
        throw new RuntimeException('รองรับเฉพาะไฟล์รูปภาพนามสกุล JPG, PNG และ WebP เท่านั้น');
    }

    // 2. ตรวจ MIME Type จริงจากเนื้อไฟล์ด้วย finfo
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $realMime = (string) $finfo->file($tmpName);
    $mimeToCanonicalExt = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($mimeToCanonicalExt[$realMime]) || $extToMime[$ext] !== $realMime) {
        throw new RuntimeException('ชนิดไฟล์รูปภาพ (MIME Type) ไม่ตรงกับนามสกุลหรือไม่ได้รับอนุญาต');
    }

    // 3. ตรวจ Magic Bytes (File Signature) และโครงสร้างภาพ
    $fp = fopen($tmpName, 'rb');
    $headerBytes = $fp !== false ? (string) fread($fp, 16) : '';
    $headScan    = $fp !== false ? ($headerBytes . (string) fread($fp, 4096)) : '';
    if ($fp !== false) {
        fclose($fp);
    }

    $validMagic = match ($realMime) {
        'image/jpeg' => strncmp($headerBytes, "\xFF\xD8\xFF", 3) === 0,
        'image/png'  => strncmp($headerBytes, "\x89PNG\r\n\x1A\n", 8) === 0,
        'image/webp' => strlen($headerBytes) >= 12
            && substr($headerBytes, 0, 4) === 'RIFF'
            && substr($headerBytes, 8, 4) === 'WEBP',
        default      => false,
    };
    if (!$validMagic || @getimagesize($tmpName) === false) {
        throw new RuntimeException('โครงสร้างไฟล์รูปภาพ (Magic Bytes) ไม่ถูกต้องหรือไฟล์เสียหาย');
    }

    if (stripos($headScan, '<?php') !== false) {
        throw new RuntimeException('ตรวจพบโค้ดที่ไม่อนุญาตภายในไฟล์รูปภาพ');
    }

    // 4. ตั้งชื่อไฟล์ใหม่แบบสุ่ม (Cryptographically Secure) + ย่อและบีบอัดภาพอัตโนมัติด้วย GD (สูงสุด 1600px)
    $uploadsDir = ensureUploadsDir();
    $newName = bin2hex(random_bytes(16)) . '.' . $mimeToCanonicalExt[$realMime];
    $destPath = $uploadsDir . DIRECTORY_SEPARATOR . $newName;

    $processedByGd = optimizeAndSaveUploadedImage($tmpName, $destPath, $realMime);
    if (!$processedByGd) {
        if (!move_uploaded_file($tmpName, $destPath)) {
            throw new RuntimeException('ไม่สามารถบันทึกไฟล์รูปภาพลงเซิร์ฟเวอร์ได้');
        }
    }
    @chmod($destPath, 0644);

    $publicPath = '/uploads/' . $newName;
    $savedFilesTracker[] = $destPath;
    return $publicPath;
}

function optimizeAndSaveUploadedImage(string $tmpName, string $destPath, string $realMime): bool
{
    if (!extension_loaded('gd')) {
        return false;
    }

    $info = @getimagesize($tmpName);
    if ($info === false) {
        return false;
    }
    $origW = (int) ($info[0] ?? 0);
    $origH = (int) ($info[1] ?? 0);
    if ($origW <= 0 || $origH <= 0 || $origW > 12000 || $origH > 12000) {
        return false;
    }

    $srcImg = match ($realMime) {
        'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($tmpName) : false,
        'image/png'  => function_exists('imagecreatefrompng') ? @imagecreatefrompng($tmpName) : false,
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpName) : false,
        default      => false,
    };
    if ($srcImg === false) {
        return false;
    }

    // หมุนภาพอัตโนมัติตามค่า EXIF Orientation สำหรับภาพถ่ายจากกล้องสมาร์ตโฟน
    if ($realMime === 'image/jpeg' && function_exists('exif_read_data') && function_exists('imagerotate')) {
        $exif = @exif_read_data($tmpName);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        $angle = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        if ($angle !== 0) {
            $rotated = @imagerotate($srcImg, $angle, 0);
            if ($rotated !== false) {
                imagedestroy($srcImg);
                $srcImg = $rotated;
                $origW  = imagesx($srcImg);
                $origH  = imagesy($srcImg);
            }
        }
    }

    $maxDim = defined('MAX_IMAGE_DIMENSION') ? (int) MAX_IMAGE_DIMENSION : 1600;
    if ($origW > $maxDim || $origH > $maxDim) {
        $ratio   = min($maxDim / $origW, $maxDim / $origH);
        $targetW = max(1, (int) round($origW * $ratio));
        $targetH = max(1, (int) round($origH * $ratio));
    } else {
        $targetW = $origW;
        $targetH = $origH;
    }

    if (function_exists('imagepalettetotruecolor')) {
        @imagepalettetotruecolor($srcImg);
    }

    $dstImg = imagecreatetruecolor($targetW, $targetH);
    if ($dstImg === false) {
        imagedestroy($srcImg);
        return false;
    }

    if ($realMime === 'image/png' || $realMime === 'image/webp') {
        imagealphablending($dstImg, false);
        imagesavealpha($dstImg, true);
        $transparent = imagecolorallocatealpha($dstImg, 0, 0, 0, 127);
        imagefilledrectangle($dstImg, 0, 0, $targetW, $targetH, $transparent);
    }

    $resampled = imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $targetW, $targetH, $origW, $origH);
    if (!$resampled) {
        imagedestroy($srcImg);
        imagedestroy($dstImg);
        return false;
    }

    $saved = false;
    if ($realMime === 'image/jpeg' && function_exists('imagejpeg')) {
        @imageinterlace($dstImg, true);
        $saved = @imagejpeg($dstImg, $destPath, 84);
    } elseif ($realMime === 'image/png' && function_exists('imagepng')) {
        $saved = @imagepng($dstImg, $destPath, 7);
    } elseif ($realMime === 'image/webp' && function_exists('imagewebp')) {
        $saved = @imagewebp($dstImg, $destPath, 84);
    }

    imagedestroy($srcImg);
    imagedestroy($dstImg);
    return $saved && is_file($destPath);
}

function validateAndSavePdf(array $file, array &$savedFilesTracker): string
{
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException('ไฟล์ PDF มีขนาดเกินกำหนด (สูงสุดไม่เกิน 15 MB)');
    }
    if ($err !== UPLOAD_ERR_OK) {
        throw new RuntimeException('เกิดข้อผิดพลาดระหว่างอัปโหลดไฟล์ PDF (รหัส ' . $err . ')');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > MAX_PDF_BYTES) {
        throw new RuntimeException('ไฟล์ PDF ต้องมีขนาดไม่เกิน 15 MB');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('ไฟล์ PDF ที่อัปโหลดไม่ถูกต้อง');
    }

    // 1. ตรวจนามสกุลไฟล์เดิม
    $origName = (string) ($file['name'] ?? '');
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        throw new RuntimeException('อนุญาตเฉพาะไฟล์คู่มือนามสกุล .pdf เท่านั้น');
    }

    // 2. ตรวจ MIME Type จริงด้วย finfo
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $realMime = (string) $finfo->file($tmpName);
    if ($realMime !== 'application/pdf') {
        throw new RuntimeException('ชนิดไฟล์ไม่ใช่เอกสาร PDF ที่ถูกต้อง');
    }

    // 3. ตรวจ Magic Bytes (%PDF-)
    $fp = fopen($tmpName, 'rb');
    $headerBytes = $fp !== false ? (string) fread($fp, 8) : '';
    if ($fp !== false) {
        fclose($fp);
    }
    if (strncmp($headerBytes, '%PDF-', 5) !== 0) {
        throw new RuntimeException('โครงสร้างไฟล์ PDF (Magic Bytes) ไม่ถูกต้อง');
    }

    // 4. ตั้งชื่อไฟล์ใหม่แบบสุ่ม
    $uploadsDir = ensureUploadsDir();
    $newName = bin2hex(random_bytes(16)) . '.pdf';
    $destPath = $uploadsDir . DIRECTORY_SEPARATOR . $newName;

    if (!move_uploaded_file($tmpName, $destPath)) {
        throw new RuntimeException('ไม่สามารถบันทึกไฟล์ PDF ลงเซิร์ฟเวอร์ได้');
    }
    @chmod($destPath, 0644);

    $publicPath = '/uploads/' . $newName;
    $savedFilesTracker[] = $destPath;
    return $publicPath;
}

function deleteOrphanedUploadIfUnreferenced(PDO $pdo, ?string $publicPath, int $excludeArticleId = 0): void
{
    if ($publicPath === null || !str_starts_with($publicPath, '/uploads/')) {
        return;
    }
    $filename = basename($publicPath);
    // ป้องกันการลบไฟล์ตัวอย่าง 00000000-0000-4000-8000-... หากต้องการเก็บไว้เป็นชุดตั้งต้น หรือเช็กการอ้างอิงก่อนเสมอ
    $likePattern = '%' . addcslashes($filename, '%_\\') . '%';
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM articles
         WHERE article_id <> :aid
           AND (image LIKE :p1 OR CAST(images AS CHAR) LIKE :p2 OR pdf_file LIKE :p3)'
    );
    $stmt->execute([
        ':aid' => $excludeArticleId,
        ':p1'  => $likePattern,
        ':p2'  => $likePattern,
        ':p3'  => $likePattern,
    ]);
    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }

    // ไม่ลบไฟล์ชุดตัวอย่างเริ่มต้น 00000000-0000-4000-8000-... เผื่อผู้ใช้รีเซ็ตฐานข้อมูลซ้ำ
    if (preg_match('/^00000000-0000-4000-8000-\d{12}\.(?:png|pdf)$/i', $filename)) {
        return;
    }

    $fullPath = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $filename;
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

// =====================================================================
// ===== 6. PUBLIC API HANDLERS =====
// =====================================================================
function handleGetCategories(PDO $pdo): never
{
    $sql = "
        SELECT c.category_id, c.category_name, c.description, c.created_at,
               COUNT(a.article_id) AS published_count
        FROM categories c
        LEFT JOIN articles a
               ON a.category_id = c.category_id AND a.status = 'PUBLISHED'
        GROUP BY c.category_id, c.category_name, c.description, c.created_at
        ORDER BY c.category_id ASC
    ";
    $rows = $pdo->query($sql)->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'categoryId'   => (int) $r['category_id'],
            'categoryName' => (string) $r['category_name'],
            'description'  => $r['description'],
            'createdAt'    => toIsoDate($r['created_at'] ?? null),
            '_count'       => ['articles' => (int) $r['published_count']],
        ];
    }
    sendJson($out);
}

function handleGetPublicArticles(PDO $pdo): never
{
    $featured = isset($_GET['featured']) && in_array(strtolower((string) $_GET['featured']), ['1', 'true'], true);
    $category = isset($_GET['category']) && ctype_digit((string) $_GET['category']) ? (int) $_GET['category'] : null;
    $sort     = ($_GET['sort'] ?? 'recent') === 'popular' ? 'popular' : 'recent';
    $limit    = isset($_GET['limit']) ? max(1, min(100, (int) $_GET['limit'])) : 24;

    $where  = ["a.status = 'PUBLISHED'"];
    $params = [];

    if ($featured) {
        $where[] = 'a.featured = 1';
    }
    if ($category !== null && $category > 0) {
        $where[] = 'a.category_id = :cat';
        $params[':cat'] = $category;
    }

    $orderBy = $sort === 'popular'
        ? 'a.view_count DESC, COALESCE(a.published_at, a.created_at) DESC'
        : 'COALESCE(a.published_at, a.created_at) DESC, a.article_id DESC';

    $sql = "
        SELECT a.*, c.category_name, c.description AS category_description
        FROM articles a
        INNER JOIN categories c ON c.category_id = a.category_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY {$orderBy}
        LIMIT :lim
    ";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, PDO::PARAM_INT);
    }
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $items = array_map('formatArticleRow', $stmt->fetchAll());
    sendJson($items);
}

function handleSearchArticles(PDO $pdo): never
{
    $q            = trim((string) ($_GET['q'] ?? ''));
    $q            = mb_substr($q, 0, 120, 'UTF-8');
    $autocomplete = isset($_GET['autocomplete']) && in_array(strtolower((string) $_GET['autocomplete']), ['1', 'true'], true);
    $noLog        = $autocomplete || !empty($_GET['noLog']);
    $category     = isset($_GET['category']) && ctype_digit((string) $_GET['category']) ? (int) $_GET['category'] : null;
    $sort         = ($_GET['sort'] ?? 'recent') === 'popular' ? 'popular' : 'recent';
    $page         = max(1, (int) ($_GET['page'] ?? 1));
    $defaultSize  = $autocomplete ? 5 : 9;
    $pageSize     = max(1, min(50, (int) ($_GET['pageSize'] ?? $defaultSize)));
    $offset       = ($page - 1) * $pageSize;

    $where        = ["a.status = 'PUBLISHED'"];
    $params       = [];
    $expandedTerms = expandSearchQueryTerms($q);

    if ($category !== null && $category > 0) {
        $where[] = 'a.category_id = :cat';
        $params[':cat'] = $category;
    }

    if (!empty($expandedTerms)) {
        // รองรับทั้งคำตรงตัว คำตัดขีด/ช่องว่าง และคำพ้องความหมายภาษาไทย-ไอที เช่น "ปริ้น" <-> "Printer/เครื่องพิมพ์", "ไวไฟ" <-> "Wi-Fi"
        $orParts = [];
        foreach ($expandedTerms as $idx => $term) {
            $escapedRaw     = '%' . addcslashes($term, '%_\\') . '%';
            $compactTerm    = str_replace(['-', ' ', '_'], '', $term);
            $escapedCompact = '%' . addcslashes($compactTerm, '%_\\') . '%';

            $orParts[] = "LOWER(a.title) LIKE :qRaw_{$idx}_1";
            $orParts[] = "LOWER(a.content) LIKE :qRaw_{$idx}_2";
            $orParts[] = "LOWER(CAST(a.keywords AS CHAR)) LIKE :qRaw_{$idx}_3";
            $orParts[] = "LOWER(CAST(a.steps AS CHAR)) LIKE :qRaw_{$idx}_4";
            $params[":qRaw_{$idx}_1"] = $escapedRaw;
            $params[":qRaw_{$idx}_2"] = $escapedRaw;
            $params[":qRaw_{$idx}_3"] = $escapedRaw;
            $params[":qRaw_{$idx}_4"] = $escapedRaw;

            if ($compactTerm !== '') {
                $orParts[] = "REPLACE(REPLACE(REPLACE(LOWER(a.title), '-', ''), ' ', ''), '_', '') LIKE :qComp_{$idx}_1";
                $orParts[] = "REPLACE(REPLACE(REPLACE(LOWER(a.content), '-', ''), ' ', ''), '_', '') LIKE :qComp_{$idx}_2";
                $orParts[] = "REPLACE(REPLACE(REPLACE(LOWER(CAST(a.keywords AS CHAR)), '-', ''), ' ', ''), '_', '') LIKE :qComp_{$idx}_3";
                $orParts[] = "REPLACE(REPLACE(REPLACE(LOWER(CAST(a.steps AS CHAR)), '-', ''), ' ', ''), '_', '') LIKE :qComp_{$idx}_4";
                $params[":qComp_{$idx}_1"] = $escapedCompact;
                $params[":qComp_{$idx}_2"] = $escapedCompact;
                $params[":qComp_{$idx}_3"] = $escapedCompact;
                $params[":qComp_{$idx}_4"] = $escapedCompact;
            }
        }
        $where[] = '(' . implode(' OR ', $orParts) . ')';
    }

    $whereSql = implode(' AND ', $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM articles a WHERE {$whereSql}");
    foreach ($params as $k => $v) {
        $countStmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();

    // บันทึกคำค้นหาลงตาราง searchlog เฉพาะการค้นหาจริง (ไม่นับขณะพิมพ์ Live Autocomplete)
    if ($q !== '' && $page === 1 && !$noLog) {
        $logStmt = $pdo->prepare('INSERT INTO searchlog (term, resultCount, createdAt) VALUES (:term, :cnt, NOW(3))');
        $logStmt->execute([
            ':term' => $q,
            ':cnt'  => $total,
        ]);
    }

    $orderParams = [];
    if ($autocomplete && !empty($expandedTerms)) {
        $titleChecks = [];
        foreach ($expandedTerms as $idx => $term) {
            $titleChecks[] = "LOWER(a.title) LIKE :tBoost_{$idx}";
            $orderParams[":tBoost_{$idx}"] = '%' . addcslashes($term, '%_\\') . '%';
        }
        $orderBy = '(CASE WHEN ' . implode(' OR ', $titleChecks) . ' THEN 0 ELSE 1 END) ASC, a.featured DESC, a.view_count DESC, a.article_id DESC';
    } else {
        $orderBy = $sort === 'popular'
            ? 'a.view_count DESC, COALESCE(a.published_at, a.created_at) DESC'
            : 'COALESCE(a.published_at, a.created_at) DESC, a.article_id DESC';
    }

    $listSql = "
        SELECT a.*, c.category_name, c.description AS category_description
        FROM articles a
        INNER JOIN categories c ON c.category_id = a.category_id
        WHERE {$whereSql}
        ORDER BY {$orderBy}
        LIMIT :lim OFFSET :off
    ";
    $listStmt = $pdo->prepare($listSql);
    foreach ($params as $k => $v) {
        $listStmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    foreach ($orderParams as $k => $v) {
        $listStmt->bindValue($k, $v, PDO::PARAM_STR);
    }
    $listStmt->bindValue(':lim', $pageSize, PDO::PARAM_INT);
    $listStmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $listStmt->execute();

    $items = array_map('formatArticleRow', $listStmt->fetchAll());
    $pageCount = max(1, (int) ceil($total / $pageSize));

    // คำแนะนำเมื่อไม่พบผลลัพธ์ (ข้ามเมื่อเป็นโหมด Autocomplete เพื่อความรวดเร็ว)
    $didYouMean        = null;
    $suggestions       = ['Wi-Fi', 'Printer', 'รหัสผ่าน', 'Windows 11', 'อินเทอร์เน็ต'];
    $suggestedArticles = [];

    if ($total === 0 && !$autocomplete) {
        $kwRows = $pdo->query("SELECT keywords FROM articles WHERE status = 'PUBLISHED' AND keywords IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
        $pool   = [];
        foreach ($kwRows as $rawKw) {
            foreach (decodeJsonList($rawKw) as $kw) {
                $pool[$kw] = true;
            }
        }
        $candidateKeywords = array_keys($pool);
        if (!empty($candidateKeywords)) {
            $suggestions = array_slice($candidateKeywords, 0, 6);
        }

        if ($q !== '' && strlen($q) <= 60) {
            $bestDist = 999;
            $qLower   = mb_strtolower($q, 'UTF-8');
            foreach ($candidateKeywords as $cand) {
                $candLower = mb_strtolower($cand, 'UTF-8');
                if ($candLower === $qLower) {
                    continue;
                }
                if (strlen($qLower) < 250 && strlen($candLower) < 250) {
                    $dist = levenshtein($qLower, $candLower);
                    if ($dist > 0 && $dist <= 3 && $dist < $bestDist) {
                        $bestDist   = $dist;
                        $didYouMean = $cand;
                    }
                }
            }
        }

        $sugStmt = $pdo->query("
            SELECT a.*, c.category_name, c.description AS category_description
            FROM articles a
            INNER JOIN categories c ON c.category_id = a.category_id
            WHERE a.status = 'PUBLISHED'
            ORDER BY a.view_count DESC
            LIMIT 3
        ");
        $suggestedArticles = array_map('formatArticleRow', $sugStmt->fetchAll());
    }

    sendJson([
        'items'             => $items,
        'total'             => $total,
        'page'              => $page,
        'pageCount'         => $pageCount,
        'matchedTerms'      => $expandedTerms,
        'didYouMean'        => $didYouMean,
        'suggestions'       => $suggestions,
        'suggestedArticles' => $suggestedArticles,
    ]);
}

function getArticleFeedbackSummary(PDO $pdo, int $articleId): array
{
    $stmt = $pdo->prepare('
        SELECT
            COUNT(*) AS total_count,
            COALESCE(SUM(CASE WHEN helpful = 1 THEN 1 ELSE 0 END), 0) AS helpful_count,
            COALESCE(SUM(CASE WHEN helpful = 0 THEN 1 ELSE 0 END), 0) AS not_helpful_count
        FROM article_feedback
        WHERE article_id = :id
    ');
    $stmt->execute([':id' => $articleId]);
    $agg = $stmt->fetch() ?: ['total_count' => 0, 'helpful_count' => 0, 'not_helpful_count' => 0];

    $total      = (int) $agg['total_count'];
    $helpful    = (int) $agg['helpful_count'];
    $notHelpful = (int) $agg['not_helpful_count'];
    $percent    = $total > 0 ? (int) round(($helpful / $total) * 100) : 0;

    $userVote = null;
    $cookieToken = $_COOKIE['kkict_voter_id'] ?? '';
    if (is_string($cookieToken) && preg_match('/^[a-f0-9]{32}$/', $cookieToken)) {
        $salt = defined('VOTER_HASH_SALT') ? VOTER_HASH_SALT : 'kkict-voter-salt';
        $vKey = hash('sha256', $salt . '|voter:' . $cookieToken);
        $vStmt = $pdo->prepare('SELECT helpful FROM article_feedback WHERE article_id = :id AND voter_key = :vk LIMIT 1');
        $vStmt->execute([':id' => $articleId, ':vk' => $vKey]);
        $vRow = $vStmt->fetch();
        if ($vRow !== false) {
            $userVote = ((int) $vRow['helpful']) === 1;
        }
    }

    return [
        'helpfulCount'    => $helpful,
        'notHelpfulCount' => $notHelpful,
        'totalCount'      => $total,
        'helpfulPercent'  => $percent,
        'userVote'        => $userVote,
    ];
}

function handleGetSingleArticle(PDO $pdo, int $articleId): never
{
    $isPreview = isset($_GET['preview']) && in_array(strtolower((string) $_GET['preview']), ['1', 'true'], true);

    if ($isPreview) {
        // โหมดดูตัวอย่าง: ต้องล็อกอินเป็น Admin และห้ามนับยอดวิว
        requireStaffAuth($pdo);
        $stmt = $pdo->prepare("
            SELECT a.*, c.category_name, c.description AS category_description
            FROM articles a
            INNER JOIN categories c ON c.category_id = a.category_id
            WHERE a.article_id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $articleId]);
    } else {
        $stmt = $pdo->prepare("
            SELECT a.*, c.category_name, c.description AS category_description
            FROM articles a
            INNER JOIN categories c ON c.category_id = a.category_id
            WHERE a.article_id = :id AND a.status = 'PUBLISHED'
            LIMIT 1
        ");
        $stmt->execute([':id' => $articleId]);
    }

    $row = $stmt->fetch();
    if (!$row) {
        sendJson(['ok' => false, 'message' => 'ไม่พบบทความที่คุณต้องการ'], 404);
    }

    // นับยอดเข้าชมเฉพาะเมื่อผู้ใช้เปิดอ่านจริง (ไม่ใช่โหมด Preview)
    if (!$isPreview) {
        $upd = $pdo->prepare('UPDATE articles SET view_count = view_count + 1 WHERE article_id = :id');
        $upd->execute([':id' => $articleId]);

        $daily = $pdo->prepare('
            INSERT INTO article_view_daily (article_id, day, count)
            VALUES (:id, CURDATE(), 1)
            ON DUPLICATE KEY UPDATE count = count + 1
        ');
        $daily->execute([':id' => $articleId]);

        $row['view_count'] = ((int) $row['view_count']) + 1;
    }

    $formatted = formatArticleRow($row);
    $formatted['isPreview'] = $isPreview;
    $formatted['feedback']  = getArticleFeedbackSummary($pdo, $articleId);

    // บทความที่เกี่ยวข้องในหมวดหมู่เดียวกัน
    $relStmt = $pdo->prepare("
        SELECT a.*, c.category_name, c.description AS category_description
        FROM articles a
        INNER JOIN categories c ON c.category_id = a.category_id
        WHERE a.category_id = :cat AND a.article_id <> :id AND a.status = 'PUBLISHED'
        ORDER BY a.view_count DESC, a.article_id DESC
        LIMIT 2
    ");
    $relStmt->execute([
        ':cat' => (int) $row['category_id'],
        ':id'  => $articleId,
    ]);
    $formatted['relatedArticles'] = array_map('formatArticleRow', $relStmt->fetchAll());

    sendJson($formatted);
}

function handlePostArticleFeedback(PDO $pdo, int $articleId): never
{
    $chk = $pdo->prepare("SELECT article_id FROM articles WHERE article_id = :id AND status = 'PUBLISHED' LIMIT 1");
    $chk->execute([':id' => $articleId]);
    if (!$chk->fetch()) {
        sendJson(['ok' => false, 'message' => 'ไม่พบบทความที่เปิดรับคะแนนความพึงพอใจ'], 404);
    }

    $body = readJsonBody();
    if (!array_key_exists('helpful', $body)) {
        sendJson(['ok' => false, 'message' => 'กรุณาระบุผลการประเมินความพึงพอใจ'], 400);
    }
    $helpful = filter_var($body['helpful'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($helpful === null) {
        sendJson(['ok' => false, 'message' => 'ค่าการโหวตไม่ถูกต้อง'], 400);
    }

    $voterKey = getVoterKey();
    $stmt = $pdo->prepare('
        INSERT INTO article_feedback (article_id, voter_key, helpful, created_at)
        VALUES (:id, :vk, :helpful, NOW(6))
        ON DUPLICATE KEY UPDATE helpful = VALUES(helpful), created_at = NOW(6)
    ');
    $stmt->execute([
        ':id'      => $articleId,
        ':vk'      => $voterKey,
        ':helpful' => $helpful ? 1 : 0,
    ]);

    $summary = getArticleFeedbackSummary($pdo, $articleId);
    $summary['ok']       = true;
    $summary['userVote'] = $helpful;
    sendJson($summary);
}

function handleGetContactSettings(PDO $pdo): never
{
    $defaults = getDefaultContactSettings();
    $stmt = $pdo->prepare("SELECT `value` FROM sitesetting WHERE `key` = 'contact' LIMIT 1");
    $stmt->execute();
    $raw = $stmt->fetchColumn();
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            sendJson(array_merge($defaults, $decoded));
        }
    }
    sendJson($defaults);
}

function handleGetAnnouncementSettings(PDO $pdo): never
{
    sendJson(getAnnouncementSettings($pdo));
}

// =====================================================================
// ===== 7. AUTH API HANDLERS =====
// =====================================================================
function handleAuthLogin(PDO $pdo): never
{
    $body     = readJsonBody();
    $username = trim((string) ($body['username'] ?? ''));
    $password = (string) ($body['password'] ?? '');

    if ($username === '' || $password === '') {
        sendJson(['ok' => false, 'message' => 'กรุณากรอกชื่อผู้ใช้และรหัสผ่านให้ครบถ้วน'], 400);
    }

    $ip     = getClientIp();
    $window = defined('LOGIN_WINDOW_MINUTES') ? (int) LOGIN_WINDOW_MINUTES : 15;
    $maxIp  = defined('LOGIN_LIMIT_PER_IP') ? (int) LOGIN_LIMIT_PER_IP : 30;
    $maxUsr = defined('LOGIN_LIMIT_PER_USER') ? (int) LOGIN_LIMIT_PER_USER : 10;

    // ล้างข้อมูลการเดารหัสที่เก่าเกิน 1 วัน
    $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');

    // 1. ตรวจขีดจำกัดต่อ IP (30 ครั้ง / 15 นาที)
    $ipStmt = $pdo->prepare('
        SELECT COUNT(*) FROM login_attempts
        WHERE ip_address = :ip AND attempted_at >= DATE_SUB(NOW(), INTERVAL :win MINUTE)
    ');
    $ipStmt->bindValue(':ip', $ip, PDO::PARAM_STR);
    $ipStmt->bindValue(':win', $window, PDO::PARAM_INT);
    $ipStmt->execute();
    if ((int) $ipStmt->fetchColumn() >= $maxIp) {
        sendJson([
            'ok'      => false,
            'message' => "มีการพยายามเข้าสู่ระบบจาก IP นี้เกิน {$maxIp} ครั้งใน {$window} นาที กรุณารอสักครู่แล้วลองใหม่",
        ], 429);
    }

    // 2. ตรวจขีดจำกัดต่อชื่อบัญชี (10 ครั้ง / 15 นาที)
    $usrStmt = $pdo->prepare('
        SELECT COUNT(*) FROM login_attempts
        WHERE LOWER(username) = LOWER(:usr) AND attempted_at >= DATE_SUB(NOW(), INTERVAL :win MINUTE)
    ');
    $usrStmt->bindValue(':usr', mb_substr($username, 0, 80, 'UTF-8'), PDO::PARAM_STR);
    $usrStmt->bindValue(':win', $window, PDO::PARAM_INT);
    $usrStmt->execute();
    if ((int) $usrStmt->fetchColumn() >= $maxUsr) {
        sendJson([
            'ok'      => false,
            'message' => "บัญชีนี้กรอกรหัสผ่านผิดเกิน {$maxUsr} ครั้งใน {$window} นาที กรุณารอ {$window} นาทีแล้วลองใหม่",
        ], 429);
    }

    // 3. ตรวจสอบบัญชีและรหัสผ่านด้วย password_verify()
    $stmt = $pdo->prepare('SELECT admin_id, username, password_hash, fullname FROM admins WHERE username = :usr LIMIT 1');
    $stmt->execute([':usr' => $username]);
    $admin = $stmt->fetch();

    $dummyHash = '$2y$12$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
    $hashToCheck = $admin ? (string) $admin['password_hash'] : $dummyHash;
    $verified = password_verify($password, $hashToCheck) && $admin !== false;

    if (!$verified) {
        $rec = $pdo->prepare('INSERT INTO login_attempts (username, ip_address, attempted_at) VALUES (:usr, :ip, NOW())');
        $rec->execute([
            ':usr' => mb_substr($username, 0, 80, 'UTF-8'),
            ':ip'  => $ip,
        ]);
        sendJson(['ok' => false, 'message' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'], 401);
    }

    // ล็อกอินสำเร็จ: ล้างประวัติความผิดพลาดของบัญชีนี้ + เปลี่ยน Session ID ป้องกัน Session Fixation
    $clr = $pdo->prepare('DELETE FROM login_attempts WHERE LOWER(username) = LOWER(:usr)');
    $clr->execute([':usr' => $username]);

    startStaffSession();
    session_regenerate_id(true);
    $_SESSION['admin_id']       = (int) $admin['admin_id'];
    $_SESSION['admin_username'] = (string) $admin['username'];
    $_SESSION['csrf_token']     = bin2hex(random_bytes(32));

    sendJson([
        'ok'        => true,
        'token'     => 'session',
        'csrfToken' => $_SESSION['csrf_token'],
        'admin'     => [
            'adminId'  => (int) $admin['admin_id'],
            'username' => (string) $admin['username'],
            'fullname' => (string) $admin['fullname'],
        ],
    ]);
}

function handleAuthLogout(): never
{
    verifyCsrfToken();
    startStaffSession();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();

    $newCsrf = getCsrfToken();
    sendJson(['ok' => true, 'csrfToken' => $newCsrf]);
}

function handleAuthMe(PDO $pdo): never
{
    $admin = requireStaffAuth($pdo);
    sendJson([
        'ok'        => true,
        'csrfToken' => getCsrfToken(),
        'admin'     => [
            'adminId'  => (int) $admin['admin_id'],
            'username' => (string) $admin['username'],
            'fullname' => (string) $admin['fullname'],
        ],
    ]);
}

function handleAuthChangePassword(PDO $pdo): never
{
    $admin = requireStaffAuth($pdo);
    verifyCsrfToken();

    $body            = readJsonBody();
    $currentPassword = (string) ($body['currentPassword'] ?? '');
    $newPassword     = (string) ($body['newPassword'] ?? '');
    $confirmPassword = (string) ($body['confirmPassword'] ?? $newPassword);

    if ($currentPassword === '' || $newPassword === '') {
        sendJson(['ok' => false, 'message' => 'กรุณากรอกรหัสผ่านเดิมและรหัสผ่านใหม่ให้ครบถ้วน'], 400);
    }

    if (!password_verify($currentPassword, (string) $admin['password_hash'])) {
        sendJson(['ok' => false, 'message' => 'รหัสผ่านเดิมไม่ถูกต้อง'], 400);
    }

    $len = mb_strlen($newPassword, 'UTF-8');
    if ($len < 12 || $len > 200) {
        sendJson(['ok' => false, 'message' => 'รหัสผ่านใหม่ต้องมีความยาวตั้งแต่ 12 ถึง 200 ตัวอักษร'], 400);
    }

    if ($newPassword !== $confirmPassword) {
        sendJson(['ok' => false, 'message' => 'รหัสผ่านใหม่และการยืนยันรหัสผ่านไม่ตรงกัน'], 400);
    }

    $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
    $upd = $pdo->prepare('UPDATE admins SET password_hash = :h WHERE admin_id = :id');
    $upd->execute([
        ':h'  => $newHash,
        ':id' => (int) $admin['admin_id'],
    ]);

    logAdminAction($pdo, $admin, 'CHANGE_PASSWORD', 'auth', (int) $admin['admin_id'], 'เปลี่ยนรหัสผ่านบัญชีเจ้าหน้าที่');

    session_regenerate_id(true);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    sendJson([
        'ok'        => true,
        'message'   => 'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว',
        'csrfToken' => $_SESSION['csrf_token'],
    ]);
}

// =====================================================================
// ===== 8. ADMIN API HANDLERS =====
// =====================================================================
function buildSixMonthTrend(PDO $pdo, ?string $endMonth = null): array
{
    // หาเดือนล่าสุดที่มีข้อมูลหรือเดือนปัจจุบัน
    if ($endMonth === null || !preg_match('/^\d{4}-\d{2}$/', $endMonth)) {
        $latestDataMonth = $pdo->query("
            SELECT MAX(ym) FROM (
                SELECT DATE_FORMAT(CURDATE(), '%Y-%m') AS ym
                UNION ALL
                SELECT DATE_FORMAT(MAX(day), '%Y-%m') FROM article_view_daily
                UNION ALL
                SELECT DATE_FORMAT(MAX(COALESCE(published_at, created_at)), '%Y-%m') FROM articles
            ) t
        ")->fetchColumn();
        $endMonth = is_string($latestDataMonth) && preg_match('/^\d{4}-\d{2}$/', $latestDataMonth)
            ? $latestDataMonth
            : date('Y-m');
    }

    $endDt   = DateTimeImmutable::createFromFormat('!Y-m', $endMonth) ?: new DateTimeImmutable('first day of this month');
    $startDt = $endDt->modify('-5 months');
    $nextEnd = $endDt->modify('+1 month');

    $months = [];
    for ($i = 0; $i < 6; $i++) {
        $mDt = $startDt->modify("+{$i} months");
        $ym  = $mDt->format('Y-m');
        $months[$ym] = [
            'month'         => $ym,
            'label'         => formatThaiMonthLabel($ym, true),
            'fullLabel'     => formatThaiMonthLabel($ym, false),
            'views'         => 0,
            'articleCount'  => 0,
            'searchesCount' => 0,
        ];
    }

    $rangeStart = $startDt->format('Y-m-01');
    $rangeEnd   = $nextEnd->format('Y-m-01');

    // ยอดวิวรายเดือนจาก article_view_daily
    $vStmt = $pdo->prepare("
        SELECT DATE_FORMAT(day, '%Y-%m') AS ym, COALESCE(SUM(count), 0) AS total_views
        FROM article_view_daily
        WHERE day >= :s AND day < :e
        GROUP BY ym
    ");
    $vStmt->execute([':s' => $rangeStart, ':e' => $rangeEnd]);
    foreach ($vStmt->fetchAll() as $r) {
        $ym = (string) $r['ym'];
        if (isset($months[$ym])) {
            $months[$ym]['views'] = (int) $r['total_views'];
        }
    }

    // จำนวนบทความต่อเดือน (นับตามเดือนที่เผยแพร่/สร้าง)
    $aStmt = $pdo->prepare("
        SELECT DATE_FORMAT(COALESCE(published_at, created_at), '%Y-%m') AS ym, COUNT(*) AS cnt
        FROM articles
        WHERE COALESCE(published_at, created_at) >= :s AND COALESCE(published_at, created_at) < :e
        GROUP BY ym
    ");
    $aStmt->execute([':s' => $rangeStart . ' 00:00:00', ':e' => $rangeEnd . ' 00:00:00']);
    foreach ($aStmt->fetchAll() as $r) {
        $ym = (string) $r['ym'];
        if (isset($months[$ym])) {
            $months[$ym]['articleCount'] = (int) $r['cnt'];
        }
    }

    // จำนวนการค้นหาต่อเดือน
    $sStmt = $pdo->prepare("
        SELECT DATE_FORMAT(createdAt, '%Y-%m') AS ym, COUNT(*) AS cnt
        FROM searchlog
        WHERE createdAt >= :s AND createdAt < :e
        GROUP BY ym
    ");
    $sStmt->execute([':s' => $rangeStart . ' 00:00:00', ':e' => $rangeEnd . ' 00:00:00']);
    foreach ($sStmt->fetchAll() as $r) {
        $ym = (string) $r['ym'];
        if (isset($months[$ym])) {
            $months[$ym]['searchesCount'] = (int) $r['cnt'];
        }
    }

    $series        = array_values($months);
    $totalViews    = array_sum(array_column($series, 'views'));
    $totalArticles = array_sum(array_column($series, 'articleCount'));
    $totalSearches = array_sum(array_column($series, 'searchesCount'));

    return [
        'months'      => $series,
        'periodLabel' => ($series[0]['fullLabel'] ?? '') . ' – ' . ($series[count($series) - 1]['fullLabel'] ?? ''),
        'totals'      => [
            'views'    => $totalViews,
            'articles' => $totalArticles,
            'searches' => $totalSearches,
        ],
    ];
}

function handleAdminDashboard(PDO $pdo): never
{
    requireStaffAuth($pdo);

    $artStats = $pdo->query("
        SELECT
            COUNT(*) AS article_count,
            COALESCE(SUM(CASE WHEN status = 'PUBLISHED' THEN 1 ELSE 0 END), 0) AS published_count,
            COALESCE(SUM(CASE WHEN status = 'DRAFT' THEN 1 ELSE 0 END), 0) AS draft_count,
            COALESCE(SUM(CASE WHEN status = 'HIDDEN' THEN 1 ELSE 0 END), 0) AS hidden_count,
            COALESCE(SUM(view_count), 0) AS total_views
        FROM articles
    ")->fetch();

    $fbStats = $pdo->query("
        SELECT
            COUNT(*) AS feedback_count,
            COALESCE(SUM(CASE WHEN helpful = 1 THEN 1 ELSE 0 END), 0) AS helpful_count
        FROM article_feedback
    ")->fetch();

    $catCount = (int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();

    $fbTotal   = (int) ($fbStats['feedback_count'] ?? 0);
    $fbHelpful = (int) ($fbStats['helpful_count'] ?? 0);
    $fbPercent = $fbTotal > 0 ? (int) round(($fbHelpful / $fbTotal) * 100) : 0;

    $sixMonth = buildSixMonthTrend($pdo);

    sendJson([
        'publishedCount' => (int) ($artStats['published_count'] ?? 0),
        'draftCount'     => (int) ($artStats['draft_count'] ?? 0),
        'hiddenCount'    => (int) ($artStats['hidden_count'] ?? 0),
        'articleCount'   => (int) ($artStats['article_count'] ?? 0),
        'totalViews'     => (int) ($artStats['total_views'] ?? 0),
        'helpfulPercent' => $fbPercent,
        'helpfulCount'   => $fbHelpful,
        'feedbackCount'  => $fbTotal,
        'categoryCount'  => $catCount,
        'sixMonthChart'  => $sixMonth['months'],
        'sixMonthPeriod' => $sixMonth['periodLabel'],
        'sixMonthTotals' => $sixMonth['totals'],
    ]);
}

function getMonthlyReportData(PDO $pdo, string $requestedMonth = ''): array
{
    $monthRows = $pdo->query("
        SELECT DISTINCT ym FROM (
            SELECT DATE_FORMAT(CURDATE(), '%Y-%m') AS ym
            UNION
            SELECT DATE_FORMAT(COALESCE(published_at, created_at), '%Y-%m') AS ym FROM articles
            UNION
            SELECT DATE_FORMAT(day, '%Y-%m') AS ym FROM article_view_daily
            UNION
            SELECT DATE_FORMAT(createdAt, '%Y-%m') AS ym FROM searchlog
        ) m
        WHERE ym IS NOT NULL AND ym <> ''
        ORDER BY ym DESC
    ")->fetchAll(PDO::FETCH_COLUMN);

    if (empty($monthRows)) {
        $monthRows = [date('Y-m')];
    }

    $selectedMonth = (preg_match('/^\d{4}-\d{2}$/', $requestedMonth) && in_array($requestedMonth, $monthRows, true))
        ? $requestedMonth
        : (preg_match('/^\d{4}-\d{2}$/', $requestedMonth) ? $requestedMonth : (string) $monthRows[0]);

    $availableMonths = array_map(static fn(string $ym): array => [
        'value' => $ym,
        'label' => formatThaiMonthLabel($ym, false),
    ], $monthRows);

    $mDt   = DateTimeImmutable::createFromFormat('!Y-m', $selectedMonth) ?: new DateTimeImmutable('first day of this month');
    $start = $mDt->format('Y-m-01');
    $end   = $mDt->modify('+1 month')->format('Y-m-01');

    // ยอดวิวในเดือนนี้
    $vStmt = $pdo->prepare('SELECT COALESCE(SUM(count), 0) FROM article_view_daily WHERE day >= :s AND day < :e');
    $vStmt->execute([':s' => $start, ':e' => $end]);
    $totalViewsInMonth = (int) $vStmt->fetchColumn();

    // จำนวนการค้นหาในเดือนนี้
    $sStmt = $pdo->prepare('SELECT COUNT(*) FROM searchlog WHERE createdAt >= :s AND createdAt < :e');
    $sStmt->execute([':s' => $start . ' 00:00:00', ':e' => $end . ' 00:00:00']);
    $searchesInMonth = (int) $sStmt->fetchColumn();

    // ความพึงพอใจในเดือนนี้
    $fStmt = $pdo->prepare('
        SELECT
            COUNT(*) AS total_cnt,
            COALESCE(SUM(CASE WHEN helpful = 1 THEN 1 ELSE 0 END), 0) AS helpful_cnt
        FROM article_feedback
        WHERE created_at >= :s AND created_at < :e
    ');
    $fStmt->execute([':s' => $start . ' 00:00:00', ':e' => $end . ' 00:00:00']);
    $fRow    = $fStmt->fetch();
    $fTotal  = (int) ($fRow['total_cnt'] ?? 0);
    $fHelp   = (int) ($fRow['helpful_cnt'] ?? 0);
    $fPct    = $fTotal > 0 ? (int) round(($fHelp / $fTotal) * 100) : 0;

    // รายการบทความที่เผยแพร่/สร้างในเดือนนี้ หรือมียอดเข้าชมในเดือนนี้
    $artStmt = $pdo->prepare("
        SELECT a.*, c.category_name, c.description AS category_description,
               COALESCE(mv.month_views, 0) AS month_views
        FROM articles a
        INNER JOIN categories c ON c.category_id = a.category_id
        LEFT JOIN (
            SELECT article_id, SUM(count) AS month_views
            FROM article_view_daily
            WHERE day >= :s1 AND day < :e1
            GROUP BY article_id
        ) mv ON mv.article_id = a.article_id
        WHERE (COALESCE(a.published_at, a.created_at) >= :s2 AND COALESCE(a.published_at, a.created_at) < :e2)
           OR COALESCE(mv.month_views, 0) > 0
        ORDER BY COALESCE(mv.month_views, 0) DESC, COALESCE(a.published_at, a.created_at) DESC
    ");
    $artStmt->execute([
        ':s1' => $start,
        ':e1' => $end,
        ':s2' => $start . ' 00:00:00',
        ':e2' => $end . ' 00:00:00',
    ]);
    $rawArticles = $artStmt->fetchAll();

    $pubInMonthStmt = $pdo->prepare("
        SELECT COUNT(*) FROM articles
        WHERE status = 'PUBLISHED'
          AND COALESCE(published_at, created_at) >= :s
          AND COALESCE(published_at, created_at) < :e
    ");
    $pubInMonthStmt->execute([':s' => $start . ' 00:00:00', ':e' => $end . ' 00:00:00']);
    $publishedInMonthCount = (int) $pubInMonthStmt->fetchColumn();

    $articlesInMonth = [];
    foreach ($rawArticles as $r) {
        $item = formatArticleRow($r);
        $item['categoryName'] = (string) $r['category_name'];
        $item['monthViews']   = (int) $r['month_views'];
        $articlesInMonth[]    = $item;
    }

    return [
        'month'           => $selectedMonth,
        'monthLabel'      => formatThaiMonthLabel($selectedMonth, false),
        'availableMonths' => $availableMonths,
        'summary'         => [
            'publishedInMonthCount'      => $publishedInMonthCount,
            'totalViewsInMonth'          => $totalViewsInMonth,
            'searchesInMonthCount'       => $searchesInMonth,
            'satisfactionPercentInMonth' => $fPct,
            'feedbackHelpfulInMonth'     => $fHelp,
            'feedbackTotalInMonth'       => $fTotal,
        ],
        'articlesInMonth' => $articlesInMonth,
    ];
}

function handleAdminMonthlyReport(PDO $pdo): never
{
    requireStaffAuth($pdo);
    $month = trim((string) ($_GET['month'] ?? ''));
    sendJson(getMonthlyReportData($pdo, $month));
}

function handleAdminGetArticles(PDO $pdo): never
{
    requireStaffAuth($pdo);
    $sql = "
        SELECT a.*, c.category_name, c.description AS category_description
        FROM articles a
        INNER JOIN categories c ON c.category_id = a.category_id
        ORDER BY a.updated_at DESC, a.article_id DESC
    ";
    $rows = $pdo->query($sql)->fetchAll();
    sendJson(array_map('formatArticleRow', $rows));
}

function parseStepsInput(mixed $raw): array
{
    if (is_array($raw)) {
        return array_values(array_filter(array_map('trim', array_map('strval', $raw)), static fn(string $s): bool => $s !== ''));
    }
    $str = trim((string) $raw);
    if ($str === '') {
        return [];
    }
    if (str_starts_with($str, '[')) {
        $decoded = json_decode($str, true);
        if (is_array($decoded)) {
            return parseStepsInput($decoded);
        }
    }
    $lines = preg_split('/\r?\n/', $str) ?: [];
    return array_values(array_filter(array_map('trim', $lines), static fn(string $s): bool => $s !== ''));
}

function parseKeywordsInput(mixed $raw): array
{
    if (is_array($raw)) {
        return array_values(array_filter(array_map('trim', array_map('strval', $raw)), static fn(string $s): bool => $s !== ''));
    }
    $str = trim((string) $raw);
    if ($str === '') {
        return [];
    }
    if (str_starts_with($str, '[')) {
        $decoded = json_decode($str, true);
        if (is_array($decoded)) {
            return parseKeywordsInput($decoded);
        }
    }
    $parts = explode(',', $str);
    return array_values(array_filter(array_map('trim', $parts), static fn(string $s): bool => $s !== ''));
}

function validateYoutubeOrExternalUrl(?string $url): ?string
{
    if ($url === null || trim($url) === '') {
        return null;
    }
    $trimmed = trim($url);
    if (mb_strlen($trimmed, 'UTF-8') > 1024) {
        sendJson(['ok' => false, 'message' => 'ลิงก์วิดีโอมีความยาวเกินกำหนด (สูงสุด 1,024 ตัวอักษร)'], 400);
    }
    if (filter_var($trimmed, FILTER_VALIDATE_URL) === false || !preg_match('#^https?://#i', $trimmed)) {
        sendJson(['ok' => false, 'message' => 'รูปแบบลิงก์วิดีโอไม่ถูกต้อง ต้องขึ้นต้นด้วย http:// หรือ https://'], 400);
    }
    return $trimmed;
}

function fetchSingleFormattedArticleAdmin(PDO $pdo, int $articleId): array
{
    $stmt = $pdo->prepare("
        SELECT a.*, c.category_name, c.description AS category_description
        FROM articles a
        INNER JOIN categories c ON c.category_id = a.category_id
        WHERE a.article_id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $articleId]);
    $row = $stmt->fetch();
    if (!$row) {
        sendJson(['ok' => false, 'message' => 'ไม่พบบทความนี้ในระบบ'], 404);
    }
    return formatArticleRow($row);
}

function handleAdminCreateArticle(PDO $pdo): never
{
    $admin = requireStaffAuth($pdo);
    verifyCsrfToken();
    $actorLabel = formatAdminActorLabel($admin);

    $title      = trim((string) ($_POST['title'] ?? ''));
    $categoryId = (int) ($_POST['categoryId'] ?? 0);
    $status     = strtoupper(trim((string) ($_POST['status'] ?? 'DRAFT')));
    $content    = trim((string) ($_POST['content'] ?? ''));
    $steps      = parseStepsInput($_POST['steps'] ?? '');
    $keywords   = parseKeywordsInput($_POST['keywords'] ?? '');
    $videoUrl   = validateYoutubeOrExternalUrl(isset($_POST['videoUrl']) ? (string) $_POST['videoUrl'] : null);
    $featured   = in_array(strtolower((string) ($_POST['featured'] ?? 'false')), ['1', 'true', 'on'], true) ? 1 : 0;

    if ($title === '' || mb_strlen($title, 'UTF-8') > 240) {
        sendJson(['ok' => false, 'message' => 'กรุณาระบุชื่อบทความ (ไม่เกิน 240 ตัวอักษร)'], 400);
    }
    if ($content === '') {
        sendJson(['ok' => false, 'message' => 'กรุณากรอกเนื้อหาบทความ'], 400);
    }
    if (!in_array($status, ['DRAFT', 'PUBLISHED', 'HIDDEN'], true)) {
        sendJson(['ok' => false, 'message' => 'สถานะบทความไม่ถูกต้อง'], 400);
    }

    $catChk = $pdo->prepare('SELECT category_id FROM categories WHERE category_id = :id LIMIT 1');
    $catChk->execute([':id' => $categoryId]);
    if (!$catChk->fetch()) {
        sendJson(['ok' => false, 'message' => 'หมวดหมู่ที่เลือกไม่มีอยู่ในระบบ'], 400);
    }

    $imageFiles = normalizeMultiFileUpload('images');
    if (count($imageFiles) > MAX_IMAGES_PER_ARTICLE) {
        sendJson(['ok' => false, 'message' => 'อัปโหลดรูปภาพประกอบได้สูงสุดไม่เกิน 5 รูปต่อบทความ'], 400);
    }

    $savedTracker = [];
    try {
        $uploadedImages = [];
        foreach ($imageFiles as $imgFile) {
            $uploadedImages[] = validateAndSaveImage($imgFile, $savedTracker);
        }

        $pdfPath = null;
        if (isset($_FILES['pdfFile']) && ((int) ($_FILES['pdfFile']['error'] ?? UPLOAD_ERR_NO_FILE)) !== UPLOAD_ERR_NO_FILE) {
            $pdfPath = validateAndSavePdf($_FILES['pdfFile'], $savedTracker);
        }

        $primaryImage = $uploadedImages[0] ?? null;
        $publishedAt  = $status === 'PUBLISHED' ? date('Y-m-d H:i:s') : null;

        $stmt = $pdo->prepare('
            INSERT INTO articles
                (category_id, title, content, steps, keywords, image, images, pdf_file, video_url, view_count, status, featured, created_by_name, updated_by_name, published_at, created_at, updated_at)
            VALUES
                (:cat, :title, :content, :steps, :keywords, :image, :images, :pdf, :video, 0, :status, :featured, :cby, :uby, :pubAt, NOW(6), NOW(6))
        ');
        $stmt->execute([
            ':cat'      => $categoryId,
            ':title'    => $title,
            ':content'  => $content,
            ':steps'    => json_encode($steps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':keywords' => json_encode($keywords, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':image'    => $primaryImage,
            ':images'   => json_encode($uploadedImages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':pdf'      => $pdfPath,
            ':video'    => $videoUrl,
            ':status'   => $status,
            ':featured' => $featured,
            ':cby'      => $actorLabel,
            ':uby'      => $actorLabel,
            ':pubAt'    => $publishedAt,
        ]);

        $newId = (int) $pdo->lastInsertId();
        logAdminAction($pdo, $admin, 'CREATE_ARTICLE', 'article', $newId, "สร้างบทความใหม่: {$title} ({$status})");
        sendJson(fetchSingleFormattedArticleAdmin($pdo, $newId), 201);
    } catch (Throwable $e) {
        foreach ($savedTracker as $fp) {
            if (is_file($fp)) {
                @unlink($fp);
            }
        }
        $msg = $e instanceof RuntimeException ? $e->getMessage() : 'เกิดข้อผิดพลาดในการบันทึกบทความ';
        sendJson(['ok' => false, 'message' => $msg], 400);
    }
}

function handleAdminUpdateArticle(PDO $pdo, int $articleId): never
{
    $admin = requireStaffAuth($pdo);
    verifyCsrfToken();
    $actorLabel = formatAdminActorLabel($admin);

    $curStmt = $pdo->prepare('SELECT * FROM articles WHERE article_id = :id LIMIT 1');
    $curStmt->execute([':id' => $articleId]);
    $current = $curStmt->fetch();
    if (!$current) {
        sendJson(['ok' => false, 'message' => 'ไม่พบบทความที่ต้องการแก้ไข'], 404);
    }

    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));

    // กรณีส่ง JSON มาเปลี่ยนสถานะด่วน (เช่น เปลี่ยนเป็น PUBLISHED / DRAFT / HIDDEN จากตาราง)
    if (str_contains($contentType, 'application/json')) {
        $body = readJsonBody();
        $newStatus   = isset($body['status']) ? strtoupper(trim((string) $body['status'])) : (string) $current['status'];
        $newFeatured = array_key_exists('featured', $body) ? ($body['featured'] ? 1 : 0) : (int) $current['featured'];

        if (!in_array($newStatus, ['DRAFT', 'PUBLISHED', 'HIDDEN'], true)) {
            sendJson(['ok' => false, 'message' => 'สถานะบทความไม่ถูกต้อง'], 400);
        }

        $pubAt = $current['published_at'];
        if ($newStatus === 'PUBLISHED' && empty($pubAt)) {
            $pubAt = date('Y-m-d H:i:s');
        }

        $upd = $pdo->prepare('
            UPDATE articles
            SET status = :status, featured = :featured, updated_by_name = :uby, published_at = :pubAt, updated_at = NOW(6)
            WHERE article_id = :id
        ');
        $upd->execute([
            ':status'   => $newStatus,
            ':featured' => $newFeatured,
            ':uby'      => $actorLabel,
            ':pubAt'    => $pubAt,
            ':id'       => $articleId,
        ]);

        logAdminAction($pdo, $admin, 'STATUS_ARTICLE', 'article', $articleId, "เปลี่ยนสถานะบทความ #{$articleId} “{$current['title']}” เป็น {$newStatus}");
        sendJson(fetchSingleFormattedArticleAdmin($pdo, $articleId));
    }

    // กรณีส่ง FormData จากหน้าแก้ไขบทความเต็มรูปแบบ
    $title      = trim((string) ($_POST['title'] ?? $current['title']));
    $categoryId = isset($_POST['categoryId']) ? (int) $_POST['categoryId'] : (int) $current['category_id'];
    $status     = isset($_POST['status']) ? strtoupper(trim((string) $_POST['status'])) : (string) $current['status'];
    $content    = trim((string) ($_POST['content'] ?? $current['content']));
    $steps      = array_key_exists('steps', $_POST) ? parseStepsInput($_POST['steps']) : decodeJsonList($current['steps']);
    $keywords   = array_key_exists('keywords', $_POST) ? parseKeywordsInput($_POST['keywords']) : decodeJsonList($current['keywords']);
    $videoUrl   = array_key_exists('videoUrl', $_POST)
        ? validateYoutubeOrExternalUrl((string) $_POST['videoUrl'])
        : ($current['video_url'] !== null ? (string) $current['video_url'] : null);
    $featured   = array_key_exists('featured', $_POST)
        ? (in_array(strtolower((string) $_POST['featured']), ['1', 'true', 'on'], true) ? 1 : 0)
        : (int) $current['featured'];

    if ($title === '' || mb_strlen($title, 'UTF-8') > 240) {
        sendJson(['ok' => false, 'message' => 'กรุณาระบุชื่อบทความ (ไม่เกิน 240 ตัวอักษร)'], 400);
    }
    if ($content === '') {
        sendJson(['ok' => false, 'message' => 'กรุณากรอกเนื้อหาบทความ'], 400);
    }
    if (!in_array($status, ['DRAFT', 'PUBLISHED', 'HIDDEN'], true)) {
        sendJson(['ok' => false, 'message' => 'สถานะบทความไม่ถูกต้อง'], 400);
    }

    $catChk = $pdo->prepare('SELECT category_id FROM categories WHERE category_id = :id LIMIT 1');
    $catChk->execute([':id' => $categoryId]);
    if (!$catChk->fetch()) {
        sendJson(['ok' => false, 'message' => 'หมวดหมู่ที่เลือกไม่มีอยู่ในระบบ'], 400);
    }

    $oldImages = decodeJsonList($current['images'] ?? null);
    if (empty($oldImages) && !empty($current['image'])) {
        $oldImages = [(string) $current['image']];
    }
    $oldImages = array_values(array_filter(array_map('resolveExistingUploadPath', $oldImages)));

    // รายการรูปภาพเดิมที่ผู้ใช้เลือกเก็บไว้
    if (array_key_exists('existingImages', $_POST)) {
        $requestedKeep = decodeJsonList($_POST['existingImages']);
        $keptImages = [];
        foreach ($requestedKeep as $imgPath) {
            $norm = resolveExistingUploadPath($imgPath);
            if ($norm !== null && in_array($norm, $oldImages, true) && !in_array($norm, $keptImages, true)) {
                $keptImages[] = $norm;
            }
        }
    } else {
        $keptImages = $oldImages;
    }

    $newImageFiles = normalizeMultiFileUpload('images');
    if (count($keptImages) + count($newImageFiles) > MAX_IMAGES_PER_ARTICLE) {
        sendJson([
            'ok'      => false,
            'message' => 'จำนวนรูปภาพรวมต้องไม่เกิน 5 รูปต่อบทความ (ปัจจุบันมีอยู่ ' . count($keptImages) . ' รูป)',
        ], 400);
    }

    $oldPdf    = resolveExistingUploadPath(isset($current['pdf_file']) ? (string) $current['pdf_file'] : null);
    $removePdf = in_array(strtolower((string) ($_POST['removePdf'] ?? 'false')), ['1', 'true', 'on'], true);
    $finalPdf  = $removePdf ? null : $oldPdf;

    $savedTracker = [];
    try {
        $newUploadedImages = [];
        foreach ($newImageFiles as $imgFile) {
            $newUploadedImages[] = validateAndSaveImage($imgFile, $savedTracker);
        }

        if (isset($_FILES['pdfFile']) && ((int) ($_FILES['pdfFile']['error'] ?? UPLOAD_ERR_NO_FILE)) !== UPLOAD_ERR_NO_FILE) {
            $finalPdf = validateAndSavePdf($_FILES['pdfFile'], $savedTracker);
        }

        $finalImages  = array_values(array_merge($keptImages, $newUploadedImages));
        $primaryImage = $finalImages[0] ?? null;

        $pubAt = $current['published_at'];
        if ($status === 'PUBLISHED' && empty($pubAt)) {
            $pubAt = date('Y-m-d H:i:s');
        }

        $upd = $pdo->prepare('
            UPDATE articles
            SET category_id = :cat,
                title = :title,
                content = :content,
                steps = :steps,
                keywords = :keywords,
                image = :image,
                images = :images,
                pdf_file = :pdf,
                video_url = :video,
                status = :status,
                featured = :featured,
                updated_by_name = :uby,
                published_at = :pubAt,
                updated_at = NOW(6)
            WHERE article_id = :id
        ');
        $upd->execute([
            ':cat'      => $categoryId,
            ':title'    => $title,
            ':content'  => $content,
            ':steps'    => json_encode($steps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':keywords' => json_encode($keywords, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':image'    => $primaryImage,
            ':images'   => json_encode($finalImages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':pdf'      => $finalPdf,
            ':video'    => $videoUrl,
            ':status'   => $status,
            ':featured' => $featured,
            ':uby'      => $actorLabel,
            ':pubAt'    => $pubAt,
            ':id'       => $articleId,
        ]);

        // ลบไฟล์เดิมที่ถูกถอดออกจากบทความนี้และไม่มีบทความอื่นใช้งาน
        foreach ($oldImages as $oldImg) {
            if (!in_array($oldImg, $finalImages, true)) {
                deleteOrphanedUploadIfUnreferenced($pdo, $oldImg, $articleId);
            }
        }
        if ($oldPdf !== null && $oldPdf !== $finalPdf) {
            deleteOrphanedUploadIfUnreferenced($pdo, $oldPdf, $articleId);
        }

        logAdminAction($pdo, $admin, 'UPDATE_ARTICLE', 'article', $articleId, "แก้ไขบทความ #{$articleId}: {$title} ({$status})");
        sendJson(fetchSingleFormattedArticleAdmin($pdo, $articleId));
    } catch (Throwable $e) {
        foreach ($savedTracker as $fp) {
            if (is_file($fp)) {
                @unlink($fp);
            }
        }
        $msg = $e instanceof RuntimeException ? $e->getMessage() : 'เกิดข้อผิดพลาดในการอัปเดตบทความ';
        sendJson(['ok' => false, 'message' => $msg], 400);
    }
}

function handleAdminDeleteArticle(PDO $pdo, int $articleId): never
{
    $admin = requireStaffAuth($pdo);
    verifyCsrfToken();

    $stmt = $pdo->prepare('SELECT title, image, images, pdf_file FROM articles WHERE article_id = :id LIMIT 1');
    $stmt->execute([':id' => $articleId]);
    $row = $stmt->fetch();
    if (!$row) {
        sendJson(['ok' => false, 'message' => 'ไม่พบบทความที่ต้องการลบ'], 404);
    }

    $del = $pdo->prepare('DELETE FROM articles WHERE article_id = :id');
    $del->execute([':id' => $articleId]);

    $imgs = decodeJsonList($row['images'] ?? null);
    if (!empty($row['image'])) {
        $imgs[] = (string) $row['image'];
    }
    foreach (array_unique($imgs) as $img) {
        deleteOrphanedUploadIfUnreferenced($pdo, $img, $articleId);
    }
    if (!empty($row['pdf_file'])) {
        deleteOrphanedUploadIfUnreferenced($pdo, (string) $row['pdf_file'], $articleId);
    }

    logAdminAction($pdo, $admin, 'DELETE_ARTICLE', 'article', $articleId, 'ลบบทความ: ' . (string) ($row['title'] ?? '#' . $articleId));
    sendJson(['ok' => true]);
}

function handleAdminGetCategories(PDO $pdo): never
{
    requireStaffAuth($pdo);
    $sql = "
        SELECT c.category_id, c.category_name, c.description, c.created_at,
               COUNT(a.article_id) AS total_articles,
               COALESCE(SUM(CASE WHEN a.status = 'PUBLISHED' THEN 1 ELSE 0 END), 0) AS published_articles
        FROM categories c
        LEFT JOIN articles a ON a.category_id = c.category_id
        GROUP BY c.category_id, c.category_name, c.description, c.created_at
        ORDER BY c.category_id ASC
    ";
    $rows = $pdo->query($sql)->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'categoryId'   => (int) $r['category_id'],
            'categoryName' => (string) $r['category_name'],
            'description'  => $r['description'],
            'createdAt'    => toIsoDate($r['created_at'] ?? null),
            '_count'       => [
                'articles'  => (int) $r['total_articles'],
                'published' => (int) $r['published_articles'],
            ],
        ];
    }
    sendJson($out);
}

function handleAdminCreateCategory(PDO $pdo): never
{
    $admin = requireStaffAuth($pdo);
    verifyCsrfToken();

    $body = readJsonBody();
    $name = trim((string) ($body['categoryName'] ?? ''));
    $desc = trim((string) ($body['description'] ?? ''));

    if ($name === '' || mb_strlen($name, 'UTF-8') > 160) {
        sendJson(['ok' => false, 'message' => 'กรุณาระบุชื่อหมวดหมู่ (ไม่เกิน 160 ตัวอักษร)'], 400);
    }

    $dup = $pdo->prepare('SELECT category_id FROM categories WHERE category_name = :name LIMIT 1');
    $dup->execute([':name' => $name]);
    if ($dup->fetch()) {
        sendJson(['ok' => false, 'message' => 'ชื่อหมวดหมู่นี้มีอยู่ในระบบแล้ว'], 400);
    }

    $ins = $pdo->prepare('INSERT INTO categories (category_name, description, created_at) VALUES (:name, :desc, NOW(6))');
    $ins->execute([
        ':name' => $name,
        ':desc' => $desc !== '' ? $desc : null,
    ]);

    $id = (int) $pdo->lastInsertId();
    logAdminAction($pdo, $admin, 'CREATE_CATEGORY', 'category', $id, "เพิ่มหมวดหมู่: {$name}");
    sendJson([
        'categoryId'   => $id,
        'categoryName' => $name,
        'description'  => $desc !== '' ? $desc : null,
        '_count'       => ['articles' => 0],
    ], 201);
}

function handleAdminUpdateCategory(PDO $pdo, int $categoryId): never
{
    $admin = requireStaffAuth($pdo);
    verifyCsrfToken();

    $chk = $pdo->prepare('SELECT category_id FROM categories WHERE category_id = :id LIMIT 1');
    $chk->execute([':id' => $categoryId]);
    if (!$chk->fetch()) {
        sendJson(['ok' => false, 'message' => 'ไม่พบหมวดหมู่ที่ต้องการแก้ไข'], 404);
    }

    $body = readJsonBody();
    $name = trim((string) ($body['categoryName'] ?? ''));
    $desc = trim((string) ($body['description'] ?? ''));

    if ($name === '' || mb_strlen($name, 'UTF-8') > 160) {
        sendJson(['ok' => false, 'message' => 'กรุณาระบุชื่อหมวดหมู่ (ไม่เกิน 160 ตัวอักษร)'], 400);
    }

    $dup = $pdo->prepare('SELECT category_id FROM categories WHERE category_name = :name AND category_id <> :id LIMIT 1');
    $dup->execute([':name' => $name, ':id' => $categoryId]);
    if ($dup->fetch()) {
        sendJson(['ok' => false, 'message' => 'ชื่อหมวดหมู่นี้ซ้ำกับหมวดหมู่อื่นในระบบ'], 400);
    }

    $upd = $pdo->prepare('UPDATE categories SET category_name = :name, description = :desc WHERE category_id = :id');
    $upd->execute([
        ':name' => $name,
        ':desc' => $desc !== '' ? $desc : null,
        ':id'   => $categoryId,
    ]);

    logAdminAction($pdo, $admin, 'UPDATE_CATEGORY', 'category', $categoryId, "แก้ไขหมวดหมู่ #{$categoryId}: {$name}");
    sendJson([
        'categoryId'   => $categoryId,
        'categoryName' => $name,
        'description'  => $desc !== '' ? $desc : null,
    ]);
}

function handleAdminDeleteCategory(PDO $pdo, int $categoryId): never
{
    $admin = requireStaffAuth($pdo);
    verifyCsrfToken();

    $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM articles WHERE category_id = :id');
    $cntStmt->execute([':id' => $categoryId]);
    $articleCount = (int) $cntStmt->fetchColumn();

    if ($articleCount > 0) {
        sendJson([
            'ok'      => false,
            'message' => "ไม่สามารถลบหมวดหมู่นี้ได้ เนื่องจากมีบทความสังกัดอยู่ {$articleCount} บทความ กรุณาย้ายหรือลบบทความก่อน",
        ], 400);
    }

    $nameStmt = $pdo->prepare('SELECT category_name FROM categories WHERE category_id = :id LIMIT 1');
    $nameStmt->execute([':id' => $categoryId]);
    $catName = (string) ($nameStmt->fetchColumn() ?: "#{$categoryId}");

    $del = $pdo->prepare('DELETE FROM categories WHERE category_id = :id');
    $del->execute([':id' => $categoryId]);
    if ($del->rowCount() === 0) {
        sendJson(['ok' => false, 'message' => 'ไม่พบหมวดหมู่ที่ต้องการลบ'], 404);
    }

    logAdminAction($pdo, $admin, 'DELETE_CATEGORY', 'category', $categoryId, "ลบหมวดหมู่: {$catName}");
    sendJson(['ok' => true]);
}

function handleAdminGetSearches(PDO $pdo): never
{
    requireStaffAuth($pdo);

    $q      = trim((string) ($_GET['q'] ?? ''));
    $filter = strtolower(trim((string) ($_GET['filter'] ?? 'all'))); // all | zero | found

    $where  = ['1=1'];
    $params = [];

    if ($q !== '') {
        $where[]      = 'term LIKE :q';
        $params[':q'] = '%' . addcslashes($q, '%_\\') . '%';
    }
    if ($filter === 'zero') {
        $where[] = 'resultCount = 0';
    } elseif ($filter === 'found') {
        $where[] = 'resultCount > 0';
    }

    $whereSql = implode(' AND ', $where);

    $grpSql = "
        SELECT
            MIN(term) AS display_term,
            LOWER(TRIM(term)) AS norm_term,
            COUNT(*) AS search_count,
            MAX(resultCount) AS max_results,
            MIN(resultCount) AS min_results,
            MAX(createdAt) AS last_searched_at
        FROM searchlog
        WHERE {$whereSql}
        GROUP BY LOWER(TRIM(term))
        ORDER BY search_count DESC, last_searched_at DESC
        LIMIT 100
    ";
    $grpStmt = $pdo->prepare($grpSql);
    $grpStmt->execute($params);

    $terms = [];
    foreach ($grpStmt->fetchAll() as $r) {
        $terms[] = [
            'term'           => (string) $r['display_term'],
            'normTerm'       => (string) $r['norm_term'],
            'searchCount'    => (int) $r['search_count'],
            'resultCount'    => (int) $r['max_results'],
            'hasZeroResults' => ((int) $r['max_results']) === 0,
            'lastSearchedAt' => toIsoDate($r['last_searched_at'] ?? null),
        ];
    }

    $sumRow = $pdo->query("
        SELECT
            COUNT(*) AS total_logs,
            COUNT(DISTINCT LOWER(TRIM(term))) AS unique_terms,
            COUNT(DISTINCT CASE WHEN resultCount = 0 THEN LOWER(TRIM(term)) END) AS zero_terms
        FROM searchlog
    ")->fetch();

    sendJson([
        'items'   => $terms,
        'summary' => [
            'totalSearches'   => (int) ($sumRow['total_logs'] ?? 0),
            'uniqueTerms'     => (int) ($sumRow['unique_terms'] ?? 0),
            'zeroResultTerms' => (int) ($sumRow['zero_terms'] ?? 0),
        ],
    ]);
}

function handleAdminDeleteSearches(PDO $pdo): never
{
    requireStaffAuth($pdo);
    verifyCsrfToken();

    $body      = readJsonBody();
    $term      = trim((string) ($body['term'] ?? ($_GET['term'] ?? '')));
    $id        = isset($body['id']) ? (int) $body['id'] : (isset($_GET['id']) ? (int) $_GET['id'] : 0);
    $clearZero = !empty($body['clearZero']) || (($_GET['clearZero'] ?? '') === '1');

    if ($id > 0) {
        $stmt = $pdo->prepare('DELETE FROM searchlog WHERE id = :id');
        $stmt->execute([':id' => $id]);
        sendJson(['ok' => true, 'deleted' => $stmt->rowCount()]);
    }

    if ($clearZero) {
        $stmt = $pdo->prepare('DELETE FROM searchlog WHERE resultCount = 0');
        $stmt->execute();
        sendJson(['ok' => true, 'deleted' => $stmt->rowCount()]);
    }

    if ($term !== '') {
        $stmt = $pdo->prepare('DELETE FROM searchlog WHERE LOWER(TRIM(term)) = LOWER(TRIM(:term))');
        $stmt->execute([':term' => $term]);
        sendJson(['ok' => true, 'deleted' => $stmt->rowCount()]);
    }

    sendJson(['ok' => false, 'message' => 'กรุณาระบุคำค้นหาที่ต้องการลบ'], 400);
}

function handleAdminUpdateContactSettings(PDO $pdo): never
{
    $admin = requireStaffAuth($pdo);
    verifyCsrfToken();

    $body = readJsonBody();
    $keys = ['phone', 'lineId', 'lineUrl', 'email', 'location', 'hours', 'note'];
    $payload = [];
    foreach ($keys as $k) {
        $payload[$k] = trim((string) ($body[$k] ?? ''));
    }

    $jsonVal = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $pdo->prepare("
        INSERT INTO sitesetting (`key`, `value`, updatedAt)
        VALUES ('contact', :val, NOW(3))
        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updatedAt = NOW(3)
    ");
    $stmt->execute([':val' => $jsonVal]);

    logAdminAction($pdo, $admin, 'UPDATE_CONTACT', 'settings', null, 'อัปเดตข้อมูลติดต่อศูนย์ ICT');
    sendJson($payload);
}

function handleAdminUpdateAnnouncementSettings(PDO $pdo): never
{
    $admin = requireStaffAuth($pdo);
    verifyCsrfToken();

    $body      = readJsonBody();
    $enabled   = filter_var($body['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $level     = strtolower(trim((string) ($body['level'] ?? 'warning')));
    $title     = mb_substr(trim((string) ($body['title'] ?? '')), 0, 160, 'UTF-8');
    $message   = mb_substr(trim((string) ($body['message'] ?? '')), 0, 600, 'UTF-8');
    $linkUrl   = mb_substr(trim((string) ($body['linkUrl'] ?? '')), 0, 500, 'UTF-8');
    $linkLabel = mb_substr(trim((string) ($body['linkLabel'] ?? '')), 0, 80, 'UTF-8');

    if (!in_array($level, ['warning', 'info', 'urgent'], true)) {
        $level = 'warning';
    }
    if ($enabled && $title === '' && $message === '') {
        sendJson(['ok' => false, 'message' => 'กรุณาระบุหัวข้อหรือข้อความประกาศก่อนเปิดแสดงหน้าเว็บ'], 400);
    }
    if ($linkUrl !== '' && !preg_match('#^(https?://|/|\#)#i', $linkUrl)) {
        sendJson(['ok' => false, 'message' => 'ลิงก์เพิ่มเติมต้องขึ้นต้นด้วย https://, http://, / หรือ #'], 400);
    }

    $payload = [
        'enabled'   => $enabled,
        'level'     => $level,
        'title'     => $title !== '' ? $title : 'แจ้งประชาสัมพันธ์จากศูนย์ ICT เทศบาลนครขอนแก่น',
        'message'   => $message,
        'linkUrl'   => $linkUrl,
        'linkLabel' => $linkLabel !== '' ? $linkLabel : 'ดูรายละเอียด',
        'updatedBy' => formatAdminActorLabel($admin),
        'updatedAt' => date('c'),
    ];

    $jsonVal = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $pdo->prepare("
        INSERT INTO sitesetting (`key`, `value`, updatedAt)
        VALUES ('announcement', :val, NOW(3))
        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updatedAt = NOW(3)
    ");
    $stmt->execute([':val' => $jsonVal]);

    $logDetail = $enabled
        ? "เปิดแถบประกาศหน้าเว็บ ({$level}): {$payload['title']}"
        : 'ปิดแถบประกาศแจ้งเตือนหน้าเว็บ';
    logAdminAction($pdo, $admin, 'UPDATE_ANNOUNCEMENT', 'settings', null, $logDetail);

    sendJson($payload);
}

function handleAdminGetAuditLogs(PDO $pdo): never
{
    requireStaffAuth($pdo);
    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 30)));

    try {
        $stmt = $pdo->prepare('
            SELECT log_id, admin_id, admin_name, action, target_type, target_id, detail, created_at
            FROM audit_logs
            ORDER BY log_id DESC
            LIMIT :lim
        ');
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll() as $r) {
            $items[] = [
                'logId'      => (int) $r['log_id'],
                'adminId'    => $r['admin_id'] !== null ? (int) $r['admin_id'] : null,
                'adminName'  => (string) $r['admin_name'],
                'action'     => (string) $r['action'],
                'targetType' => (string) $r['target_type'],
                'targetId'   => $r['target_id'] !== null ? (int) $r['target_id'] : null,
                'detail'     => (string) ($r['detail'] ?? ''),
                'createdAt'  => toIsoDate($r['created_at'] ?? null),
            ];
        }
        sendJson(['items' => $items]);
    } catch (Throwable) {
        sendJson(['items' => []]);
    }
}

function handleAdminExportBackup(PDO $pdo): never
{
    $admin  = requireStaffAuth($pdo);
    $format = strtolower(trim((string) ($_GET['format'] ?? 'sql')));
    if (!in_array($format, ['sql', 'json'], true)) {
        sendJson(['ok' => false, 'message' => 'รูปแบบไฟล์สำรองข้อมูลไม่ถูกต้อง (รองรับ sql และ json)'], 400);
    }

    logAdminAction($pdo, $admin, 'EXPORT_BACKUP', 'backup', null, 'ดาวน์โหลดไฟล์สำรองข้อมูลระบบ (' . strtoupper($format) . ')');

    $stamp = date('Ymd-His');
    $existingTables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $preferredOrder = [
        'categories',
        'admins',
        'articles',
        'article_feedback',
        'article_view_daily',
        'searchlog',
        'sitesetting',
        'login_attempts',
        'audit_logs',
    ];
    $orderedTables = [];
    foreach ($preferredOrder as $t) {
        if (in_array($t, $existingTables, true)) {
            $orderedTables[] = $t;
        }
    }
    foreach ($existingTables as $t) {
        if (!in_array($t, $orderedTables, true)) {
            $orderedTables[] = (string) $t;
        }
    }

    if ($format === 'json') {
        $filename = "kkict-backup-{$stamp}.json";
        $exportData = [
            'meta' => [
                'project'    => 'คลังความรู้ด้าน ICT — ศูนย์พัฒนาทักษะและการเรียนรู้ ICT เทศบาลนครขอนแก่น',
                'database'   => DB_NAME,
                'exportedAt' => date('c'),
                'exportedBy' => formatAdminActorLabel($admin),
            ],
            'tables' => [],
        ];

        foreach ($orderedTables as $table) {
            if ($table === 'login_attempts') {
                continue;
            }
            if ($table === 'admins') {
                $exportData['tables']['admins'] = $pdo->query('SELECT admin_id, username, fullname, created_at FROM `admins` ORDER BY admin_id ASC')->fetchAll();
                continue;
            }
            $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll();
            $exportData['tables'][$table] = $rows;
        }

        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo json_encode($exportData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    // Export .sql Dump มาตรฐานพร้อมกู้คืนทันที
    $filename = "kkict-backup-{$stamp}.sql";
    http_response_code(200);
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $out = fopen('php://output', 'wb');
    if ($out === false) {
        exit;
    }

    $dbNameEsc = str_replace('`', '``', (string) DB_NAME);
    fwrite($out, "-- =====================================================================\n");
    fwrite($out, "-- KKICT Knowledge Base — MySQL Full Backup\n");
    fwrite($out, "-- Exported at : " . date('Y-m-d H:i:sP') . "\n");
    fwrite($out, "-- Exported by : " . formatAdminActorLabel($admin) . "\n");
    fwrite($out, "-- =====================================================================\n\n");
    fwrite($out, "SET NAMES utf8mb4;\n");
    fwrite($out, "SET TIME_ZONE = '+07:00';\n");
    fwrite($out, "SET FOREIGN_KEY_CHECKS = 0;\n\n");
    fwrite($out, "CREATE DATABASE IF NOT EXISTS `{$dbNameEsc}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n");
    fwrite($out, "USE `{$dbNameEsc}`;\n\n");

    foreach ($orderedTables as $table) {
        $safeTable = str_replace('`', '``', $table);
        $createRow = $pdo->query("SHOW CREATE TABLE `{$safeTable}`")->fetch(PDO::FETCH_NUM);
        if (!$createRow || empty($createRow[1])) {
            continue;
        }

        fwrite($out, "-- ---------------------------------------------------------------------\n");
        fwrite($out, "-- Table structure for table `{$safeTable}`\n");
        fwrite($out, "-- ---------------------------------------------------------------------\n");
        fwrite($out, "DROP TABLE IF EXISTS `{$safeTable}`;\n");
        fwrite($out, $createRow[1] . ";\n\n");

        $stmt = $pdo->query("SELECT * FROM `{$safeTable}`");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows)) {
            $cols = array_map(static fn(string $c): string => '`' . str_replace('`', '``', $c) . '`', array_keys($rows[0]));
            $colListSql = implode(', ', $cols);

            $chunks = array_chunk($rows, 100);
            foreach ($chunks as $chunk) {
                $tupleStrings = [];
                foreach ($chunk as $row) {
                    $vals = [];
                    foreach ($row as $v) {
                        if ($v === null) {
                            $vals[] = 'NULL';
                        } elseif (is_int($v)) {
                            $vals[] = (string) $v;
                        } else {
                            $vals[] = $pdo->quote((string) $v);
                        }
                    }
                    $tupleStrings[] = '(' . implode(', ', $vals) . ')';
                }
                fwrite($out, "INSERT INTO `{$safeTable}` ({$colListSql}) VALUES\n  " . implode(",\n  ", $tupleStrings) . ";\n");
            }
            fwrite($out, "\n");
        }
    }

    fwrite($out, "SET FOREIGN_KEY_CHECKS = 1;\n");
    fclose($out);
    exit;
}

function handleAdminExportCsv(PDO $pdo): never
{
    requireStaffAuth($pdo);

    $type  = strtolower(trim((string) ($_GET['type'] ?? 'articles')));
    $month = trim((string) ($_GET['month'] ?? ''));

    $rows         = [];
    $thaiFilename = 'รายงานคลังความรู้-ICT.csv';
    $asciiName    = 'kkict-report.csv';

    if ($type === 'monthly') {
        $report       = getMonthlyReportData($pdo, $month);
        $ym           = $report['month'];
        $thaiFilename = "รายงานประจำเดือน-{$ym}.csv";
        $asciiName    = "kkict-monthly-{$ym}.csv";

        $rows[] = ['ลำดับ', 'รหัสบทความ', 'ชื่อบทความ', 'หมวดหมู่', 'สถานะ', 'ยอดเข้าชมในเดือนนี้', 'ยอดเข้าชมสะสม', 'วันที่เผยแพร่/อัปเดต'];
        foreach ($report['articlesInMonth'] as $idx => $a) {
            $statusLabel = match ($a['status']) {
                'PUBLISHED' => 'เผยแพร่แล้ว',
                'HIDDEN'    => 'ซ่อน',
                default     => 'ฉบับร่าง',
            };
            $rows[] = [
                $idx + 1,
                $a['articleId'],
                $a['title'],
                $a['categoryName'],
                $statusLabel,
                $a['monthViews'],
                $a['viewCount'],
                substr((string) ($a['publishedAt'] ?? $a['updatedAt'] ?? ''), 0, 10),
            ];
        }
    } elseif ($type === 'sixmonths') {
        $six          = buildSixMonthTrend($pdo);
        $thaiFilename = 'รายงานสรุปครึ่งปี-6-เดือน.csv';
        $asciiName    = 'kkict-6months-summary.csv';

        $rows[] = ['เดือน (YYYY-MM)', 'เดือนภาษาไทย', 'ยอดเข้าชม (ครั้ง)', 'จำนวนบทความที่สร้าง/เผยแพร่ (เรื่อง)', 'จำนวนการค้นหา (ครั้ง)'];
        foreach ($six['months'] as $m) {
            $rows[] = [$m['month'], $m['fullLabel'], $m['views'], $m['articleCount'], $m['searchesCount']];
        }
        $rows[] = ['รวม 6 เดือน', $six['periodLabel'], $six['totals']['views'], $six['totals']['articles'], $six['totals']['searches']];
    } else {
        $thaiFilename = 'รายงานคลังความรู้ทั้งหมด.csv';
        $asciiName    = 'kkict-all-articles.csv';

        $sql = "
            SELECT a.*, c.category_name,
                   COALESCE(fb.helpful_cnt, 0) AS helpful_cnt,
                   COALESCE(fb.total_cnt, 0) AS total_cnt
            FROM articles a
            INNER JOIN categories c ON c.category_id = a.category_id
            LEFT JOIN (
                SELECT article_id,
                       SUM(CASE WHEN helpful = 1 THEN 1 ELSE 0 END) AS helpful_cnt,
                       COUNT(*) AS total_cnt
                FROM article_feedback
                GROUP BY article_id
            ) fb ON fb.article_id = a.article_id
            ORDER BY a.article_id ASC
        ";
        $rows[] = ['รหัส', 'ชื่อบทความ', 'หมวดหมู่', 'สถานะ', 'บทความแนะนำ', 'ยอดเข้าชมสะสม', 'โหวตมีประโยชน์', 'โหวตทั้งหมด', 'วันที่อัปเดตล่าสุด'];
        foreach ($pdo->query($sql)->fetchAll() as $r) {
            $statusLabel = match ((string) $r['status']) {
                'PUBLISHED' => 'เผยแพร่แล้ว',
                'HIDDEN'    => 'ซ่อน',
                default     => 'ฉบับร่าง',
            };
            $rows[] = [
                (int) $r['article_id'],
                (string) $r['title'],
                (string) $r['category_name'],
                $statusLabel,
                ((int) $r['featured']) === 1 ? 'ใช่' : 'ไม่ใช่',
                (int) $r['view_count'],
                (int) $r['helpful_cnt'],
                (int) $r['total_cnt'],
                substr((string) $r['updated_at'], 0, 19),
            ];
        }
    }

    http_response_code(200);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($thaiFilename));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $out = fopen('php://output', 'wb');
    if ($out === false) {
        exit;
    }
    // เขียน UTF-8 BOM เพื่อให้ Microsoft Excel บน Windows แสดงภาษาไทยถูกต้องทันที
    fwrite($out, "\xEF\xBB\xBF");
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

// =====================================================================
// ===== 9. ROUTER DISPATCH =====
// =====================================================================
$method = getEffectiveMethod();
$route  = getApiRoutePath();
$pdo    = getPdo();

// --- Health Check ---
if ($method === 'GET' && $route === '/health') {
    $pdo->query('SELECT 1')->fetchColumn();
    sendJson([
        'ok'           => true,
        'status'       => 'ok',
        'db'           => 'connected',
        'baseUrl'      => resolveEffectiveBaseUrl(),
        'csrfToken'    => getCsrfToken(),
        'announcement' => getAnnouncementSettings($pdo),
    ]);
}

// --- Public Routes ---
if ($method === 'GET' && $route === '/categories') {
    handleGetCategories($pdo);
}
if ($method === 'GET' && $route === '/articles') {
    handleGetPublicArticles($pdo);
}
if ($method === 'GET' && $route === '/articles/search') {
    handleSearchArticles($pdo);
}
if ($method === 'GET' && preg_match('#^/articles/(\d+)$#', $route, $m)) {
    handleGetSingleArticle($pdo, (int) $m[1]);
}
if ($method === 'POST' && preg_match('#^/articles/(\d+)/feedback$#', $route, $m)) {
    handlePostArticleFeedback($pdo, (int) $m[1]);
}
if ($method === 'GET' && $route === '/settings/contact') {
    handleGetContactSettings($pdo);
}
if ($method === 'GET' && $route === '/settings/announcement') {
    handleGetAnnouncementSettings($pdo);
}

// --- Auth Routes ---
if ($method === 'POST' && $route === '/auth/login') {
    handleAuthLogin($pdo);
}
if ($method === 'POST' && $route === '/auth/logout') {
    handleAuthLogout();
}
if ($method === 'GET' && $route === '/auth/me') {
    handleAuthMe($pdo);
}
if ($method === 'POST' && $route === '/auth/change-password') {
    handleAuthChangePassword($pdo);
}

// --- Admin Routes ---
if ($method === 'GET' && $route === '/admin/dashboard') {
    handleAdminDashboard($pdo);
}
if ($method === 'GET' && $route === '/admin/reports/monthly') {
    handleAdminMonthlyReport($pdo);
}
if ($method === 'GET' && $route === '/admin/export/csv') {
    handleAdminExportCsv($pdo);
}
if ($method === 'GET' && $route === '/admin/export/backup') {
    handleAdminExportBackup($pdo);
}
if ($method === 'GET' && $route === '/admin/audit-logs') {
    handleAdminGetAuditLogs($pdo);
}
if ($method === 'GET' && $route === '/admin/articles') {
    handleAdminGetArticles($pdo);
}
if ($method === 'GET' && preg_match('#^/admin/articles/(\d+)$#', $route, $m)) {
    requireStaffAuth($pdo);
    sendJson(fetchSingleFormattedArticleAdmin($pdo, (int) $m[1]));
}
if ($method === 'POST' && $route === '/admin/articles') {
    handleAdminCreateArticle($pdo);
}
if (($method === 'PATCH' || $method === 'POST') && preg_match('#^/admin/articles/(\d+)$#', $route, $m)) {
    handleAdminUpdateArticle($pdo, (int) $m[1]);
}
if ($method === 'DELETE' && preg_match('#^/admin/articles/(\d+)$#', $route, $m)) {
    handleAdminDeleteArticle($pdo, (int) $m[1]);
}
if ($method === 'GET' && $route === '/admin/categories') {
    handleAdminGetCategories($pdo);
}
if ($method === 'POST' && $route === '/admin/categories') {
    handleAdminCreateCategory($pdo);
}
if ($method === 'PATCH' && preg_match('#^/admin/categories/(\d+)$#', $route, $m)) {
    handleAdminUpdateCategory($pdo, (int) $m[1]);
}
if ($method === 'DELETE' && preg_match('#^/admin/categories/(\d+)$#', $route, $m)) {
    handleAdminDeleteCategory($pdo, (int) $m[1]);
}
if ($method === 'GET' && $route === '/admin/searches') {
    handleAdminGetSearches($pdo);
}
if ($method === 'DELETE' && $route === '/admin/searches') {
    handleAdminDeleteSearches($pdo);
}
if ($method === 'PUT' && $route === '/admin/settings/contact') {
    handleAdminUpdateContactSettings($pdo);
}
if ($method === 'PUT' && $route === '/admin/settings/announcement') {
    handleAdminUpdateAnnouncementSettings($pdo);
}

sendJson([
    'ok'      => false,
    'message' => 'ไม่พบ Endpoint ที่ร้องขอ: ' . $method . ' ' . $route,
], 404);
