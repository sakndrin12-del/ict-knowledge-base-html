# คลังความรู้ด้าน ICT — ศูนย์พัฒนาทักษะและการเรียนรู้ ICT เทศบาลนครขอนแก่น (KKICT)

ระบบคลังความรู้และคู่มือบริการไอทีด้วยตนเอง พัฒนาด้วยสถาปัตยกรรม **HTML ไฟล์เดียว (`index.html`) + Vanilla PHP 8 (`api.php`, `share.php`) + MySQL 8+ (`utf8mb4`)** รองรับการอัปโหลดขึ้นเว็บโฮสติ้ง Apache มาตรฐานของศูนย์ (`kkict.net`) ได้ทันทีโดยไม่ต้องใช้ Node.js, Composer หรือขั้นตอน Build ใด ๆ

---

## 1. โครงสร้างไฟล์ของโปรเจกต์

```text
ict-knowledge-base-html-main/
├── index.html               # หน้าเว็บทั้งระบบรวมในไฟล์เดียว (HTML + CSS + JS)
├── api.php                  # Backend API ทั้งหมด (Public, Auth, Admin, GD Upload, CSV, Backup, Audit)
├── config.php               # ค่าเชื่อมต่อฐานข้อมูล MySQL และ BASE_URL (ไม่นำขึ้น Git)
├── config.example.php       # ไฟล์ตัวอย่างการตั้งค่าสำหรับคัดลอกเป็น config.php
├── share.php                # หน้าสร้าง Open Graph (og:title, og:description, og:image, og:url) สำหรับแชร์ LINE / Facebook
├── router.php               # ตัวกระจายเส้นทางสำหรับรันในเครื่องด้วย php -S localhost:8000 router.php
├── .htaccess                # กฎ Apache บนโฮสต์จริง (/api/... -> api.php) และป้องกันการเข้าถึง config.php / scripts/
├── .gitignore               # เพิกเฉย config.php และไฟล์ที่อัปโหลดใน uploads/
├── uploads/
│   └── .htaccess            # ป้องกันการรันสคริปต์ PHP/CGI ทุกชนิดในโฟลเดอร์อัปโหลด
├── scripts/
│   └── create-admin.php     # สคริปต์ CLI สำหรับสร้างบัญชีแอดมินใหม่หรือกู้คืนรหัสผ่าน
├── database-mysql.sql       # โครงสร้างตารางทั้ง 9 ตารางและข้อมูลเริ่มต้น (utf8mb4)
└── README.md                # คู่มือการติดตั้ง ใช้งาน อัปโหลดขึ้นโฮสต์ และรายการทดสอบ
```

---

## 2. การติดตั้ง PHP 8 บน Windows (ไม่ต้องใช้ XAMPP)

1. เปิด **PowerShell** แล้วติดตั้ง PHP 8.3 ด้วย `winget`:
   ```powershell
   winget install --id PHP.PHP.8.3 --exact
   ```
   *(หลังติดตั้งเสร็จ ให้ปิดแล้วเปิดหน้าต่าง PowerShell ใหม่เพื่อให้คำสั่ง `php` ใช้งานได้)*

2. ตั้งค่าไฟล์ `php.ini` (ดูตำแหน่งไฟล์ได้จากคำสั่ง `php --ini`):
   - หากยังไม่มีไฟล์ `php.ini` ให้คัดลอก `php.ini-development` เป็น `php.ini` ในโฟลเดอร์เดียวกับ `php.exe`
   - เปิดไฟล์ `php.ini` แล้วเอาเครื่องหมาย `;` หน้าบรรทัดเหล่านี้ออก และปรับขนาดไฟล์อัปโหลด:
     ```ini
     extension_dir = "ext"
     extension=pdo_mysql
     extension=mbstring
     extension=exif
     extension=fileinfo
     extension=gd
     extension=openssl

     upload_max_filesize = 20M
     post_max_size = 48M
     date.timezone = Asia/Bangkok
     ```
   - ตรวจสอบด้วยคำสั่ง:
     ```powershell
     php -v
     php -m
     ```
     ต้องเห็น `pdo_mysql`, `mbstring`, `exif`, `fileinfo`, `gd`, `openssl` แสดงอยู่ในรายการ

