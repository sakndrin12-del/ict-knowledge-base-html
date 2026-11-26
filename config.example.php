<?php
declare(strict_types=1);

// ป้องกันการเรียกไฟล์ config โดยตรงผ่าน URL
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}

// ===== การเชื่อมต่อฐานข้อมูล MySQL 8+ / 26.10 =====
define('DB_HOST', '127.0.0.1');          // ใช้ 127.0.0.1 หรือ localhost
define('DB_PORT', 3306);                 // พอร์ตมาตรฐานของ MySQL
define('DB_NAME', 'ict_knowledge_base'); // ชื่อฐานข้อมูล
define('DB_USER', 'root');               // ชื่อผู้ใช้ฐานข้อมูล
define('DB_PASS', 'ใส่รหัสผ่านตรงนี้');    // รหัสผ่านฐานข้อมูล MySQL
define('DB_CHARSET', 'utf8mb4');

// ===== URL หลักของระบบ (ห้ามมีเครื่องหมาย / ปิดท้าย) =====
// ตอนรันในเครื่อง: 'http://localhost:8000'
// ตอนขึ้นโฮสต์จริง: 'https://www.kkict.net' หรือ 'https://www.kkict.net/kb'
define('BASE_URL', 'http://localhost:8000');

// ===== ความปลอดภัยและ Session =====
define('SESSION_NAME', 'kkict_staff_sid');
define('VOTER_HASH_SALT', 'kkict-kb-change-this-secret-salt-2026');

// จำกัดการเดารหัสผ่าน (Rate Limit)
define('LOGIN_LIMIT_PER_USER', 10); // สูงสุด 10 ครั้ง / บัญชี
define('LOGIN_LIMIT_PER_IP', 30);   // สูงสุด 30 ครั้ง / IP
define('LOGIN_WINDOW_MINUTES', 15); // ภายในหน้าต่างเวลา 15 นาที

// ===== ข้อกำหนดไฟล์อัปโหลด =====
define('MAX_IMAGE_BYTES', 5 * 1024 * 1024); // รูป JPG/PNG/WebP ไม่เกิน 5 MB ต่อรูป
define('MAX_IMAGE_DIMENSION', 1600);        // ย่อขนาดความกว้าง/สูงภาพอัตโนมัติสูงสุด 1600px ด้วย GD
define('MAX_IMAGES_PER_ARTICLE', 5);        // สูงสุด 5 รูปต่อบทความ
define('MAX_PDF_BYTES', 15 * 1024 * 1024);  // ไฟล์ PDF ไม่เกิน 15 MB
