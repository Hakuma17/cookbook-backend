<?php
// get_new_recipes.php — สูตรล่าสุด 10 รายการ

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php'; // เพิ่ม helper

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

try {
    $uid  = getLoggedInUserId();
    $base = getBaseUrl() . '/uploads/recipes';

    $sql = "
        SELECT r.recipe_id, r.name, r.image_path, r.prep_time,
               COALESCE(AVG(rv.rating),0)  AS average_rating,
               COUNT(rv.rating)            AS review_count,
               GROUP_CONCAT(DISTINCT ri.descrip ORDER BY ri.ingredient_id
                            SEPARATOR ', ')                               AS short_ingredients,
               GROUP_CONCAT(DISTINCT ri.ingredient_id ORDER BY ri.ingredient_id
                            SEPARATOR ',')                                AS ingredient_ids,
               " . ($uid ? "CASE WHEN EXISTS (
                        SELECT 1 FROM recipe_ingredient ri2
                        JOIN allergyinfo a ON a.ingredient_id = ri2.ingredient_id
                        WHERE ri2.recipe_id = r.recipe_id AND a.user_id = :uid
                      ) THEN 1 ELSE 0 END" : "0") . "                     AS has_allergy
        FROM recipe r
        LEFT JOIN review rv            ON rv.recipe_id = r.recipe_id
        LEFT JOIN recipe_ingredient ri ON ri.recipe_id = r.recipe_id
        GROUP BY r.recipe_id
        ORDER BY r.created_at DESC
        LIMIT 10
    ";

    $rows = $uid
        ? dbAll($sql, ['uid' => $uid])
        : dbAll($sql);

    $data = [];
    foreach ($rows as $row) {
        $data[] = [
            'recipe_id'         => (int)$row['recipe_id'],
            'name'              => $row['name'],
            'prep_time'         => $row['prep_time'] ? (int)$row['prep_time'] : null,
            'average_rating'    => (float)$row['average_rating'],
            'review_count'      => (int)$row['review_count'],
            'short_ingredients' => $row['short_ingredients'] ?? '',
            'has_allergy'       => (bool)$row['has_allergy'],
            'image_url'         => $base . '/' . ($row['image_path'] ?: 'default_recipe.jpg'),
            'ingredient_ids'    => array_filter(array_map('intval',
                                        explode(',', $row['ingredient_ids'] ?? ''))),
        ];
    }

    jsonOutput(['success' => true, 'data' => $data]);

} catch (Throwable $e) {
    error_log('[new_recipes] ' . $e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