---

## 3. การนำเข้าฐานข้อมูล MySQL (MySQL Workbench หรือ CLI)

### วิธีที่ 1: ผ่าน MySQL Workbench
1. เปิด **MySQL Workbench** เชื่อมต่อไปยัง `localhost:3306` (ผู้ใช้ `root`)
2. ไปที่เมนู **Server → Data Import**
3. เลือก **Import from Self-Contained File** แล้วเลือกไฟล์ `database-mysql.sql`
4. กดปุ่ม **Start Import** (ภายในไฟล์มีการสร้างฐานข้อมูล `ict_knowledge_base` และตั้ง `SET FOREIGN_KEY_CHECKS` ให้อัตโนมัติครบทั้ง 9 ตาราง ได้แก่ `admins`, `categories`, `articles`, `article_feedback`, `article_view_daily`, `searchlog`, `sitesetting`, `login_attempts`, `audit_logs`)

### วิธีที่ 2: ผ่านบรรทัดคำสั่ง (CLI)
```powershell
& "C:\Program Files\MySQL\MySQL Server 26.10\bin\mysql.exe" -u root -p --default-character-set=utf8mb4 < database-mysql.sql
```

---

## 4. การตั้งค่าและรันเซิร์ฟเวอร์ในเครื่อง (Local Development)

1. คัดลอกไฟล์ตั้งค่า (หากยังไม่มี `config.php`):
   ```powershell
   Copy-Item config.example.php config.php
   ```
2. ตรวจสอบค่าใน `config.php`:
   - `DB_HOST` = `'127.0.0.1'`
   - `DB_PORT` = `3306`
   - `DB_NAME` = `'ict_knowledge_base'`
   - `DB_USER` = `'root'`
   - `DB_PASS` = รหัสผ่าน MySQL ของเครื่อง
   - `BASE_URL` = `'http://localhost:8000'`
3. รันเซิร์ฟเวอร์ด้วยคำสั่ง:
   ```powershell
   php -S localhost:8000 router.php
   ```
4. เปิดเบราว์เซอร์ไปที่: **`http://localhost:8000`**
   - แถบด้านบนขวาจะแสดงสถานะ **`● เชื่อมต่อ MySQL สด`**

---

## 5. การสร้างบัญชีแอดมินครั้งแรก หรือกู้คืนรหัสผ่าน (CLI)

สคริปต์ `scripts/create-admin.php` อนุญาตให้รันผ่าน Command Line เท่านั้น (ป้องกันการเรียกผ่านเบราว์เซอร์ 100%) และบังคับความยาวรหัสผ่าน **12–200 ตัวอักษร** เข้ารหัสด้วย `password_hash(..., PASSWORD_BCRYPT)`:

```powershell
php scripts/create-admin.php <username> "<password>" "<ชื่อ-นามสกุลเจ้าหน้าที่>"
```

**ตัวอย่าง:**
```powershell
php scripts/create-admin.php kkict-staff "KkictSecure@2026!" "เจ้าหน้าที่ศูนย์ ICT ขอนแก่น"
```
*(หากกรอก `username` ที่มีอยู่แล้ว ระบบจะเปลี่ยนรหัสผ่านใหม่และปลดล็อก Rate Limit ของบัญชีนั้นให้ทันที)*

### บัญชีเจ้าหน้าที่เริ่มต้นจาก `database-mysql.sql`
- **บัญชีที่ 1:** Username `ict-admin` · Password `IctAdmin@2026!`
(หมายเหตุ: เมื่อต้องการเปลี่ยนรหัสผ่านใหม่ในหน้าตั้งค่า ระบบจะกำหนดให้รหัสใหม่ยาว 12–200 ตัวอักษร)*

---

## 6. วิธีอัปโหลดขึ้นโฮสต์จริง (`kkict.net`)

