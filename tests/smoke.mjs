import assert from 'node:assert/strict';

const base = process.argv[2] || 'http://127.0.0.1:8085/';
if (!['127.0.0.1', 'localhost'].includes(new URL(base).hostname)) throw new Error('Use a local, disposable test server.');
let passed = 0;
function check(condition, message) { assert.ok(condition, message); console.log('PASS ' + message); passed++; }
const decode = value => value.replace(/&quot;/g, '"').replace(/&#039;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
class Client {
    cookie = '';
    async request(path = '', fields = null) {
        const response = await fetch(new URL(path || 'index.php', base), {
            method: fields ? 'POST' : 'GET',
            redirect: 'manual',
            headers: {...(this.cookie ? {Cookie: this.cookie} : {}), ...(fields ? {'Content-Type': 'application/x-www-form-urlencoded'} : {})},
            ...(fields ? {body: new URLSearchParams(fields)} : {})
        });
        const cookies = response.headers.getSetCookie();
        if (cookies.length) this.cookie = cookies.at(-1).split(';')[0];
        const html = await response.text();
        if ([301, 302, 303].includes(response.status)) return this.request(response.headers.get('location'));
        return {html, status: response.status};
    }
    async post(fields, path = '') {
        const {html} = await this.request(path);
        const csrf = html.match(/name="csrf" value="([^"]+)"/)?.[1];
        assert.ok(csrf, 'CSRF token present');
        return this.request(path, {csrf, ...fields});
    }
}
const admin = new Client();
const initial = await admin.request();
assert.ok(initial.html.includes('Create your workspace'), 'REFUSING: server must use a fresh test database.');
const beforeSetup = new Client();
let preSetupResult = await beforeSetup.post({action:'register',name:'Early User',phone:'',password:'TestingPass123!',password_confirmation:'TestingPass123!',role:'admin'},'register.php');
check(preSetupResult.html.includes('initial setup first'), 'Registration cannot replace first administrator setup');
let result = await admin.post({action:'setup',name:'Test Admin',password:'TestingPass123!',phone:'123'});
check(result.html.includes('Your administrator account is ready.'), 'First administrator setup');
result = await admin.post({action:'setup',name:'Unauthorized Admin',password:'TestingPass123!'});
check(result.html.includes('Setup has already been completed.'), 'Setup cannot be repeated');
result = await admin.post({action:'save_user',id:'1',name:'Test Admin',phone:'123',role:'user',password:''},'index.php?page=users&edit=1');
check(result.html.includes('Keep at least one administrator.'), 'Final administrator cannot be demoted');
await admin.post({action:'save_user',name:'Test User',phone:'555',role:'user',password:'UserTesting123!'},'index.php?page=users');
result = await admin.request('index.php?page=users');
check(result.html.includes('Test User'), 'Create and list users');
result = await admin.post({action:'save_user',id:'2',name:'Test User',phone:'556',role:'user',password:''},'index.php?page=users&edit=2');
check(result.html.includes('User saved.') && result.html.includes('556'), 'Update user without replacing password');
result = await admin.post({action:'save_supplier',name:'Test Kitchen',phone:'111',comment:'Fresh food',password:'SupplierPass123!'},'index.php?page=suppliers');
check(result.html.includes('Test Kitchen'), 'Create and read supplier');
result = await admin.post({action:'save_supplier',id:'1',name:'Test Kitchen Updated',phone:'112',comment:'<script>alert(1)</script>',password:''},'index.php?page=suppliers&edit=1');
check(result.html.includes('&lt;script&gt;alert(1)&lt;/script&gt;') && !result.html.includes('<script>alert(1)</script>'), 'Supplier update and escaped stored content');
await admin.post({action:'save_supplier',name:'Other Kitchen',phone:'222',comment:'',password:''},'index.php?page=suppliers');
result = await admin.post({action:'save_item',supplier_id:'1',name:'Pasta',description:'Lunch',price:'8.50'},'index.php?page=items');
check(result.html.includes('Pasta') && result.html.includes('8.50 KM'), 'Create and read supplier item');
result = await admin.post({action:'quick_item',supplier_id:'1',name:'Soup',description:'Warm',price:'3,25'},'index.php?page=order');
const quick = JSON.parse(result.html);
check(quick.item.name === 'Soup' && quick.item.price_cents === 325, 'Quick item creation with exact cents');
await admin.post({action:'save_item',supplier_id:'2',name:'Other item',description:'',price:'4'},'index.php?page=items');
const order = {action:'save_order',supplier_id:'1',user_id:'1',status:'active',order_date:'2026-11-02T12:00',description:'Test order',lines:JSON.stringify([{supplier_item_id:1,quantity:2,description:'No cheese'},{supplier_item_id:2,quantity:1,description:''}])};
result = await admin.post(order,'index.php?page=order');
check(result.html.includes('Order #1 saved.') && result.html.includes('data-lines='), 'Create order with lines');
function savedLines(html) { return JSON.parse(decode(html.match(/data-lines="([^"]*)"/)[1])); }
let lines = savedLines(result.html);
check(lines.length === 2 && lines[0].quantity === 2 && lines[0].unit_price_cents === 850, 'Read quantities, notes and price snapshots');
result = await admin.request('index.php?page=order_summary&id=1');
check(result.html.includes('Test Kitchen Updated') && result.html.includes('<strong>112</strong>') && result.html.includes('Item name') && result.html.includes('Quantity') && result.html.includes('Description') && result.html.includes('No cheese') && result.html.includes('Print order'), 'Order summary shows supplier, phone and line table');
result = await admin.request('index.php?page=order_summary&id=1&layout=compact');
check(result.html.includes('Pasta x2 No cheese') && result.html.includes('Soup x1') && result.html.includes('Table view') && result.html.includes('02.11.2026'), 'Compact printable rows and date beside order number');
result = await admin.request('index.php?page=orders');
check(result.html.includes('20.25 KM'), 'Order list computes exact total');
await admin.post({action:'save_item',id:'1',supplier_id:'1',name:'Pasta',description:'New menu price',price:'10.00'},'index.php?page=items&edit=1');
result = await admin.request('index.php?page=order&id=1');
check(savedLines(result.html)[0].unit_price_cents === 850, 'Catalog price change preserves order price');
result = await admin.post({action:'save_item',id:'1',supplier_id:'2',name:'Pasta',description:'',price:'10.00'},'index.php?page=items&edit=1');
check(result.html.includes('cannot be moved'), 'Referenced item cannot change supplier');
const editedLines = [{id:lines[0].id,supplier_item_id:1,quantity:3,description:'Updated note'}];
result = await admin.post({...order,id:'1',version:'1',status:'ordered',lines:JSON.stringify(editedLines)},'index.php?page=order&id=1');
check(result.html.includes('Order #1 saved.') && savedLines(result.html).length === 1 && savedLines(result.html)[0].quantity === 3, 'Update order and edit/remove lines');
lines = savedLines(result.html);
check(lines[0].unit_price_cents === 850, 'Editing quantities retains original line price');
result = await admin.post({...order,id:'1',version:'1',lines:JSON.stringify([{id:lines[0].id,supplier_item_id:1,quantity:7,description:''}])},'index.php?page=order&id=1');
check(result.html.includes('updated in another window'), 'Stale order changes rejected');
result = await admin.post({...order,lines:JSON.stringify([{supplier_item_id:3,quantity:1,description:''}])},'index.php?page=order');
check(result.html.includes('must belong to the selected supplier'), 'Wrong supplier line rejected');
result = await admin.post({...order,lines:JSON.stringify([{supplier_item_id:1,quantity:0,description:''}])},'index.php?page=order');
check(result.html.includes('quantity from 1 to 9999'), 'Invalid quantity rejected');
result = await admin.post({action:'delete',entity:'items',id:'1'},'index.php?page=items');
check(result.html.includes('This record is in use.'), 'Referenced item protected from deletion');
result = await admin.post({action:'delete',entity:'suppliers',id:'1'},'index.php?page=suppliers');
check(result.html.includes('This record is in use.'), 'Referenced supplier protected from deletion');
const normal = new Client();
result = await normal.post({action:'login',name:'Test User',password:'UserTesting123!'});
check(result.html.includes('Hello, Test User'), 'User login after blank-password edit');
result = await normal.request('index.php?page=order&id=1');
check(result.status === 404 && result.html.includes('access denied'), 'Users cannot read another user’s order');
result = await normal.request('index.php?page=order_summary&id=1');
check(result.status === 404 && result.html.includes('access denied'), 'Order summary enforces ownership');
result = await normal.post({action:'delete',entity:'orders',id:'1'});
check(result.html.includes('access denied'), 'Users cannot delete another user’s order');
result = await normal.post({action:'save_user',name:'Intruder',role:'admin',phone:'',password:'TestingPass123!'});
check(result.html.includes('Administrator access is required.'), 'Users cannot administer accounts');
result = await normal.post({action:'reset_password',id:'1',password:'HackedPassword123!',password_confirmation:'HackedPassword123!'});
check(result.html.includes('Administrator access is required.'), 'Only administrators can reset passwords');
result = await admin.request('index.php?page=users&reset=2');
check(result.html.includes('Reset password for Test User') && result.html.includes('Confirm new password'), 'Explicit administrator reset form');
result = await admin.post({action:'reset_password',id:'2',password:'NewUserPassword123!',password_confirmation:'MismatchPassword123!'},'index.php?page=users&reset=2');
check(result.html.includes('passwords do not match'), 'Password reset rejects mismatched confirmation');
result = await admin.post({action:'reset_password',id:'2',password:'NewUserPassword123!',password_confirmation:'NewUserPassword123!'},'index.php?page=users&reset=2');
check(result.html.includes('Password reset successfully.'), 'Administrator resets user password');
result = await normal.request();
check(result.html.includes('Sign in to your account'), 'Password reset invalidates previous login session');
result = await normal.post({action:'login',name:'Test User',password:'UserTesting123!'});
check(result.html.includes('Incorrect name or password.'), 'Previous password stops working after reset');
result = await normal.post({action:'login',name:'Test User',password:'NewUserPassword123!'});
check(result.html.includes('Hello, Test User'), 'User can log in with reset password');
result = await normal.post({...order,user_id:'1',description:'User-owned order'},'index.php?page=order');
check(result.html.includes('Order #2 saved.'), 'Normal user creates an order');
result = await admin.request('index.php?page=orders');
check(result.html.includes('User-owned order') && result.html.includes('Test User'), 'Submitted owner is ignored for normal users');
result = await normal.request('index.php?page=orders');
check(!result.html.includes('Test order') && result.html.includes('User-owned order'), 'Order list is scoped to the signed-in user');
result = await admin.request('',{action:'delete',entity:'orders',id:'1',csrf:'invalid'});
check(result.html.includes('session token expired'), 'CSRF protection rejects writes');
result = await admin.post({action:'delete',entity:'users',id:'1'},'index.php?page=users');
check(result.html.includes('cannot delete your own account'), 'Self-deletion prevented');
await normal.post({action:'delete',entity:'orders',id:'2'},'index.php?page=orders');
await admin.post({action:'delete',entity:'orders',id:'1'},'index.php?page=orders');
result = await admin.request('index.php?page=orders');
check(result.html.includes('No orders yet.'), 'Delete orders');
await admin.post({action:'delete',entity:'items',id:'1'},'index.php?page=items');
await admin.post({action:'delete',entity:'items',id:'2'},'index.php?page=items');
await admin.post({action:'delete',entity:'items',id:'3'},'index.php?page=items');
result = await admin.request('index.php?page=items');
check(result.html.includes('Your directory is empty.'), 'Order deletion cascades lines and permits item deletion');
await admin.post({action:'delete',entity:'suppliers',id:'1'},'index.php?page=suppliers');
await admin.post({action:'delete',entity:'suppliers',id:'2'},'index.php?page=suppliers');
result = await admin.request('index.php?page=suppliers');
check(result.html.includes('Your directory is empty.'), 'Delete suppliers');
await admin.post({action:'delete',entity:'users',id:'2'},'index.php?page=users');
result = await admin.request('index.php?page=users');
check(!result.html.includes('<strong>Test User</strong>'), 'Delete user');
for (const path of ['storage/food_orders.sqlite','app/schema.sql','tests/smoke.mjs','README.md']) {
    const response = await admin.request(path);
    check([403,404].includes(response.status), 'Private path blocked: ' + path);
}
await admin.post({action:'logout'});
const wrong = new Client();
result = await wrong.post({action:'login',name:'Test Admin',password:'incorrect'});
check(result.html.includes('Incorrect name or password.'), 'Incorrect password rejected');
result = await wrong.post({action:'login',name:'Test Admin',password:'TestingPass123!'});
check(result.html.includes('Hello, Test Admin'), 'Administrator can sign in again');
const signup = new Client();
result = await signup.request('register.php');
check(result.html.includes('Create your account') && result.html.includes('Confirm password'), 'Public registration page is available');
result = await signup.post({action:'register',name:'Public User',phone:'123',password:'PublicPassword123!',password_confirmation:'DifferentPassword123!'},'register.php');
check(result.html.includes('passwords do not match'), 'Registration requires matching password confirmation');
result = await signup.post({action:'register',name:'Public User',phone:'123',password:'PublicPassword123!',password_confirmation:'PublicPassword123!',role:'admin'},'register.php');
check(result.html.includes('Your account is ready.') && result.html.includes('Hello, Public User'), 'Public registration creates account and signs in');
result = await signup.request('index.php?page=users');
check(result.status===403 && result.html.includes('Administrator access is required.'), 'Registration ignores forged admin role');
await signup.post({action:'logout'});
result = await signup.post({action:'register',name:'PUBLIC USER',phone:'',password:'PublicPassword123!',password_confirmation:'PublicPassword123!'},'register.php');
check(result.html.includes('already in use'), 'Registration rejects duplicate login names');
result = await signup.post({action:'login',name:'Public User',password:'PublicPassword123!'});
check(result.html.includes('Hello, Public User'), 'Registered user can sign in later');
console.log(`\n${passed} checks passed.`);
