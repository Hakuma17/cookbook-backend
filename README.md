# 🍽️ Cookbook Backend

Back-end PHP API for the [Cookbook Flutter app](https://github.com/Hakuma17/cookbook)

## 📦 Features
- REST API: user login, recipe retrieval, ingredients, ratings
- Nutrition-linked ingredients
- Image upload (recipes, avatars)
- Secure DB config via `.env`

## 📂 Structure
- `/sql/` → DB schema and seed data
- `/uploads/` → image folders (recipes, users, ingredients)
- `.env.example` → environment config template
- `sync_php_to_xampp.bat` → auto-sync code to XAMPP for testing

## ⚙️ Setup

1. Copy `.env.example` → `.env` and set DB credentials
2. Import `sql/cookbook_db.sql` into your MySQL using phpMyAdmin
3. Double-click `sync_php_to_xampp.bat` to sync backend to XAMPP

## 🧪 Development stack
- PHP 8+
- MySQL 8+
- Apache (via XAMPP)