1. นำไฟล์ `database-mysql.sql` ไป Import เข้าฐานข้อมูล MySQL ของโฮสต์ผ่าน **phpMyAdmin**
2. อัปโหลดไฟล์ทั้งหมดในโปรเจกต์ขึ้นโฟลเดอร์เว็บ (เช่น `public_html/` หรือ `public_html/kb/`) โดยตรวจสอบให้มีไฟล์ `.htaccess` และ `uploads/.htaccess` ครบถ้วน
3. ตั้งสิทธิ์โฟลเดอร์ `uploads/` ให้ PHP เขียนไฟล์ได้ (`chmod 755` สำหรับโฟลเดอร์ และ `644` สำหรับไฟล์)
4. แก้ไขไฟล์ **`config.php`** บนโฮสต์จริงในจุดต่อไปนี้:
   ```php
   define('DB_HOST', 'localhost');                 // โฮสต์ฐานข้อมูลของศูนย์
   define('DB_PORT', 3306);
   define('DB_NAME', 'ชื่อฐานข้อมูลบนโฮสต์จริง');
   define('DB_USER', 'ชื่อผู้ใช้ฐานข้อมูลบนโฮสต์จริง');
   define('DB_PASS', 'รหัสผ่านฐานข้อมูลบนโฮสต์จริง');

   // เปลี่ยนเป็นโดเมนจริงของศูนย์ (ห้ามใส่ / ปิดท้าย) เพื่อให้ลิงก์แชร์ LINE / Facebook (share.php) ชี้ถูกต้อง
   define('BASE_URL', 'https://www.kkict.net');    // หรือ 'https://www.kkict.net/kb' หากวางในโฟลเดอร์ย่อย

   // เปลี่ยนค่า Salt เป็นค่าสุ่มเฉพาะของเซิร์ฟเวอร์จริง
   define('VOTER_HASH_SALT', 'ตั้งข้อความสุ่มยาวๆสำหรับเซิร์ฟเวอร์จริง');
   ```

---

## 7. ตารางสรุป API Endpoints ทั้งหมด

