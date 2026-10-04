<?php
/**
 * Saves, updates or deletes the signed-in customer's own review of a product.
 * (Admins moderate reviews from Admin → Reviews; that's a separate page.)
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/');
require_csrf();

$productId = (int) ($_POST['product_id'] ?? 0);
$stmt = db()->prepare('SELECT id, slug FROM products WHERE id = ? AND is_active = 1');
$stmt->execute([$productId]);
$product = $stmt->fetch();
if (!$product) {
    flash_set('error', 'That product is no longer available.');
    redirect('/');
}
$back = product_url($product);

$user = current_user();
if (!$user) {
    $_SESSION['redirect_after_login'] = $back . '#reviews';
    flash_set('info', 'Please log in to review this product.');
    redirect('/login');
}

$existing = review_of_user((int) $user['id'], $productId);

// Deleting your own review is always allowed.
if (($_POST['action'] ?? '') === 'delete') {
    if ($existing) {
        db()->prepare('DELETE FROM product_reviews WHERE id = ? AND user_id = ?')->execute([$existing['id'], $user['id']]);
        flash_set('success', 'Your review was deleted.');
    }
    redirect($back . '#reviews');
}

// Writing a new review needs a received order for the product; editing an existing one doesn't.
if (!$existing && !review_has_purchased($user, $productId)) {
    flash_set('error', 'Only customers who have received this product can review it.');
    redirect($back . '#reviews');
}

[$errors, $clean] = review_validate($_POST);
if ($errors) {
    foreach ($errors as $err) flash_set('error', $err);
    $_SESSION['review_old'][$productId] = ['rating' => $clean['rating'], 'title' => (string) $clean['title'], 'body' => $clean['body']];
    redirect($back . '#review-form');
}

if ($existing) {
    // Editing keeps the review's status: a review the store hid stays hidden.
    db()->prepare('UPDATE product_reviews SET rating = ?, title = ?, body = ?, author_name = ? WHERE id = ? AND user_id = ?')
        ->execute([$clean['rating'], $clean['title'], $clean['body'], $user['name'], $existing['id'], $user['id']]);
    flash_set('success', 'Your review was updated.');
} else {
    db()->prepare('INSERT INTO product_reviews (product_id, user_id, author_name, rating, title, body) VALUES (?,?,?,?,?,?)')
        ->execute([$productId, $user['id'], $user['name'], $clean['rating'], $clean['title'], $clean['body']]);
    flash_set('success', 'Thanks — your review is live.');
}
redirect($back . '#reviews');
