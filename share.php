<?php
declare(strict_types=1);

/**
 * share.php — หน้าสร้าง Open Graph Meta Tags สำหรับแชร์ลง LINE / Facebook
 * ดึงข้อมูลบทความจาก MySQL ตาม ?id=... สร้าง og:title, og:description, og:image, og:url
 * โดยอ้างอิง BASE_URL จาก config.php แล้วพาผู้ใช้อ่านบทความใน index.html ทันที
 */

date_default_timezone_set('Asia/Bangkok');
mb_internal_encoding('UTF-8');

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    header('Location: ./index.html');
    exit;
}
require_once $configFile;

$baseUrl = rtrim((string) BASE_URL, '/');
if (preg_match('#^https?://(?:localhost|127\.0\.0\.1)(?::\d+)?$#i', $baseUrl)) {
    $reqHost = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($reqHost !== '' && preg_match('/^[a-zA-Z0-9.\-]+(?::\d+)?$/', $reqHost)) {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((int) ($_SERVER['SERVER_PORT'] ?? 80) === 443);
        $baseUrl = ($isHttps ? 'https://' : 'http://') . $reqHost;
    }
}
$articleId = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : 0;

$siteName    = 'คลังความรู้ ICT — ศูนย์พัฒนาทักษะและการเรียนรู้ ICT เทศบาลนครขอนแก่น (KKICT)';
$ogTitle     = $siteName;
$ogDesc      = 'บริการข้อมูลคู่มือการใช้งานคอมพิวเตอร์ เครือข่าย อุปกรณ์สำนักงาน และระบบสารสนเทศ เพื่อการเรียนรู้และแก้ไขปัญหาด้วยตนเอง';
$ogImage     = '';
$ogUrl       = $articleId > 0 ? ($baseUrl . '/share.php?id=' . $articleId) : ($baseUrl . '/');
$redirectUrl = $articleId > 0 ? ($baseUrl . '/index.html#article-' . $articleId) : ($baseUrl . '/index.html');

if ($articleId > 0) {
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
            PDO::ATTR_TIMEOUT            => 3,
        ]);
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

        $stmt = $pdo->prepare("
            SELECT a.article_id, a.title, a.content, a.image, a.images, c.category_name
            FROM articles a
            INNER JOIN categories c ON c.category_id = a.category_id
            WHERE a.article_id = :id AND a.status = 'PUBLISHED'
            LIMIT 1
        ");
        $stmt->execute([':id' => $articleId]);
        $article = $stmt->fetch();

        if ($article) {
            $ogTitle = trim((string) $article['title']) . ' | คลังความรู้ ICT ขอนแก่น';

            $cleanText = preg_replace('/^(เกริ่นนำ|Introduction)\s*/iu', '', (string) $article['content']) ?? (string) $article['content'];
            $cleanText = preg_replace('/\s+/u', ' ', trim($cleanText)) ?? '';
            if (mb_strlen($cleanText, 'UTF-8') > 180) {
                $ogDesc = mb_substr($cleanText, 0, 180, 'UTF-8') . '…';
            } elseif ($cleanText !== '') {
                $ogDesc = $cleanText;
            }

            $candidateImg = null;
            if (!empty($article['images'])) {
                $decoded = json_decode((string) $article['images'], true);
                if (is_array($decoded) && !empty($decoded[0])) {
                    $candidateImg = (string) $decoded[0];
                }
            }
            if ($candidateImg === null && !empty($article['image'])) {
                $candidateImg = (string) $article['image'];
            }

            if ($candidateImg !== null) {
                if (preg_match('#^https?://#i', $candidateImg)) {
                    $ogImage = $candidateImg;
                } else {
                    $filename = basename($candidateImg);
                    if (is_file(__DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $filename)) {
                        $ogImage = $baseUrl . '/uploads/' . rawurlencode($filename);
                    }
                }
            }
        } else {
            $redirectUrl = $baseUrl . '/index.html';
        }
    } catch (Throwable $e) {
        // กรณีฐานข้อมูลขัดข้องชั่วคราว ยังคงส่งผู้ใช้ไปยังหน้าเว็บหลักได้โดยไม่ค้าง
    }
}

function escHtml(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= escHtml($ogTitle) ?></title>
  <meta name="description" content="<?= escHtml($ogDesc) ?>" />

  <!-- Open Graph สำหรับ LINE / Facebook -->
  <meta property="og:type" content="article" />
  <meta property="og:site_name" content="<?= escHtml($siteName) ?>" />
  <meta property="og:locale" content="th_TH" />
  <meta property="og:title" content="<?= escHtml($ogTitle) ?>" />
  <meta property="og:description" content="<?= escHtml($ogDesc) ?>" />
  <meta property="og:url" content="<?= escHtml($ogUrl) ?>" />
<?php if ($ogImage !== ''): ?>
  <meta property="og:image" content="<?= escHtml($ogImage) ?>" />
<?php endif; ?>

  <!-- Twitter Card -->
  <meta name="twitter:card" content="<?= $ogImage !== '' ? 'summary_large_image' : 'summary' ?>" />
  <meta name="twitter:title" content="<?= escHtml($ogTitle) ?>" />
  <meta name="twitter:description" content="<?= escHtml($ogDesc) ?>" />
<?php if ($ogImage !== ''): ?>
  <meta name="twitter:image" content="<?= escHtml($ogImage) ?>" />
<?php endif; ?>

  <link rel="canonical" href="<?= escHtml($redirectUrl) ?>" />
  <meta http-equiv="refresh" content="0;url=<?= escHtml($redirectUrl) ?>" />
  <style>
    body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f4f8fb; color: #0f2530; font-family: 'Noto Sans Thai', system-ui, sans-serif; padding: 24px; text-align: center; }
    .card { max-width: 520px; padding: 28px 24px; border: 1px solid #d9e4ec; border-radius: 16px; background: #ffffff; box-shadow: 0 8px 24px rgba(11, 100, 119, .08); }
    h1 { margin: 0 0 10px; color: #084c5d; font-size: 20px; line-height: 1.4; }
    p { margin: 0 0 18px; color: #526875; font-size: 15px; line-height: 1.65; }
    a { display: inline-block; padding: 10px 20px; border-radius: 10px; background: #0b6477; color: #ffffff; text-decoration: none; font-weight: 600; }
  </style>
  <script>
    window.location.replace(<?= json_encode($redirectUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>);
  </script>
</head>
<body>
  <div class="card">
    <h1><?= escHtml($ogTitle) ?></h1>
    <p><?= escHtml($ogDesc) ?></p>
    <a href="<?= escHtml($redirectUrl) ?>">เปิดอ่านคู่มือฉบับเต็ม →</a>
  </div>
</body>
</html>
