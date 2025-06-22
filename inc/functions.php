<?php
/**
 * -------------------------------------------------------------
 *  inc/functions.php   (refactor 2025-06-22 b)
 * ------------------------------------------------------------
 *  • PDO singleton  →  pdo()
 *  • helper: sanitize / respond / jsonOutput / getBaseUrl
 *  • session helper: getLoggedInUserId / requireLogin
 *  • search_recipes()  – ปรับไม่อาศัย r.favorite_count
 * -------------------------------------------------------------
 */

/* ──────────────────────────────────────────────────────────────
 * 0) hide PHP notice / warning จาก output (ใช้ log แทน)
 * ─────────────────────────────────────────────────────────── */
ini_set('display_errors', 0);
error_reporting(0);

/* ──────────────────────────────────────────────────────────────
 * 1) include config / json helpers & start session
 * ─────────────────────────────────────────────────────────── */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/json.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ──────────────────────────────────────────────────────────────
 * 2) PDO singleton
 * ─────────────────────────────────────────────────────────── */
$__pdo = null;
function pdo(): PDO
{
    global $__pdo;
    if (! $__pdo) {
        $__pdo = new PDO(
            'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }
    return $__pdo;
}

/* --- (alias สำหรับสคริปต์เก่า) --------------------------- */
if (!function_exists('openDB')) {
    function openDB(): PDO { return pdo(); }
}

/* ──────────────────────────────────────────────────────────────
 * 3) utilities
 * ─────────────────────────────────────────────────────────── */
if (!function_exists('sanitize')) {
    function sanitize(string $v): string
    {   return htmlspecialchars(trim($v), ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('getBaseUrl')) {
    function getBaseUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return rtrim($scheme.'://'.$_SERVER['HTTP_HOST'].dirname($_SERVER['SCRIPT_NAME']), '/');
    }
}

/* --- session helpers --------------------------------------- */
if (!function_exists('getLoggedInUserId')) {
    function getLoggedInUserId(): ?int { return $_SESSION['user_id'] ?? null; }
}
if (!function_exists('requireLogin')) {
    function requireLogin(): int
    {
        $uid = getLoggedInUserId();
        if (!$uid) respond(false, ['message'=>'กรุณาเข้าสู่ระบบก่อน'], 401);
        return $uid;
    }
}

/* --- respond(): wrapper ง่าย ๆ -------------------------------- */
if (!function_exists('respond')) {
    function respond(bool $ok, array $data=[], int $code=200): void
    {
        jsonOutput([
            'success'=>$ok,
            'message'=>$ok ? 'ดำเนินการสำเร็จ' : ($data['message'] ?? 'เกิดข้อผิดพลาด'),
            'data'   =>$ok ? $data : (object)[],
        ], $code);
    }
}

/* ──────────────────────────────────────────────────────────────
 * 4) search_recipes()
 * ─────────────────────────────────────────────────────────── */
if (!function_exists('search_recipes')) {
function search_recipes(
    string $query                ='',
    array  $includeIngredientIds =[],
    array  $excludeIngredientIds =[],
    ?int   $categoryId           =null,
    int    $offset               =0,
    int    $limit                =20,
    ?int   $userId               =null,
    string $sortKey              ='latest'   // popular | trending | latest | recommended
): array {

    $pdo   = pdo();
    $query = sanitize($query);

    $where  = [];
    $params = [];

    /* 4.1 ORDER BY */
    $orderBy = match ($sortKey) {
        'popular'     => 'fav_cnt DESC, r.average_rating DESC',
        'trending'    => 'review_cnt DESC',
        'recommended' => 'r.average_rating DESC',
        default       => 'r.created_at DESC',
    };

    if ($sortKey === 'trending') {
        // ตัวอย่าง: ใช้เฉพาะรีวิวใน 7 วันหลังสุด
        $where[] = 'r.created_at >= NOW() - INTERVAL 30 DAY';
    }
    if ($sortKey === 'recommended') {
        $where[] = 'r.average_rating >= 4.0';
    }

    /* 4.2 full-text / LIKE search ที่ชื่อ */
    if ($query !== '') {
        $where[]      = 'r.name LIKE :q';
        $params[':q'] = "%$query%";
    }

    /* 4.3 include / exclude ingredients */
    if ($includeIngredientIds) {
        $in      = implode(',', array_map('intval', $includeIngredientIds));
        $where[] = "r.recipe_id IN (SELECT recipe_id FROM recipe_ingredient WHERE ingredient_id IN ($in))";
    }
    if ($excludeIngredientIds) {
        $ex      = implode(',', array_map('intval', $excludeIngredientIds));
        $where[] = "r.recipe_id NOT IN (SELECT recipe_id FROM recipe_ingredient WHERE ingredient_id IN ($ex))";
    }

    /* 4.4 category filter */
    if ($categoryId !== null) {
        $where[]        = 'EXISTS (SELECT 1 FROM category_recipe cr WHERE cr.recipe_id=r.recipe_id AND cr.category_id=:cat)';
        $params[':cat'] = $categoryId;
    }

    /* 4.5 allergy filter (exclude สูตรที่มีของแพ้) */
    if ($userId !== null) {
        $where[]        = 'r.recipe_id NOT IN (
            SELECT ri.recipe_id
            FROM recipe_ingredient ri
            JOIN allergyinfo a ON a.ingredient_id = ri.ingredient_id
            WHERE a.user_id = :uid
        )';
        $params[':uid'] = $userId;
    }

    /* 4.6 SQL statement */
    $sqlWhere = $where ? 'WHERE '.implode(' AND ', $where) : '';
    $sql = "
        SELECT
            r.recipe_id,
            r.name,
            r.image_path,
            r.prep_time,
            r.average_rating,
            /* ★ จำนวนรีวิว  */
            (SELECT COUNT(*) FROM review rv WHERE rv.recipe_id=r.recipe_id) AS review_cnt,
            /* ★ จำนวน favorites */
            (SELECT COUNT(*) FROM favorites f WHERE f.recipe_id=r.recipe_id) AS fav_cnt,
            /* ingredient สั้น ๆ + id ทั้งหมด  */
            (SELECT GROUP_CONCAT(DISTINCT COALESCE(ri.descrip,'') SEPARATOR ', ')
             FROM recipe_ingredient ri WHERE ri.recipe_id=r.recipe_id) AS short_ingredients,
            (SELECT GROUP_CONCAT(DISTINCT ri.ingredient_id)
             FROM recipe_ingredient ri WHERE ri.recipe_id=r.recipe_id) AS ingredient_ids,
            r.created_at
        FROM recipe r
        $sqlWhere
        ORDER BY $orderBy
        LIMIT :offset,:limit
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k=>$v) {
        $stmt->bindValue($k,$v,is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
    }
    $stmt->bindValue(':offset',$offset,PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    /* 4.7 post-process rows */
    $base = getBaseUrl().'/uploads/recipes';
    foreach ($rows as &$r) {
        $r['image_url']      = $r['image_path']
                             ? $base.'/'.basename($r['image_path'])
                             : $base.'/default_recipe.png';
        $r['prep_time']      = $r['prep_time'] ? (int)$r['prep_time'] : null;
        $r['average_rating'] = (float)$r['average_rating'];
        $r['review_count']   = (int)$r['review_cnt'];
        $r['favorite_count'] = (int)$r['fav_cnt'];
        $r['ingredient_ids'] = array_filter(array_map('intval', explode(',',$r['ingredient_ids']??'')));
        $r['has_allergy']    = false;
        unset($r['fav_cnt'],$r['review_cnt']);
    }
    return $rows;
}}
