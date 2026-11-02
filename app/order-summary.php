<?php
declare(strict_types=1);
$id = positive_id($_GET['id'] ?? null);
$order = order_access($id);
$compact = ($_GET['layout'] ?? '') === 'compact';
$displayDate = (new DateTimeImmutable($order['order_date']))->format('d.m.Y');
$supplier = query('SELECT name,phone FROM suppliers WHERE id=?',[$order['supplier_id']])->fetch();
$owner = query('SELECT name FROM users WHERE id=?',[$order['user_id']])->fetch();
$lines = query('SELECT oi.quantity,oi.description,i.name FROM order_items oi JOIN supplier_items i ON i.id=oi.supplier_item_id WHERE oi.order_id=? ORDER BY oi.id',[$id])->fetchAll();
?>
<div class="page-heading no-print"><div><a class="text-link" href="index.php?page=orders">← All orders</a><h1>Order summary</h1><p class="muted">Order #<?= $id ?> · <?= e($displayDate) ?></p></div><div class="form-actions"><a href="index.php?page=order_summary&id=<?= $id ?><?= $compact ? '' : '&layout=compact' ?>" class="button"><?= $compact ? 'Table view' : 'Compact view' ?></a><a href="index.php?page=order&id=<?= $id ?>" class="button">Edit order</a><button type="button" class="button primary" data-print>Print order</button></div></div>
<article class="panel order-sheet">
    <header class="supplier-header"><div><span class="eyebrow">SUPPLIER</span><h2><?= e($supplier['name']) ?></h2><div class="supplier-phone"><span>Phone</span> <strong><?= $supplier['phone'] !== '' ? e($supplier['phone']) : 'Not provided' ?></strong></div></div><div class="sheet-order-id"><span>ORDER</span><strong>#<?= $id ?></strong><time datetime="<?= e(substr($order['order_date'],0,10)) ?>"><?= e($displayDate) ?></time><span class="badge <?= e($order['status']) ?>"><?= e(ucfirst($order['status'])) ?></span></div></header>
    <div class="sheet-meta"><div><span>Order date</span><strong><?= e(str_replace('T',' ',$order['order_date'])) ?></strong></div><div><span>Placed for</span><strong><?= e($owner['name']) ?></strong></div><div><span>Total quantity</span><strong><?= array_sum(array_column($lines,'quantity')) ?></strong></div></div>
    <?php if($compact): ?>
    <div class="table-wrap"><table class="compact-order-table"><thead><tr><th>Order items</th></tr></thead><tbody><?php foreach($lines as $line): $description = trim(preg_replace('/\s+/u',' ',$line['description'])); ?><tr><td><?= e($line['name'].' x'.(int)$line['quantity'].($description !== '' ? ' '.$description : '')) ?></td></tr><?php endforeach; ?><?php if(!$lines): ?><tr><td class="empty">This order has no items.</td></tr><?php endif; ?></tbody></table></div>
    <?php else: ?>
    <div class="table-wrap"><table class="summary-table"><thead><tr><th>Item name</th><th class="summary-quantity">Quantity</th><th>Description</th></tr></thead><tbody><?php foreach($lines as $line): ?><tr><td><strong><?= e($line['name']) ?></strong></td><td class="summary-quantity"><?= (int)$line['quantity'] ?></td><td class="preserve-lines"><?= $line['description']!=='' ? e($line['description']) : '—' ?></td></tr><?php endforeach; ?><?php if(!$lines): ?><tr><td colspan="3" class="empty">This order has no items.</td></tr><?php endif; ?></tbody></table></div>
    <?php endif; ?>
    <?php if($order['description']!==''): ?><section class="sheet-notes"><span class="eyebrow">ORDER NOTES</span><p class="preserve-lines"><?= e($order['description']) ?></p></section><?php endif; ?>
</article>