| Method & Path | สิทธิ์ | ไฟล์ PHP ที่รับผิดชอบ | หน้าที่การทำงาน |
|---|---|---|---|
| `GET /api/health` | ทั่วไป | `api.php` | ตรวจสถานะการเชื่อมต่อ MySQL และส่ง `baseUrl` + `csrfToken` + `announcement` |
| `GET /api/categories` | ทั่วไป | `api.php` | ดึงหมวดหมู่ทั้งหมดพร้อมจำนวนบทความที่เผยแพร่แล้ว |
| `GET /api/articles` | ทั่วไป | `api.php` | ดึงรายการบทความ (`?featured=true&limit=6`) |
| `GET /api/articles/search` | ทั่วไป | `api.php` | ค้นหาบทความ + Live Autocomplete (`?autocomplete=1` ไม่ปั่น `searchlog`) รองรับคำพ้อง เช่น `ปริ้น` = `Printer/เครื่องพิมพ์`, `ไวไฟ` = `Wi-Fi` |
| `GET /api/articles/{id}` | ทั่วไป / Admin | `api.php` | อ่านบทความ + นับยอดวิวรายวัน (หากส่ง `?preview=1` โดย Admin จะดูตัวอย่างได้ทุกสถานะและไม่นับวิว) |
| `POST /api/articles/{id}/feedback` | ทั่วไป | `api.php` | โหวตคู่มือ "มีประโยชน์ / ยังไม่ตรงปัญหา" (ป้องกันโหวตซ้ำด้วย SHA-256 `voter_key`) |
| `GET /api/settings/contact` | ทั่วไป | `api.php` | ดึงข้อมูลช่องทางติดต่อศูนย์ ICT |
| `GET /api/settings/announcement` | ทั่วไป | `api.php` | ดึงข้อมูลแถบประกาศแจ้งเตือนด่วนหน้าเว็บ (Announcement Banner) |
| `GET /share.php?id={id}` | ทั่วไป / Bot | `share.php` | สร้าง `<meta property="og:...">` จาก MySQL และ `BASE_URL` สำหรับ LINE / Facebook แล้วพาเข้าหน้าอ่านบทความ |
| `POST /api/auth/login` | ทั่วไป | `api.php` | ล็อกอินด้วย PHP Session + `password_verify` พร้อม Rate Limit (10 ครั้ง/15 นาที/บัญชี, 30 ครั้ง/15 นาที/IP) |
| `POST /api/auth/logout` | Admin + CSRF | `api.php` | ทำลาย PHP Session ออกจากระบบอย่างปลอดภัย |
| `GET /api/auth/me` | Admin | `api.php` | ตรวจสถานะล็อกอินปัจจุบันและรับ `csrfToken` |
| `POST /api/auth/change-password` | Admin + CSRF | `api.php` | เปลี่ยนรหัสผ่านเจ้าหน้าที่ (ตรวจรหัสเดิม + บังคับรหัสใหม่ยาว 12–200 ตัวอักษร) |
| `GET /api/admin/dashboard` | Admin | `api.php` | ตัวเลข KPI ภาพรวม + ข้อมูลกราฟแท่งเปรียบเทียบย้อนหลัง 6 เดือน (`sixMonthChart`) |
| `GET /api/admin/reports/monthly` | Admin | `api.php` | รายงานสรุปประจำเดือนและรายการบทความรายเดือน |
| `GET /api/admin/export/csv` | Admin | `api.php` | ส่งออก CSV ใส่ UTF-8 BOM (`type=articles`, `monthly`, `sixmonths`) เปิดใน Excel ภาษาไทยไม่เพี้ยน |
| `GET /api/admin/export/backup` | Admin | `api.php` | ดาวน์โหลดไฟล์สำรองฐานข้อมูลทั้งระบบ (`?format=sql` หรือ `?format=json`) |
| `GET /api/admin/audit-logs` | Admin | `api.php` | ดึงประวัติผู้สร้าง/ผู้แก้ไขและกิจกรรมผู้ดูแลระบบล่าสุด (Audit Trail) |
| `GET /api/admin/articles` | Admin | `api.php` | ดึงบทความทุกสถานะ (`DRAFT`, `PUBLISHED`, `HIDDEN`) พร้อมชื่อเจ้าหน้าที่ผู้สร้าง/แก้ไขล่าสุด |
| `GET /api/admin/articles/{id}` | Admin | `api.php` | ดึงข้อมูลบทความสำหรับแก้ไข |
| `POST /api/admin/articles` | Admin + CSRF | `api.php` | สร้างบทความใหม่ ย่อขนาดรูปอัตโนมัติไม่เกิน 1600px ด้วย GD (สูงสุด 5 รูป) และแนบไฟล์ PDF |
| `PATCH /api/admin/articles/{id}` | Admin + CSRF | `api.php` | แก้ไขบทความ บันทึกผู้แก้ไขล่าสุด (รองรับทั้ง `POST` + `_method=PATCH` และ `PATCH` JSON) |
| `DELETE /api/admin/articles/{id}` | Admin + CSRF | `api.php` | ลบบทความและลบไฟล์แนบที่ไม่มีบทความอื่นอ้างอิง |
| `GET/POST/PATCH/DELETE /api/admin/categories` | Admin (+ CSRF) | `api.php` | จัดการหมวดหมู่ (ป้องกันการลบหมวดหมู่ที่ยังมีบทความอยู่) |
| `GET/DELETE /api/admin/searches` | Admin (+ CSRF) | `api.php` | วิเคราะห์ กรองคำค้นที่ยังไม่พบบทความ (0 ผลลัพธ์) และลบประวัติคำค้นหา |
| `PUT /api/admin/settings/contact` | Admin + CSRF | `api.php` | อัปเดตข้อมูลติดต่อศูนย์ ICT |
| `PUT /api/admin/settings/announcement` | Admin + CSRF | `api.php` | เปิด/ปิดและตั้งค่าข้อความแถบประกาศแจ้งเตือนด่วนหน้าเว็บ |

---

## 8. Checklist สำหรับทดสอบระบบทีละข้อ

