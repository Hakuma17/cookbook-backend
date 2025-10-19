<?php
/**
 * Sync DB -> real files for ingredient images (WRITES to DB).
 * Run: php sync_ingredient_images.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// ── (1) Load dotenv if available ──────────────────────────────────────────────
$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) require_once $autoload;
if (class_exists('Dotenv\Dotenv')) {
  Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
}

// ── (2) DB config (.env > defaults) ───────────────────────────────────────────
$DEFAULTS = [
  'DB_HOST'    => '127.0.0.1',
  'DB_PORT'    => '3306',
  'DB_NAME'    => 'cookbook',
  'DB_USER'    => 'root',
  'DB_PASS'    => '',
  'DB_CHARSET' => 'utf8mb4',
];
$env = fn($k) => ($_ENV[$k] ?? getenv($k) ?? $DEFAULTS[$k]);

$dbHost=$env('DB_HOST'); $dbPort=(int)$env('DB_PORT'); $dbName=$env('DB_NAME');
$dbUser=$env('DB_USER'); $dbPass=$env('DB_PASS'); $dbCharset=$env('DB_CHARSET');

// ── (3) Table/columns & paths ─────────────────────────────────────────────────
$table='ingredients';       // ชื่อตาราง
$idCol='id';                // PK
$pathCol='image_path';      // คอลัมน์พาธรูป
$webBase='uploads/ingredients';          // พาธที่เก็บใน DB
$baseDir=__DIR__ . '/uploads/ingredients'; // โฟลเดอร์หลัก
$exts=['png','jpg','jpeg','PNG','JPG','JPEG'];
$fallback='default_ingredients.png';     // ไม่พบไฟล์ → ชี้ไฟล์นี้

// ค้นหาในโฟลเดอร์สำรองด้วย (เช่น uploads/ingredients-2025xxxx/)
$extraDirs = array_filter(glob(__DIR__ . '/uploads/ingredients-*', GLOB_ONLYDIR) ?: [], 'is_dir');
$searchDirs = array_merge([$baseDir], $extraDirs);

// ── (4) สร้างแผนที่ “ingredients_<id> => [path, ext, mtime]” จากทุกโฟลเดอร์ ─────
$disk = []; // key => ['path'=>absPath, 'ext'=>ext, 'mtime'=>int]
$scan = function(string $dir) use(&$disk,$exts){
  if (!is_dir($dir)) return;
  $it = new DirectoryIterator($dir);
  foreach ($it as $f) {
    if ($f->isDot() || !$f->isFile()) continue;
    $name=$f->getFilename();
    if (preg_match('/^(ingredients_\d+)\.('.implode('|',$exts).')$/',$name,$m)) {
      $key=$m[1]; $ext=$m[2];
      $path=$f->getPathname();
      $mtime=$f->getMTime();
      // เก็บไฟล์ที่ใหม่กว่าไว้
      if (!isset($disk[$key]) || $mtime > $disk[$key]['mtime']) {
        $disk[$key]=['path'=>$path,'ext'=>$ext,'mtime'=>$mtime];
      }
    }
  }
};
foreach ($searchDirs as $d) $scan($d);

// ── (5) ต่อ DB ────────────────────────────────────────────────────────────────
try {
  $dsn = "mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=$dbCharset";
  $pdo = new PDO($dsn,$dbUser,$dbPass,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
  ]);
} catch (PDOException $e) {
  fwrite(STDERR,"DB connect failed: ".$e->getMessage().PHP_EOL); exit(1);
}

// ── (6) ดึงรายการที่เกี่ยวข้อง แล้วอัปเดต ───────────────────────────────────────
$sql="SELECT {$idCol} AS id, {$pathCol} AS path FROM {$table} WHERE {$pathCol} LIKE :pfx";
$st=$pdo->prepare($sql); $st->execute([':pfx'=>$webBase.'/%']);
$rows=$st->fetchAll();

$upd=$pdo->prepare("UPDATE {$table} SET {$pathCol}=:p WHERE {$idCol}=:id LIMIT 1");

$changed=0; $fallbackCount=0; $notfound=[];

foreach ($rows as $r) {
  $id=(int)$r['id']; $old=(string)$r['path'];

  // ให้ key มาจาก path ถ้าเจอ ไม่งั้นใช้จาก id
  if (preg_match('/(ingredients_\d+)\.(?:'.implode('|',$exts).')$/',$old,$m)) {
    $key=$m[1];
  } else {
    $key="ingredients_{$id}";
  }

  if (isset($disk[$key])) {
    // map เป็น web path ใหม่ให้ตรงนามสกุลจริง (อิงโฟลเดอร์หลักเป็นมาตรฐาน)
    $ext=$disk[$key]['ext'];
    $new="{$webBase}/{$key}.{$ext}";
  } else {
    $new="{$webBase}/{$fallback}";
    $fallbackCount++;
    $notfound[] = ['id'=>$id,'wanted'=>$key];
  }

  if ($new !== $old) {
    $upd->execute([':p'=>$new, ':id'=>$id]);
    echo "[UPDATE] id={$id}  {$old}  ->  {$new}\n";
    $changed++;
  }
}

echo "Done. Updated rows: {$changed}\n";
if ($fallbackCount) {
  echo "Fallback applied to {$fallbackCount} rows (no matching file found).\n";
  // แสดงอย่างย่อ
  foreach (array_slice($notfound,0,10) as $nf) {
    echo "  - id={$nf['id']} ({$nf['wanted']}.*) not found\n";
  }
}
