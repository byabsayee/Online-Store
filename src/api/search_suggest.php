<?php
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');
header('Cache-Control: private, max-age=30');

$q = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
    echo json_encode(['items' => []]);
    exit;
}

$s = product_search_sql($q);
$stmt = db()->prepare(
    "SELECT p.name, p.slug, p.price, p.image_main, c.name AS category_name, ({$s['rank']}) AS relevance
     FROM products p LEFT JOIN categories c ON c.id = p.category_id
     WHERE p.is_active = 1 AND {$s['where']}
     ORDER BY relevance DESC, p.name ASC LIMIT 6"
);
$stmt->execute(array_merge($s['rank_params'], $s['where_params']));

$items = [];
foreach ($stmt->fetchAll() as $r) {
    $items[] = [
        'name' => $r['name'],
        'slug' => $r['slug'],
        'price' => money((float) $r['price']),
        'image' => product_image_src($r['image_main']),
        'category' => $r['category_name'],
    ];
}
echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE);
