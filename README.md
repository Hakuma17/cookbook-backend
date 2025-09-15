# Cookbook Backend API

แอปพลิเคชัน Backend API สำหรับระบบสูตรอาหารไทย โดยใช้ PHP และ MySQL ซึ่งรองรับการจัดการสูตรอาหาร การยืนยันตัวตน ระบบโปรด และฟีเจอร์ต่างๆ เพื่อการใช้งานแอปพลิเคชันสูตรอาหาร

## 🚀 คุณสมบัติหลัก

### 🔐 การจัดการผู้ใช้
- สมัครสมาชิกพร้อมยืนยัน OTP ทางอีเมล
- เข้าสู่ระบบด้วยอีเมล/รหัสผ่าน
- เข้าสู่ระบบด้วย Google OAuth
- เปลี่ยนรหัสผ่าน และรีเซ็ตรหัสผ่าน
- จัดการโปรไฟล์และอัพโหลดรูปภาพโปรไฟล์

### 🍳 การจัดการสูตรอาหาร
- ดึงสูตรอาหารใหม่ล่าสุด
- ค้นหาสูตรอาหารแบบขั้นสูง (รองรับภาษาไทย)
- ดูรายละเอียดสูตรอาหาร
- จัดกลุ่มสูตรอาหารตามหมวดหมู่
- ระบบสูตรอาหารยอดนิยม

### ⭐ ระบบโต้ตอบ
- เพิ่ม/ลบสูตรอาหารโปรด
- แสดงความคิดเห็นและให้คะแนนสูตรอาหาร
- ลบความคิดเห็นของตัวเอง

### 🛒 ระบบตะกร้าส่วนผสม
- เพิ่ม/ลบส่วนผสมในตะกร้า
- อัพเดตจำนวนส่วนผสม
- ดูรายการส่วนผสมในตะกร้า
- เคลียร์ตะกร้าทั้งหมด

### 🥜 การจัดการภูมิแพ้
- ตั้งค่าภูมิแพ้ส่วนบุคคล
- ตรวจสอบสูตรอาหารที่เหมาะสมตามภูมิแพ้
- แสดงคำเตือนภูมิแพ้ในสูตรอาหาร

### 🔍 การค้นหาขั้นสูง
- ค้นหาด้วยชื่อสูตรอาหาร ส่วนผสม หรือวิธีทำ
- รองรับการค้นหาภาษาไทยแบบ Fuzzy Search
- ฟิลเตอร์ตามหมวดหมู่และภูมิแพ้

## 🛠️ เทคโนโลยีที่ใช้

- **PHP 8.2+** - ภาษาหลักในการพัฒนา
- **MySQL/MariaDB** - ฐานข้อมูลหลัก
- **Composer** - จัดการ dependencies
- **PHPMailer** - ส่งอีเมล
- **Google API Client** - Google OAuth
- **Firebase JWT** - JSON Web Tokens
- **Python (Optional)** - Thai text tokenization

## 📋 ข้อกำหนดระบบ

- PHP 8.2 หรือสูงกว่า
- MySQL 5.7+ หรือ MariaDB 10.4+
- Apache/Nginx Web Server
- Composer
- Python 3.6+ (สำหรับ Thai tokenization - ไม่บังคับ)

## 🔧 การติดตั้ง

### 1. Clone Repository
```bash
git clone https://github.com/Hakuma17/cookbook-backend.git
cd cookbook-backend
```

### 2. ติดตั้ง Dependencies
```bash
composer install
```

### 3. ตั้งค่าฐานข้อมูล
1. สร้างฐานข้อมูล MySQL:
```sql
CREATE DATABASE cookbook_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

2. นำเข้าไฟล์ SQL:
```bash
mysql -u [username] -p cookbook_db < sql/cookbook_db.sql
```

### 4. ตั้งค่าไฟล์ Configuration
1. แก้ไขไฟล์ `inc/config.php`:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'cookbook_db');
define('DB_USER', 'your_username');
define('DB_PASS', 'your_password');

define('GOOGLE_CLIENT_ID', 'your_google_client_id');
```

2. สร้างไฟล์ `.env` (ไม่บังคับ):
```env
APP_BRAND_NAME=Cooking Guide
SUPPORT_EMAIL=support@yourdomain.com
APP_URL=https://yourdomain.com
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USERNAME=your_email@gmail.com
SMTP_PASSWORD=your_app_password
```

### 5. ตั้งค่า Web Server
ตั้งค่า Document Root ให้ชี้ไปที่โฟลเดอร์ของโปรเจค และเปิดใช้งาน mod_rewrite (สำหรับ Apache)

### 6. ตั้งค่า Permissions
```bash
chmod 755 uploads/
chmod 755 vendor/
```

## 📝 API Endpoints

### Authentication
- `POST /register.php` - สมัครสมาชิก
- `POST /login.php` - เข้าสู่ระบบ
- `POST /google_login.php` - เข้าสู่ระบบด้วย Google
- `POST /verify_otp.php` - ยืนยัน OTP
- `POST /logout.php` - ออกจากระบบ
- `GET /ping.php` - ตรวจสอบสถานะการเข้าสู่ระบบ

### User Management
- `POST /change_password.php` - เปลี่ยนรหัสผ่าน
- `POST /reset_password.php` - รีเซ็ตรหัสผ่าน
- `GET /get_profile.php` - ดูข้อมูลโปรไฟล์
- `POST /update_profile.php` - แก้ไขโปรไฟล์
- `POST /upload_profile_image.php` - อัพโหลดรูปโปรไฟล์

