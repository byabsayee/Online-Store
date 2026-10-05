-- Cookie Policy link in the footer's Legal column (existing stores). Idempotent.
INSERT INTO footer_links (group_title, label, url, group_order, sort_order)
SELECT 'Legal', 'Cookie Policy', '/cookie-policy', COALESCE((SELECT MIN(group_order) FROM footer_links WHERE group_title = 'Legal'), 2), 5
FROM DUAL
WHERE EXISTS (SELECT 1 FROM footer_links WHERE group_title = 'Legal')
  AND NOT EXISTS (SELECT 1 FROM footer_links WHERE url = '/cookie-policy');
