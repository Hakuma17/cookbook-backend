<?php
ini_set('display_errors', 0);
error_reporting(0);

/* ──────────────────────────
 * 1) include & session
 * ────────────────────────── */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/json.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ──────────────────────────
 * 2) PDO singleton
 * ────────────────────────── */
$__pdo = null;
function pdo(): PDO
{
    global $__pdo;
    if ($__pdo === null) {
        $__pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
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

/* ──────────────────────────
 * 3) helpers
 * ────────────────────────── */
function sanitize(string $v): string
{
    return htmlspecialchars(trim($v), ENT_QUOTES, 'UTF-8');
}

function getBaseUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return rtrim($scheme . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']), '/');
}

function getLoggedInUserId(): ?int
{
    return $_SESSION['user_id'] ?? null;
}

/**
 * ตรวจว่า user ล็อกอินหรือยัง
 * ถ้ายัง ให้ส่ง JSON 401 แล้ว exit
 * ถ้าล็อกอิน ให้คืน user_id กลับ
 */
function requireLogin(): int
{
    $uid = getLoggedInUserId();
    if (!$uid) {
        jsonOutput([
            'success' => false,
            'message' => 'ต้องล็อกอินก่อน'
        ], 401);
    }
    return $uid;
}

function respond(bool $ok, array $data = [], int $code = 200): void
{
    jsonOutput([
        'success' => $ok,
        'message' => $ok ? 'ดำเนินการสำเร็จ' : ($data['message'] ?? 'เกิดข้อผิดพลาด'),
        'data'    => $ok ? $data : (object)[],
    ], $code);
}

/* ──────────────────────────
 * 4) search_recipes()
 * ────────────────────────── */
function search_recipes(
    string $query                = '',
    array  $includeIngredientIds = [],
    array  $excludeIngredientIds = [],
    ?int   $categoryId           = null,
    int    $offset               = 0,
    int    $limit                = 20,
    ?int   $userId               = null,
    string $sortKey              = 'latest'   // popular | trending | latest | recommended
): array {

    $pdo   = pdo();
    $query = sanitize($query);

    /* 4-A: map ชื่อวัตถุดิบที่พิมพ์มา (ingredients=...) ให้กลายเป็น ingredient_id ทั้งจากตาราง ingredients และ recipe_ingredient.descrip */
    if (!empty($_GET['ingredients']) || !empty($_POST['ingredients'])) {
        $raw   = $_GET['ingredients'] ?? $_POST['ingredients'];
        $terms = array_filter(array_map('trim', explode(',', $raw)));

        if ($terms) {
            $mapped = [];

            foreach ($terms as $term) {
                $like = "%{$term}%";

                // (1) ค้นจาก ingredients.name, display_name, searchable_keywords
                $stmt = $pdo->prepare("
                    SELECT ingredient_id
                      FROM ingredients
                     WHERE name              LIKE :t
                        OR display_name       LIKE :t
                        OR searchable_keywords LIKE :t
                ");
                $stmt->execute([':t' => $like]);
                $mapped = array_merge($mapped, $stmt->fetchAll(PDO::FETCH_COLUMN));

                // (2) ค้นจาก recipe_ingredient.descrip ด้วย
                $stmt = $pdo->prepare("
                    SELECT DISTINCT ingredient_id
                      FROM recipe_ingredient
                     WHERE descrip LIKE :t
                ");
                $stmt->execute([':t' => $like]);
                $mapped = array_merge($mapped, $stmt->fetchAll(PDO::FETCH_COLUMN));
            }
            $includeIngredientIds = array_unique(
                array_merge($includeIngredientIds, array_map('intval', $mapped))
            );
        }
    }

    /* 4-B: เตรียม SQL เงื่อนไข WHERE และ ORDER ตามพารามิเตอร์ */
    $where   = [];
    $params  = [];

    $orderBy = match ($sortKey) {
        'popular'     => 'fav_cnt DESC, r.average_rating DESC',
        'trending'    => 'review_cnt DESC',
        'recommended' => 'r.average_rating DESC',
        default       => 'r.created_at DESC',
    };
    if ($sortKey === 'trending') {
        $where[] = 'r.created_at >= NOW() - INTERVAL 30 DAY';
    }
    if ($sortKey === 'recommended') {
        $where[] = 'r.average_rating >= 4.0';
    }

    // LIKE-based search แบบ fuzzy
    if ($query !== '') {
        $queryNoSpace = preg_replace('/\s+/', '', $query);
        $where[] = "(
            r.name LIKE :q_exact
            OR REPLACE(r.name,' ','') LIKE :q_nospace
        )";
        $params[':q_exact']   = "%{$query}%";
        $params[':q_nospace'] = "%{$queryNoSpace}%";
    }

    /* 4-C: กรองจากวัตถุดิบที่ต้องมี / ไม่ต้องมี */
    $selectMatch = '';
    $orderMatch  = '';

    if ($includeIngredientIds) {
        $inc = implode(',', array_map('intval', $includeIngredientIds));

        // เพิ่มจำนวน match สำหรับ sorting
        $selectMatch = ",
          (SELECT COUNT(DISTINCT ri2.ingredient_id)
             FROM recipe_ingredient ri2
            WHERE ri2.recipe_id     = r.recipe_id
              AND ri2.ingredient_id IN ($inc)
          ) AS match_cnt";

        // เงื่อนไข: ต้องมีอย่างน้อย 1 วัตถุดิบจากที่ระบุ
        $where[] = "
          EXISTS (
            SELECT 1
              FROM recipe_ingredient ri
             WHERE ri.recipe_id     = r.recipe_id
               AND ri.ingredient_id IN ($inc)
          )";

        $orderMatch = 'match_cnt DESC, ';
    }

    if ($excludeIngredientIds) {
        $exc = implode(',', array_map('intval', $excludeIngredientIds));
        $where[] = "
          r.recipe_id NOT IN (
            SELECT ri.recipe_id
              FROM recipe_ingredient ri
             WHERE ri.ingredient_id IN ($exc)
          )";
    }

    /* 4-D: เพิ่มเงื่อนไขจาก category และแพ้อาหาร */
    if ($categoryId !== null) {
        $where[]        = '
          EXISTS (
            SELECT 1
              FROM category_recipe cr
             WHERE cr.recipe_id  = r.recipe_id
               AND cr.category_id = :cat
          )';
        $params[':cat'] = $categoryId;
    }
    if ($userId !== null) {
        $where[]        = '
          r.recipe_id NOT IN (
            SELECT ri.recipe_id
              FROM recipe_ingredient ri
              JOIN allergyinfo a ON a.ingredient_id = ri.ingredient_id
             WHERE a.user_id = :uid
          )';
        $params[':uid'] = $userId;
    }

    /* 4-E: สร้าง SQL คำสั่งหลัก */
    $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $sql = "
      SELECT
        r.recipe_id,
        r.name,
        r.image_path,
        r.prep_time,
        r.average_rating,
        (SELECT COUNT(*) FROM review    rv WHERE rv.recipe_id = r.recipe_id) AS review_cnt,
        (SELECT COUNT(*) FROM favorites f  WHERE f.recipe_id = r.recipe_id) AS fav_cnt,
        (SELECT GROUP_CONCAT(DISTINCT COALESCE(ri.descrip,'') SEPARATOR ', ')
           FROM recipe_ingredient ri WHERE ri.recipe_id = r.recipe_id) AS short_ingredients,
        (SELECT GROUP_CONCAT(DISTINCT ri.ingredient_id)
           FROM recipe_ingredient ri WHERE ri.recipe_id = r.recipe_id) AS ingredient_ids,
        r.created_at
        $selectMatch
      FROM recipe r
      $sqlWhere
      ORDER BY $orderMatch $orderBy
      LIMIT :offset, :limit
    ";

    // bind พารามิเตอร์
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    /* 4-F: จัดการ post-process เพื่อส่งกลับ API */
    $base = getBaseUrl() . '/uploads/recipes';
    foreach ($rows as &$r) {
        $r['image_url']      = $r['image_path']
                             ? "$base/" . basename($r['image_path'])
                             : "$base/default_recipe.png";
        $r['prep_time']      = $r['prep_time'] ? (int)$r['prep_time'] : null;
        $r['average_rating'] = (float)$r['average_rating'];
        $r['review_count']   = (int)$r['review_cnt'];
        $r['favorite_count'] = (int)$r['fav_cnt'];
        $r['ingredient_ids'] = array_filter(array_map('intval',
                                explode(',', $r['ingredient_ids'] ?? '')));
        $r['match_cnt']      = isset($r['match_cnt']) ? (int)$r['match_cnt'] : null;
        $r['has_allergy']    = false;
        unset($r['fav_cnt'], $r['review_cnt']);
    }
    return $rows;
}
