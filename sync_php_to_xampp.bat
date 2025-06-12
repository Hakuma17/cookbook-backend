@echo off
setlocal

:: แก้เส้นทางตามที่นายใช้อยู่จริง
set SOURCE=C:\Users\Lenovo\Desktop\cookbook-backend
set DEST=C:\xampp\htdocs\cookbookapp

echo 🔄 Syncing PHP files to XAMPP...
robocopy "%SOURCE%" "%DEST%" /MIR

echo ✅ Done syncing to XAMPP!
pause