### Recipes
- `GET /get_new_recipes.php` - ดึงสูตรอาหารใหม่
- `GET /get_popular_recipes.php` - ดึงสูตรอาหารยอดนิยม
- `GET /get_recipe_detail.php?id={recipe_id}` - ดูรายละเอียดสูตรอาหาร
- `GET /get_search_recipes.php` - ค้นหาสูตรอาหารแบบธรรมดา
- `GET /search_recipes_unified.php` - ค้นหาสูตรอาหารแบบขั้นสูง
- `GET /get_recipes_by_group.php?group_id={id}` - ดึงสูตรอาหารตามหมวดหมู่

### Favorites & Comments
- `POST /toggle_favorite.php` - เพิ่ม/ลบรายการโปรด
- `GET /get_user_favorites.php` - ดูรายการโปรด
- `POST /post_comment.php` - แสดงความคิดเห็น/ให้คะแนน
- `GET /get_comments.php?recipe_id={id}` - ดูความคิดเห็น
- `DELETE /delete_comment.php` - ลบความคิดเห็น

### Shopping Cart
- `POST /add_cart_item.php` - เพิ่มส่วนผสมในตะกร้า
- `GET /get_cart_items.php` - ดูรายการในตะกร้า
- `GET /get_cart_ingredients.php` - ดูส่วนผสมในตะกร้า
- `POST /update_cart.php` - อัพเดตจำนวนในตะกร้า
- `DELETE /remove_cart_item.php` - ลบรายการจากตะกร้า
- `DELETE /clear_cart.php` - เคลียร์ตะกร้า

### Allergy Management
- `GET /get_allergy_list.php` - ดูรายการภูมิแพ้ที่มี
- `POST /manage_allergy.php` - จัดการภูมิแพ้ส่วนบุคคล

### Ingredients & Groups
- `GET /get_ingredients.php` - ดูรายการส่วนผสม
- `GET /get_ingredient_groups.php` - ดูกลุ่มส่วนผสม
- `GET /get_ingredient_suggestions.php` - แนะนำส่วนผสม
- `GET /get_group_suggestions.php` - แนะนำกลุ่มอาหาร

## 🧪 การทดสอบ

### ทดสอบการเชื่อมต่อฐานข้อมูล
```bash
php test.php
```

### ทดสอบ API Endpoints
สามารถใช้ Postman หรือ curl เพื่อทดสอบ API:

```bash
# ทดสอบ ping
curl -X GET http://localhost/ping.php

# ทดสอบดึงสูตรอาหารใหม่
curl -X GET http://localhost/get_new_recipes.php
```

## 🔒 ความปลอดภัย

- การป้องกัน SQL Injection ด้วย Prepared Statements
- การตรวจสอบ CSRF Token
- การเข้ารหัสรหัสผ่านด้วย PHP password_hash()
- การตรวจสอบสิทธิ์ด้วย JWT
- การจำกัดการอัพโหลดไฟล์
- การตรวจสอบ Input Validation

## 📁 โครงสร้างโปรเจค

```
cookbook-backend/
├── inc/                    # ไฟล์ configuration และ functions
│   ├── config.php         # การตั้งค่าฐานข้อมูลและ API
│   ├── db.php            # Database helpers
│   ├── functions.php     # Utility functions
│   ├── json.php          # JSON response helpers
│   └── mailer.php        # Email configuration
├── sql/                   # ไฟล์ฐานข้อมูล
│   └── cookbook_db.sql   # Database schema
├── scripts/              # Python scripts
│   └── thai_tokenize.py  # Thai text processing
├── assets/               # Static files
├── uploads/              # อัพโหลดไฟล์
├── vendor/               # Composer dependencies
├── bootstrap.php         # Application bootstrap
├── composer.json         # Composer configuration
├── *.php                 # API endpoints
└── README.md            # เอกสารนี้
```

## 🐛 การแก้ไขปัญหา

### ปัญหาที่พบบ่อย

1. **Error 500**: ตรวจสอบ error log ใน web server
2. **การเชื่อมต่อฐานข้อมูลล้มเหลว**: ตรวจสอบการตั้งค่าใน `inc/config.php`
3. **ส่งอีเมลไม่ได้**: ตรวจสอบการตั้งค่า SMTP ในไฟล์ `.env`
4. **อัพโหลดไฟล์ไม่ได้**: ตรวจสอบ permissions ของโฟลเดอร์ `uploads/`

### การเปิด Debug Mode
แก้ไขในไฟล์ `inc/config.php`:
```php
ini_set('display_errors', 1);
error_reporting(E_ALL);
```

## 🤝 การพัฒนา

### การเพิ่ม API Endpoint ใหม่
1. สร้างไฟล์ PHP ใหม่ในโฟลเดอร์หลัก
2. Include ไฟล์ที่จำเป็น:
```php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';
```
3. ใช้ `jsonOutput()` สำหรับ response
4. เพิ่มการตรวจสอบ HTTP method
5. เพิ่ม input validation

### การเพิ่มตาราง Database ใหม่
1. เพิ่ม schema ในไฟล์ `sql/cookbook_db.sql`
2. สร้าง migration script (ถ้าจำเป็น)
3. อัพเดต functions ใน `inc/db.php`

## 📄 License

โปรเจคนี้ใช้สำหรับการศึกษาและพัฒนา

## 👥 ผู้พัฒนา

- [Hakuma17](https://github.com/Hakuma17)

## 📞 การติดต่อ

หากมีคำถามหรือต้องการความช่วยเหลือ สามารถติดต่อได้ผ่าน GitHub Issues

---