- [ ] **1. การเชื่อมต่อฐานข้อมูล:** เปิด `http://localhost:8000` ป้ายมุมขวาบนแสดง `● เชื่อมต่อ MySQL สด` และ `http://localhost:8000/api/health` ตอบ `200 OK`
- [ ] **2. ความปลอดภัยไฟล์สำคัญ:** เปิด `http://localhost:8000/config.php` และ `http://localhost:8000/scripts/create-admin.php` บนเบราว์เซอร์ ต้องถูกบล็อกด้วย `403 Forbidden`
- [ ] **3. หน้าแรกและช่องทางติดต่อ:** แสดงโลโก้คู่ หมวดหมู่ทั้ง 5 หมวด บทความแนะนำ อัปเดตล่าสุด ข้อมูลติดต่อศูนย์ และปุ่มสแกน QR Code LINE ได้ปกติ
- [ ] **4. การตัดระบบรายการโปรด (Bookmarks):** ไม่มีปุ่มติดดาว (`★`/`☆`) บนการ์ดและหน้าบทความ ไม่มีเมนู "ที่บันทึกไว้" และไม่มีกล่อง Personal Quick Access / เปิดอ่านล่าสุด บนหน้าแรก
- [ ] **5. การค้นหา `wifi` และ `Wi-Fi`:** พิมพ์ค้นหาคำว่า `wifi` และ `Wi-Fi` ในช่องค้นหา ได้ผลลัพธ์จำนวนบทความตรงกันครบถ้วน รวมถึงกรองหมวดหมู่ เรียงลำดับ และแบ่งหน้าได้
- [ ] **6. หน้าอ่านบทความ:**
  - แสดงรูปภาพประกอบ (สูงสุด 5 รูป) กดสลับรูปและคลิกเปิด Lightbox เลื่อนซ้าย-ขวาได้ (หากบทความใดไม่มีไฟล์รูป จะแสดงภาพ Placeholder ตามหมวดหมู่ ไม่ขึ้นรูปแตก)
  - บทความที่มีไฟล์ PDF จะแสดงปุ่ม "อ่าน PDF บนเว็บ" และ "ดาวน์โหลด PDF" (หากไม่มีไฟล์ PDF จะซ่อนปุ่มอัตโนมัติ)
  - บทความที่ใส่ลิงก์ YouTube จะแสดงกล่องวิดีโอฝังบนหน้าเว็บทันที
  - กดปุ่มโหวต `👍 มีประโยชน์` / `👎 ยังไม่ตรงปัญหา` แล้วตัวเลขความพึงพอใจอัปเดตลงฐานข้อมูลจริงทันที
- [ ] **7. ระบบแชร์ LINE / Facebook (`share.php`):**
  - เปิด `http://localhost:8000/share.php?id=4` แล้วคลิกขวา View Page Source จะเห็น `<meta property="og:title">`, `og:description`, `og:image`, `og:url` ที่ดึงจาก MySQL และ `BASE_URL` จากนั้นหน้าเว็บจะพาเข้าสู่บทความที่ 4 ใน `index.html` อัตโนมัติ
  - กดปุ่ม **แชร์ทาง LINE** ในหน้าอ่านบทความ จะส่งข้อความ `"ชื่อบทความ + คำอธิบายสั้น + ลิงก์ share.php?id=..."`
- [ ] **8. ระบบล็อกอิน Rate Limit & CSRF:**
  - หน้า Login ไม่มีแท็บ "ตั้งรหัสใหม่" แบบไม่ล็อกอินแล้ว
  - ทดลองกรอกรหัสผิดเกิน 10 ครั้งใน 15 นาที ระบบจะบล็อกด้วยข้อความแจ้งเตือน (`429`)
  - ล็อกอินด้วยบัญชี `ict-admin` / `IctAdmin@2026!` เข้าสู่หน้าจัดการหลังบ้านได้ และกดออกจากระบบทำลาย Session ได้จริง
- [ ] **9. กราฟแท่ง 6 เดือน & พิมพ์รายงานครึ่งปี:**
  - บนหน้า Dashboard แสดงกราฟแท่ง Chart.js ย้อนหลัง 6 เดือน (ยอดเข้าชม + จำนวนบทความต่อเดือน) พร้อมตารางสรุป 6 เดือน
  - กดปุ่ม **`🖨 พิมพ์รายงาน`** หน้าตัวอย่างก่อนพิมพ์จะซ่อนแถบเมนูด้านข้างและแสดงหัวรายงานทางการ + กราฟแท่ง + ตารางสรุปพร้อมพิมพ์ลงกระดาษ A4
