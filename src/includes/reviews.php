<?php
/**
 * Product reviews.
 *
 * Who can review: a signed-in customer who has received the product — i.e. has an order for it
 * that is Shipped or Completed. The order counts if it was placed from their account, or (for
 * guest checkouts) if it carries the same email as their account and that email is verified.
 * One review per customer per product; they can edit or delete their own. Admins can hide or
 * delete any review (Admin → Reviews). Only published reviews are shown and counted.
 */

const REVIEW_MAX_BODY = 2000;
const REVIEW_MIN_BODY = 5;
const REVIEWS_PER_PAGE = 6;

/**
 * Star rating as markup. The stars are drawn in CSS from --rating (0–5), so half-stars and
 * averages like 4.3 render correctly. The text alternative is in aria-label.
 */
function stars_html(float $rating, string $class = ''): string {
    $r = max(0.0, min(5.0, $rating));
    return '<span class="stars ' . e($class) . '" style="--rating:' . number_format($r, 2, '.', '') . '" role="img" aria-label="'
        . e(rtrim(rtrim(number_format($r, 1, '.', ''), '0'), '.') ?: '0') . ' out of 5 stars"></span>';
}

/** "Rafi Hasan" → "Rafi H." — reviews are public, so we don't print customers' full names. */
function review_display_name(string $full): string {
    $parts = preg_split('/\s+/u', trim($full), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$parts) return 'Customer';
    $first = mb_substr($parts[0], 0, 30);
    return count($parts) > 1 ? $first . ' ' . mb_strtoupper(mb_substr(end($parts), 0, 1)) . '.' : $first;
}

/** @return array{count:int, avg:float, dist:array<int,int>} dist maps 5..1 → how many reviews gave that many stars */
function review_summary(int $productId): array {
    $s = db()->prepare("SELECT rating, COUNT(*) c FROM product_reviews WHERE product_id = ? AND status = 'published' GROUP BY rating");
    $s->execute([$productId]);
    $dist = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
    $count = 0; $sum = 0;
    foreach ($s->fetchAll() as $r) {
        $n = (int) $r['rating'];
        if (!isset($dist[$n])) continue;
        $dist[$n] = (int) $r['c'];
        $count += (int) $r['c'];
        $sum += $n * (int) $r['c'];
    }
    return ['count' => $count, 'avg' => $count ? round($sum / $count, 2) : 0.0, 'dist' => $dist];
}

/** Published reviews, newest first. */
function review_list(int $productId, int $limit, int $offset = 0): array {
    $s = db()->prepare(
        "SELECT id, author_name, rating, title, body, created_at, updated_at FROM product_reviews
         WHERE product_id = ? AND status = 'published' ORDER BY created_at DESC, id DESC LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset
    );
    $s->execute([$productId]);
    return $s->fetchAll();
}

/** This customer's own review of the product (published or hidden), or null. */
function review_of_user(int $userId, int $productId): ?array {
    $s = db()->prepare('SELECT * FROM product_reviews WHERE user_id = ? AND product_id = ?');
    $s->execute([$userId, $productId]);
    return $s->fetch() ?: null;
}

/** Has this customer received the product? (see the file header for exactly what counts) */
function review_has_purchased(array $user, int $productId): bool {
    $s = db()->prepare(
        "SELECT 1 FROM orders o JOIN order_items oi ON oi.order_id = o.id
         WHERE oi.product_id = ? AND o.status IN ('shipped','completed')
           AND (o.user_id = ? OR (? = 1 AND o.customer_email IS NOT NULL AND LOWER(o.customer_email) = LOWER(?)))
         LIMIT 1"
    );
    $s->execute([$productId, (int) $user['id'], !empty($user['email_verified']) ? 1 : 0, (string) $user['email']]);
    return (bool) $s->fetchColumn();
}

/**
 * What the review box on a product page should offer.
 * state: guest (log in) | can_review | cannot (not received it yet)
 * @return array{state:string, existing:?array}
 */
function review_box_state(?array $user, int $productId): array {
    if (!$user) return ['state' => 'guest', 'existing' => null];
    $existing = review_of_user((int) $user['id'], $productId);
    // Someone who already reviewed keeps the right to edit or delete it, even if the order is cancelled later.
    if ($existing || review_has_purchased($user, $productId)) return ['state' => 'can_review', 'existing' => $existing];
    return ['state' => 'cannot', 'existing' => null];
}

/**
 * Cleans and validates a submitted review.
 * @return array{0:array<int,string>, 1:array{rating:int,title:?string,body:string}}
 */
function review_validate(array $in): array {
    $errors = [];
    $rating = (int) ($in['rating'] ?? 0);
    if ($rating < 1 || $rating > 5) $errors[] = 'Please choose a star rating from 1 to 5.';
    $title = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($in['title'] ?? ''))), 0, 120);
    $body = trim(str_replace("\r", '', (string) ($in['body'] ?? '')));
    if (mb_strlen($body) < REVIEW_MIN_BODY) $errors[] = 'Please write a few words about the product.';
    if (mb_strlen($body) > REVIEW_MAX_BODY) $errors[] = 'Your review is too long — please keep it under ' . REVIEW_MAX_BODY . ' characters.';
    return [$errors, ['rating' => $rating, 'title' => $title !== '' ? $title : null, 'body' => $body]];
}
