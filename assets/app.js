'use strict';
document.querySelectorAll('[data-print]').forEach(button => button.addEventListener('click', () => window.print()));
document.querySelectorAll('form[data-confirm]').forEach(form => form.addEventListener('submit', event => {
    if (!window.confirm(form.dataset.confirm)) event.preventDefault();
}));

const data = document.getElementById('order-data');
if (data) {
    const items = JSON.parse(data.dataset.items);
    let lines = JSON.parse(data.dataset.lines).map(line => ({...line, id: Number(line.id || 0), supplier_item_id: Number(line.supplier_item_id), quantity: Number(line.quantity), unit_price_cents: Number(line.unit_price_cents)}));
    const form = document.getElementById('order-form');
    const supplier = document.getElementById('order-supplier');
    const picker = document.getElementById('item-picker');
    const itemDescription = document.getElementById('item-description');
    const body = document.getElementById('line-rows');
    const feedback = document.getElementById('order-feedback');
    const dialog = document.getElementById('quick-dialog');
    let previousSupplier = supplier.value;
    let dirty = false;
    const currency = cents => (cents / 100).toLocaleString('en-IE', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' KM';
    const element = (tag, className, text) => {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    };
    const serialize = () => { document.getElementById('order-lines').value = JSON.stringify(lines.map(line => ({id: line.id, supplier_item_id: line.supplier_item_id, quantity: line.quantity, description: line.description}))); };
    function totals() {
        document.getElementById('summary-count').textContent = lines.reduce((n, line) => n + (Number(line.quantity) || 0), 0);
        document.getElementById('summary-total').textContent = currency(lines.reduce((n, line) => n + (Number(line.quantity) || 0) * line.unit_price_cents, 0));
        document.getElementById('empty-lines').hidden = lines.length > 0;
        serialize();
    }
    function renderLines() {
        body.replaceChildren();
        lines.forEach((line, index) => {
            const row = element('tr');
            const info = element('td');
            info.append(element('strong', '', line.name));
            const note = element('input', 'line-note');
            note.value = line.description;
            note.maxLength = 1000;
            note.placeholder = 'Item description…';
            note.setAttribute('aria-label', 'Description for ' + line.name);
            note.addEventListener('input', () => {line.description = note.value; dirty = true; serialize();});
            info.append(note);
            const quantityCell = element('td');
            const quantity = element('input', 'quantity');
            quantity.type = 'number'; quantity.min = '1'; quantity.max = '9999'; quantity.step = '1'; quantity.required = true; quantity.value = line.quantity;
            quantity.setAttribute('aria-label', 'Quantity for ' + line.name);
            const total = element('td', 'number', currency(line.quantity * line.unit_price_cents));
            quantity.addEventListener('input', () => {line.quantity = Number(quantity.value); total.textContent = currency(line.quantity * line.unit_price_cents); dirty = true; totals();});
            quantityCell.append(quantity);
            const action = element('td');
            const remove = element('button', 'remove-line', '×');
            remove.type = 'button'; remove.setAttribute('aria-label', 'Remove ' + line.name);
            remove.addEventListener('click', () => {lines.splice(index, 1); dirty = true; renderLines();});
            action.append(remove);
            row.append(info, quantityCell, element('td', 'number', currency(line.unit_price_cents)), total, action);
            body.append(row);
        });
        totals();
    }
    function renderPicker() {
        picker.replaceChildren(new Option(supplier.value ? 'Choose an item…' : 'Choose a supplier first', ''));
        items.filter(item => String(item.supplier_id) === supplier.value).forEach(item => picker.add(new Option(item.name + ' · ' + currency(Number(item.price_cents)), item.id)));
        picker.disabled = !supplier.value;
        document.getElementById('quick-open').disabled = !supplier.value;
        document.getElementById('summary-supplier').textContent = supplier.value ? supplier.selectedOptions[0].textContent : '—';
    }
    function addItem(item) {
        if (lines.length >= 100) {feedback.textContent = 'An order can have up to 100 lines.'; return;}
        lines.push({id: 0, supplier_item_id: Number(item.id), name: item.name, quantity: 1, description: itemDescription.value.trim(), unit_price_cents: Number(item.price_cents)});
        itemDescription.value = '';
        dirty = true; renderLines(); feedback.textContent = item.name + ' added to the order.';
    }
    supplier.addEventListener('change', () => {
        if (lines.length && !confirm('Changing the supplier will remove the current order lines. Continue?')) {supplier.value = previousSupplier; return;}
        itemDescription.value = '';
        lines = []; previousSupplier = supplier.value; dirty = true; feedback.textContent = ''; renderPicker(); renderLines();
    });
    document.getElementById('add-line').addEventListener('click', () => {
        const item = items.find(item => String(item.id) === picker.value && String(item.supplier_id) === supplier.value);
        if (!item) { feedback.textContent = 'Choose a supplier and an item first.'; return; }
        addItem(item);
    });
    document.getElementById('quick-open').addEventListener('click', () => {
        if (!supplier.value) return;
        document.getElementById('quick-supplier').value = supplier.value;
        document.getElementById('quick-supplier-name').textContent = supplier.selectedOptions[0].textContent;
        document.getElementById('quick-error').hidden = true;
        dialog.showModal();
    });
    document.getElementById('quick-close').addEventListener('click', () => dialog.close());
    document.getElementById('quick-form').addEventListener('submit', async event => {
        event.preventDefault();
        const quickForm = event.currentTarget;
        const button = quickForm.querySelector('button[type="submit"]');
        const error = document.getElementById('quick-error');
        button.disabled = true; error.hidden = true;
        try {
            const response = await fetch('index.php', {method: 'POST', body: new FormData(quickForm), headers: {'Accept': 'application/json'}});
            const result = await response.json();
            if (!response.ok || !result.item) throw new Error(result.error || 'Could not create the item. Your session may have expired.');
            items.push(result.item); renderPicker(); picker.value = result.item.id; addItem(result.item); quickForm.reset(); dialog.close();
        } catch (exception) {error.textContent = exception.message || 'Connection failed. Please try again.'; error.hidden = false;}
        finally {button.disabled = false;}
    });
    form.addEventListener('input', () => {dirty = true;});
    form.addEventListener('submit', event => {
        if (!lines.length) {event.preventDefault(); feedback.textContent = 'Add at least one item before saving.'; feedback.scrollIntoView({block: 'center'}); return;}
        serialize(); dirty = false; document.getElementById('save-order').disabled = true;
    });
    window.addEventListener('beforeunload', event => {if (dirty) {event.preventDefault(); event.returnValue = '';}});
    renderPicker(); renderLines();
}