- [ ] **10. ส่งออกไฟล์ CSV ภาษาไทย:** กดปุ่มดาวน์โหลด CSV ทั้งแบบบทความทั้งหมด รายเดือน และสรุป 6 เดือน เปิดใน Microsoft Excel แล้วภาษาไทยแสดงถูกต้องไม่เป็นภาษาต่างดาว (มี UTF-8 BOM)
- [ ] **11. CRUD บทความ + อัปโหลดไฟล์ + ดูตัวอย่างไม่นับวิว:**
  - สร้าง/แก้ไขบทความ อัปโหลดรูป JPG/PNG/WebP (ไม่เกิน 5 MB สูงสุด 5 รูป) และ PDF (ไม่เกิน 15 MB) สำเร็จ (ทดลองอัปโหลดไฟล์ปลอมที่เปลี่ยนนามสกุลเป็น `.png` ระบบจะปฏิเสธด้วย Magic Bytes ทันที)
  - กดปุ่ม **ดูตัวอย่าง** จากตารางบทความในหน้า Admin (แม้สถานะเป็น `DRAFT` หรือ `HIDDEN`) จะเปิดดูได้พร้อมแถบแจ้งเตือนโหมดดูตัวอย่าง และยอดวิวไม่เพิ่มขึ้น
- [ ] **12. จัดการหมวดหมู่ / คำค้นหา / เปลี่ยนรหัสผ่าน:**
  - เพิ่ม/แก้ไขหมวดหมู่ได้ และระบบป้องกันการลบหมวดหมู่ที่ยังมีบทความสังกัดอยู่
  - เมนู **คำค้นหา** กรองดูคำค้นที่ยังไม่พบบทความ (`0 ผลลัพธ์`) กดปุ่ม `+ เขียนคู่มือรองรับ` หรือกดลบคำค้นออกจากฐานข้อมูลได้
  - เมนู **ตั้งค่า** แก้ไขข้อมูลติดต่อศูนย์ และเปลี่ยนรหัสผ่านเจ้าหน้าที่ (ตรวจรหัสเดิม + ความยาว 12–200 ตัวอักษร) ได้สำเร็จ
- [ ] **13. Live Autocomplete + ไฮไลต์คำค้น:** พิมพ์ `wifi`, `ปริ้น`, หรือ `สแกน` ในช่องค้นหาหน้าแรก จะแสดง Dropdown แนะนำคู่มือทันทีสูงสุด 5 เรื่องพร้อมไฮไลต์คำค้น คลิกเข้าอ่านได้ทันทีโดยไม่ต้องกด Enter
- [ ] **14. แถบประกาศแจ้งเตือนด่วนหน้าเว็บ (Announcement Banner):** ในเมนู **ตั้งค่า** เปิดแถบประกาศแจ้งเตือน (เหลือง/ฟ้า/แดง) แล้วเปิดหน้าแรก จะเห็นแถบแจ้งเตือนแสดงด้านบนทันที
- [ ] **15. บันทึกผู้แก้ไขล่าสุด (Audit Trail):** ในตารางจัดการบทความ หน้าแก้ไขบทความ และตารางประวัติในหน้าตั้งค่า แสดงชื่อเจ้าหน้าที่ผู้สร้าง/แก้ไขล่าสุดครบถ้วน
- [ ] **16. สำรองข้อมูลฐานข้อมูล (.sql / .json) & ย่อรูป GD อัตโนมัติ:** กดปุ่มดาวน์โหลดไฟล์สำรอง `.sql` และ `.json` ในหน้าตั้งค่าได้ทันที และเมื่ออัปโหลดภาพถ่ายขนาดใหญ่เกิน `1600px` ระบบจะย่อสัดส่วนภาพไม่ให้เกิน `1600px` และบีบอัดให้อัตโนมัติ
