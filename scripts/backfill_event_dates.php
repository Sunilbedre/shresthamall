<?php
/**
 * Backfill customers.event_date from vouchers when missing (legacy rows).
 */
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

$pdo = Database::connection();
$before = (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE event_date IS NULL OR event_date = ''")->fetchColumn();
$pdo->exec("
    UPDATE customers
    SET event_date = (
        SELECT v.event_date FROM vouchers v
        WHERE v.customer_id = customers.id
        ORDER BY v.id ASC
        LIMIT 1
    )
    WHERE (event_date IS NULL OR event_date = '')
      AND EXISTS (SELECT 1 FROM vouchers v2 WHERE v2.customer_id = customers.id AND v2.event_date IS NOT NULL AND v2.event_date != '')
");
$after = (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE event_date IS NULL OR event_date = ''")->fetchColumn();
$filled = $before - $after;
echo "backfilled={$filled}\n";
echo "still_null={$after}\n";
$rows = $pdo->query("SELECT COALESCE(event_date,'(null)') d, COUNT(*) c FROM customers GROUP BY event_date ORDER BY d")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo $r['d'] . '=' . $r['c'] . PHP_EOL;
}
