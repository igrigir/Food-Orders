<?php
declare(strict_types=1);

function handle_action(string $action): void {
    global $db;
    verify_csrf();
    if ($action === 'setup') {
        $name = field('name', 100, true);
        $phone = field('phone', 50);
        $hash = password_value();
        $db->exec('BEGIN IMMEDIATE');
        try {
            if ((int)query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0) throw new RuntimeException('Setup has already been completed.');
            query('INSERT INTO users(name, password_hash, phone, role) VALUES(?,?,?,?)', [$name, $hash, $phone, 'admin']);
            $id = (int)$db->lastInsertId();
            $db->exec('COMMIT');
        } catch (Throwable $error) { $db->exec('ROLLBACK'); throw $error; }
        session_regenerate_id(true);
        $_SESSION['user_id'] = $id;
        $_SESSION['auth_hash'] = hash('sha256',$hash);
        unset($_SESSION['csrf']);
        flash('Your administrator account is ready.');
        redirect('index.php');
    }
    if ($action === 'register') {
        if (!(int)query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn()) throw new RuntimeException('An administrator must complete the initial setup first.');
        $name = field('name',100,true);
        $phone = field('phone',50);
        $hash = password_value();
        $confirmation = $_POST['password_confirmation'] ?? null;
        if (!is_string($confirmation) || !hash_equals($_POST['password'],$confirmation)) throw new RuntimeException('The passwords do not match.');
        query("INSERT INTO users(name,password_hash,phone,role) VALUES(?,?,?,'user')",[$name,$hash,$phone]);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$db->lastInsertId();
        $_SESSION['auth_hash'] = hash('sha256',$hash);
        unset($_SESSION['csrf']);
        flash('Your account is ready. Welcome to Food Orders.');
        redirect('index.php');
    }
    if ($action === 'login') {
        $address = $_SERVER['REMOTE_ADDR'] ?? 'local';
        $attempt = query('SELECT * FROM login_attempts WHERE address = ?', [$address])->fetch();
        if ($attempt && (int)$attempt['window_start'] > time() - 900 && (int)$attempt['attempts'] >= 10) throw new RuntimeException('Too many login attempts. Try again in 15 minutes.');
        $user = query('SELECT * FROM users WHERE name = ?', [field('name', 100, true)])->fetch();
        $password = $_POST['password'] ?? '';
        $hash = $user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $valid = is_string($password) && password_verify($password, $hash);
        if (!$user || !$valid) {
            query('INSERT INTO login_attempts(address,attempts,window_start) VALUES(?,1,?) ON CONFLICT(address) DO UPDATE SET attempts = CASE WHEN window_start <= ? THEN 1 ELSE attempts+1 END, window_start = CASE WHEN window_start <= ? THEN excluded.window_start ELSE window_start END', [$address,time(),time()-900,time()-900]);
            throw new RuntimeException('Incorrect name or password.');
        }
        query('DELETE FROM login_attempts WHERE address = ?', [$address]);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['auth_hash'] = hash('sha256',$user['password_hash']);
        unset($_SESSION['csrf']);
        redirect('index.php');
    }
    if (!current_user()) throw new RuntimeException('Please sign in to continue.');
    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        redirect('index.php');
    }
    if ($action === 'reset_password') {
        require_admin();
        $id = positive_id($_POST['id'] ?? null);
        if (!query('SELECT id FROM users WHERE id=?',[$id])->fetch()) throw new RuntimeException('User not found.');
        $hash = password_value();
        $confirmation = $_POST['password_confirmation'] ?? null;
        if (!is_string($confirmation) || !hash_equals($_POST['password'], $confirmation)) throw new RuntimeException('The passwords do not match.');
        set_user_password($id,$hash);
        flash('Password reset successfully. The user can now sign in with the new password.');
        redirect('index.php?page=users');
    }
    if ($action === 'save_user') {
        require_admin();
        $id = empty($_POST['id']) ? null : positive_id($_POST['id']);
        $name = field('name', 100, true); $phone = field('phone', 50);
        $role = field('role');
        if (!in_array($role, ['admin','user'], true)) throw new RuntimeException('Invalid role.');
        $hash = password_value(!$id);
        $db->beginTransaction();
        try {
            if ($id) {
                $existing = query('SELECT * FROM users WHERE id = ?', [$id])->fetch();
                if (!$existing) throw new RuntimeException('User not found.');
                if ($existing['role'] === 'admin' && $role !== 'admin' && (int)query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() <= 1) throw new RuntimeException('Keep at least one administrator.');
                query('UPDATE users SET name=?, phone=?, role=? WHERE id=?', [$name,$phone,$role,$id]);
                if ($hash) set_user_password($id,$hash);
            } else query('INSERT INTO users(name,phone,role,password_hash) VALUES(?,?,?,?)', [$name,$phone,$role,$hash]);
            $db->commit();
        } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
        flash('User saved.'); redirect('index.php?page=users');
    }
    if ($action === 'save_supplier') {
        $id = empty($_POST['id']) ? null : positive_id($_POST['id']);
        $values = [field('name',150,true),field('phone',50),field('comment',2000)];
        $hash = password_value(false);
        if ($id) {
            if (!query('SELECT id FROM suppliers WHERE id=?',[$id])->fetch()) throw new RuntimeException('Supplier not found.');
            query('UPDATE suppliers SET name=?,phone=?,comment=? WHERE id=?', [...$values,$id]);
            if ($hash) query('UPDATE suppliers SET password_hash=? WHERE id=?', [$hash,$id]);
        } else query('INSERT INTO suppliers(name,phone,comment,password_hash) VALUES(?,?,?,?)', [...$values,$hash]);
        flash('Supplier saved.'); redirect('index.php?page=suppliers');
    }
    if ($action === 'save_item' || $action === 'quick_item') {
        $id = ($action !== 'quick_item' && !empty($_POST['id'])) ? positive_id($_POST['id']) : null;
        $supplier = positive_id($_POST['supplier_id'] ?? null);
        if (!query('SELECT id FROM suppliers WHERE id=?',[$supplier])->fetch()) throw new RuntimeException('Supplier not found.');
        $values = [$supplier,field('name',150,true),field('description',2000),cents(field('price',20,true))];
        if ($id) {
            $old = query('SELECT * FROM supplier_items WHERE id=?',[$id])->fetch();
            if (!$old) throw new RuntimeException('Item not found.');
            if ((int)$old['supplier_id'] !== $supplier && query('SELECT id FROM order_items WHERE supplier_item_id=? LIMIT 1',[$id])->fetch()) throw new RuntimeException('An item used in an order cannot be moved to another supplier.');
            query('UPDATE supplier_items SET supplier_id=?,name=?,description=?,price_cents=? WHERE id=?',[...$values,$id]);
        } else query('INSERT INTO supplier_items(supplier_id,name,description,price_cents) VALUES(?,?,?,?)',$values);
        if ($action === 'quick_item') {
            header('Content-Type: application/json');
            echo json_encode(['item'=>['id'=>(int)$db->lastInsertId(),'supplier_id'=>$supplier,'name'=>$values[1],'description'=>$values[2],'price_cents'=>$values[3]]]);
            exit;
        }
        flash('Supplier item saved.'); redirect('index.php?page=items&supplier_id='.$supplier);
    }
    if ($action === 'save_order') {
        $id = empty($_POST['id']) ? null : positive_id($_POST['id']);
        $old = $id ? order_access($id) : null;
        $supplier = positive_id($_POST['supplier_id'] ?? null);
        $owner = is_admin() ? positive_id($_POST['user_id'] ?? null) : (int)current_user()['id'];
        $status = field('status');
        if (!in_array($status,['active','inactive','ordered','delivered'],true)) throw new RuntimeException('Invalid order status.');
        $date = field('order_date',16,true);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$date);
        if (!$parsed || $parsed->format('Y-m-d\TH:i') !== $date) throw new RuntimeException('Invalid order date.');
        $description = field('description',2000);
        $lines = json_decode(field('lines',100000,true),true,512,JSON_THROW_ON_ERROR);
        if (!is_array($lines) || count($lines) < 1 || count($lines) > 100) throw new RuntimeException('Add between 1 and 100 order lines.');
        $db->beginTransaction();
        try {
            if (!query('SELECT id FROM suppliers WHERE id=?',[$supplier])->fetch() || !query('SELECT id FROM users WHERE id=?',[$owner])->fetch()) throw new RuntimeException('Supplier or user no longer exists.');
            $oldLines = $id ? query('SELECT * FROM order_items WHERE order_id=?',[$id])->fetchAll(PDO::FETCH_UNIQUE) : [];
            $validated = []; $seen = [];
            foreach ($lines as $line) {
                if (!is_array($line)) throw new RuntimeException('Invalid order line.');
                $itemId = positive_id($line['supplier_item_id'] ?? null);
                $quantity = filter_var($line['quantity'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>9999]]);
                $note = $line['description'] ?? '';
                if ($quantity === false || !is_string($note) || mb_strlen($note)>1000) throw new RuntimeException('Use a quantity from 1 to 9999 and a note up to 1000 characters.');
                $item = query('SELECT * FROM supplier_items WHERE id=? AND supplier_id=?',[$itemId,$supplier])->fetch();
                if (!$item) throw new RuntimeException('Every order item must belong to the selected supplier.');
                $lineId = empty($line['id']) ? null : positive_id($line['id']);
                if ($lineId && (isset($seen[$lineId]) || !isset($oldLines[$lineId]) || (int)$oldLines[$lineId]['supplier_item_id'] !== $itemId)) throw new RuntimeException('An order line was changed unexpectedly. Reload the order.');
                if ($lineId) $seen[$lineId] = true;
                $price = $lineId ? (int)$oldLines[$lineId]['unit_price_cents'] : (int)$item['price_cents'];
                $validated[] = [$itemId,$quantity,trim($note),$price];
            }
            if ($id) {
                $version = positive_id($_POST['version'] ?? null);
                $updated = query('UPDATE orders SET order_date=?,status=?,user_id=?,supplier_id=?,description=?,version=version+1 WHERE id=? AND version=?',[$date,$status,$owner,$supplier,$description,$id,$version]);
                if (!$updated->rowCount()) throw new RuntimeException('This order was updated in another window. Reload before saving.');
                query('DELETE FROM order_items WHERE order_id=?',[$id]);
            } else {
                query('INSERT INTO orders(order_date,status,user_id,supplier_id,description) VALUES(?,?,?,?,?)',[$date,$status,$owner,$supplier,$description]);
                $id = (int)$db->lastInsertId();
            }
            foreach ($validated as $line) query('INSERT INTO order_items(order_id,supplier_item_id,quantity,description,unit_price_cents) VALUES(?,?,?,?,?)',[$id,...$line]);
            $db->commit();
        } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
        flash('Order #'.$id.' saved.'); redirect('index.php?page=order&id='.$id);
    }
    if ($action === 'delete') {
        $entity = field('entity'); $id = positive_id($_POST['id'] ?? null);
        $tables = ['users'=>'users','suppliers'=>'suppliers','items'=>'supplier_items','orders'=>'orders'];
        if (!isset($tables[$entity])) throw new RuntimeException('Unknown record type.');
        if ($entity === 'users') {
            require_admin();
            if ($id === (int)current_user()['id']) throw new RuntimeException('You cannot delete your own account.');
            $user = query('SELECT * FROM users WHERE id=?',[$id])->fetch();
            if ($user && $user['role'] === 'admin' && (int)query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn()<=1) throw new RuntimeException('Keep at least one administrator.');
        }
        if ($entity === 'orders') order_access($id);
        query('DELETE FROM '.$tables[$entity].' WHERE id=?',[$id]);
        flash('Record deleted.'); redirect('index.php?page='.$entity);
    }
    throw new RuntimeException('Unknown action.');
}
