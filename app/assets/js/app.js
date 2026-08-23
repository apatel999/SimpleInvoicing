(function () {
  var confirmModal = document.getElementById('confirm-modal');
  var confirmText = document.getElementById('confirm-modal-text');
  var confirmOk = document.getElementById('confirm-modal-ok');
  var confirmCancel = document.getElementById('confirm-modal-cancel');
  var pendingForm = null;

  function closeConfirmModal() {
    pendingForm = null;
    if (confirmModal) confirmModal.hidden = true;
    document.body.classList.remove('modal-open');
  }

  function openConfirmModal(form) {
    pendingForm = form;
    var message = form.getAttribute('data-confirm') || 'Do you want to continue?';
    if (confirmText) confirmText.textContent = message;
    if (confirmOk) confirmOk.textContent = /delete/i.test(message) ? 'Delete' : 'Confirm';
    if (confirmModal) confirmModal.hidden = false;
    document.body.classList.add('modal-open');
    if (confirmOk) confirmOk.focus();
  }

  document.addEventListener('submit', function (event) {
    var form = event.target;
    var message = form.getAttribute('data-confirm');
    if (!message) return;
    event.preventDefault();
    openConfirmModal(form);
  });

  if (confirmOk) {
    confirmOk.addEventListener('click', function () {
      var form = pendingForm;
      if (!form) return;
      form.removeAttribute('data-confirm');
      closeConfirmModal();
      form.submit();
    });
  }
  if (confirmCancel) {
    confirmCancel.addEventListener('click', closeConfirmModal);
  }
  if (confirmModal) {
    confirmModal.addEventListener('click', function (event) {
      if (event.target === confirmModal) {
        closeConfirmModal();
      }
    });
  }
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && confirmModal && !confirmModal.hidden) {
      closeConfirmModal();
    }
  });

  var sendPrompt = document.getElementById('send-prompt');
  if (sendPrompt) {
    var dismissSend = sendPrompt.querySelector('a.btn-ghost');
    document.body.classList.add('modal-open');
    sendPrompt.addEventListener('click', function (event) {
      if (event.target === sendPrompt && dismissSend) {
        window.location.href = dismissSend.href;
      }
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && dismissSend) {
        window.location.href = dismissSend.href;
      }
    });
  }

  var tbody = document.getElementById('item-rows');
  var addBtn = document.getElementById('add-item');
  var invoiceForm = document.getElementById('invoice-form');

  function currency() {
    return (invoiceForm && invoiceForm.getAttribute('data-currency')) || '$';
  }

  function moneyText(amount) {
    return currency() + ' ' + amount.toFixed(2);
  }

  if (tbody && addBtn) {
    addBtn.addEventListener('click', function () {
      addItemRow();
      recalc();
    });
    tbody.addEventListener('click', function (event) {
      var remove = event.target.closest ? event.target.closest('.remove-item') : (event.target.classList.contains('remove-item') ? event.target : null);
      if (remove) {
        var rows = tbody.querySelectorAll('tr');
        if (rows.length > 1) {
          remove.closest('tr').remove();
          recalc();
        }
      }
    });
    tbody.addEventListener('input', recalc);
    var extras = document.querySelectorAll('#discount_type, #discount_value, #tax_rate');
    for (var i = 0; i < extras.length; i++) {
      extras[i].addEventListener('input', recalc);
      extras[i].addEventListener('change', recalc);
    }
    recalc();
  }

  function addItemRow() {
    var symbol = currency();
    var row = document.createElement('tr');
    row.className = 'inv-item';
    row.innerHTML =
      '<td class="inv-col-grip"><span class="inv-grip" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span><span></span></span></td>' +
      '<td class="inv-col-desc" data-label="Item"><input type="text" name="items[description][]" placeholder="Type item description (optional)"></td>' +
      '<td class="inv-col-qty" data-label="Quantity"><input type="number" name="items[quantity][]" min="0" step="0.01" value="1"></td>' +
      '<td class="inv-col-price" data-label="Price"><span class="inv-price"><em>' + symbol + '</em><input type="number" name="items[unit_price][]" min="0" step="0.01" value="0"></span></td>' +
      '<td class="num inv-col-amt" data-label="Amount"><span class="line-total">' + moneyText(0) + '</span></td>' +
      '<td class="inv-col-remove"><button type="button" class="inv-remove remove-item" title="Remove item">&times;</button></td>';
    tbody.appendChild(row);
  }

  function recalc() {
    if (!tbody) return;
    var rows = tbody.querySelectorAll('tr');
    var subtotal = 0;
    for (var i = 0; i < rows.length; i++) {
      var qtyInput = rows[i].querySelector('input[name="items[quantity][]"]');
      var priceInput = rows[i].querySelector('input[name="items[unit_price][]"]');
      var lineEl = rows[i].querySelector('.line-total');
      if (!qtyInput || !priceInput || !lineEl) continue;
      var qty = parseFloat(qtyInput.value || '0');
      var price = parseFloat(priceInput.value || '0');
      var line = Math.round(qty * price * 100) / 100;
      subtotal += line;
      lineEl.textContent = moneyText(line);
    }
    var typeEl = document.getElementById('discount_type');
    var valueEl = document.getElementById('discount_value');
    var taxEl = document.getElementById('tax_rate');
    var addTax = document.getElementById('add_tax');
    var type = typeEl ? typeEl.value : 'fixed';
    var discountValue = parseFloat(valueEl ? valueEl.value : '0') || 0;
    var taxRate = (addTax && addTax.value === '1' && taxEl) ? (parseFloat(taxEl.value || '0') || 0) : 0;
    var discount = type === 'percent' ? subtotal * (discountValue / 100) : discountValue;
    if (discount > subtotal) discount = subtotal;
    discount = Math.round(discount * 100) / 100;
    var tax = Math.round((subtotal - discount) * (taxRate / 100) * 100) / 100;
    var total = Math.round((subtotal - discount + tax) * 100) / 100;
    setMoney('live-subtotal', subtotal);
    setMoney('live-tax', tax);
    setMoney('live-total', total);
    var discEl = document.getElementById('live-discount');
    if (discEl) discEl.textContent = discount.toFixed(2);
  }

  function setMoney(id, amount) {
    var el = document.getElementById(id);
    if (el) el.textContent = moneyText(amount);
  }

  var addTaxBtn = document.getElementById('add-tax-btn');
  if (addTaxBtn) {
    addTaxBtn.addEventListener('click', function () {
      var taxRow = document.getElementById('tax-row');
      var taxAmountRow = document.getElementById('tax-amount-row');
      var addTax = document.getElementById('add_tax');
      if (taxRow) taxRow.hidden = false;
      if (taxAmountRow) taxAmountRow.hidden = false;
      if (addTax) addTax.value = '1';
      addTaxBtn.hidden = true;
      recalc();
    });
  }

  var insertNote = document.getElementById('insert-note');
  if (insertNote) {
    insertNote.addEventListener('click', function () {
      var notes = document.getElementById('notes');
      var snippet = insertNote.getAttribute('data-note') || '';
      if (notes && snippet) {
        notes.value = notes.value ? notes.value : snippet;
        notes.focus();
      }
    });
  }

  var trigger = document.getElementById('customer-trigger');
  var panel = document.getElementById('customer-panel');
  var search = document.getElementById('customer-search');
  var customerId = document.getElementById('customer_id');
  var customerMode = document.getElementById('customer_mode');
  var newFields = document.getElementById('new-customer-fields');
  var selectedFields = document.getElementById('selected-customer-fields');
  var chooseNew = document.getElementById('choose-new-customer');
  var customerLabel = document.getElementById('customer-label');
  var customerSub = document.getElementById('customer-sub');
  var viewName = document.getElementById('view-customer-name');
  var viewCompany = document.getElementById('view-customer-company');
  var viewEmail = document.getElementById('view-customer-email');
  var viewPhone = document.getElementById('view-customer-phone');

  function customerDetail(value) {
    var text = String(value || '').trim();
    return text === '' ? '—' : text;
  }

  function showNewCustomerFields() {
    if (customerMode) customerMode.value = 'new';
    if (customerId) customerId.value = '';
    if (selectedFields) selectedFields.hidden = true;
    if (newFields) newFields.hidden = false;
  }

  function fillSelectedCustomer(name, company, email, phone) {
    if (viewName) viewName.textContent = customerDetail(name);
    if (viewCompany) viewCompany.textContent = customerDetail(company);
    if (viewEmail) viewEmail.textContent = customerDetail(email);
    if (viewPhone) viewPhone.textContent = customerDetail(typeof formatPhone === 'function' ? formatPhone(phone) : phone);
    if (customerMode) customerMode.value = 'existing';
    if (selectedFields) selectedFields.hidden = false;
    if (newFields) newFields.hidden = true;
  }

  function closePicker() {
    if (panel) panel.hidden = true;
  }

  if (trigger && panel) {
    trigger.addEventListener('click', function (event) {
      event.preventDefault();
      panel.hidden = !panel.hidden;
      if (!panel.hidden && search) {
        search.focus();
      }
    });
    document.addEventListener('click', function (event) {
      var card = document.getElementById('customer-card');
      if (card && !card.contains(event.target)) {
        closePicker();
      }
    });
  }

  if (search) {
    search.addEventListener('input', function () {
      var q = search.value.toLowerCase();
      var items = document.querySelectorAll('.inv-picker-item');
      for (var i = 0; i < items.length; i++) {
        var hay = (items[i].getAttribute('data-name') + ' ' + items[i].getAttribute('data-company') + ' ' + items[i].getAttribute('data-email')).toLowerCase();
        items[i].style.display = hay.indexOf(q) === -1 ? 'none' : '';
      }
    });
  }

  var pickerItems = document.querySelectorAll('.inv-picker-item');
  for (var c = 0; c < pickerItems.length; c++) {
    pickerItems[c].addEventListener('click', function () {
      var name = this.getAttribute('data-name') || '';
      var company = this.getAttribute('data-company') || '';
      var email = this.getAttribute('data-email') || '';
      var phone = this.getAttribute('data-phone') || '';
      if (customerId) customerId.value = this.getAttribute('data-id');
      if (customerLabel) customerLabel.textContent = name;
      if (customerSub) customerSub.textContent = company || email || 'Existing customer';
      fillSelectedCustomer(name, company, email, phone);
      closePicker();
    });
  }

  if (chooseNew) {
    chooseNew.addEventListener('click', function () {
      if (customerLabel) customerLabel.textContent = 'New Customer';
      if (customerSub) customerSub.textContent = 'Enter customer details';
      showNewCustomerFields();
      closePicker();
      var nameField = document.getElementById('new_name');
      if (nameField) nameField.focus();
    });
  }

  if (customerMode && customerMode.value === 'new') {
    showNewCustomerFields();
  } else if (selectedFields && customerId && customerId.value) {
    selectedFields.hidden = false;
    if (newFields) newFields.hidden = true;
  }

  function phoneDigits(value) {
    var digits = String(value || '').replace(/\D/g, '');
    if (digits.length === 11 && digits.charAt(0) === '1') {
      digits = digits.slice(1);
    }
    return digits.slice(0, 10);
  }

  function formatPhone(value) {
    var digits = phoneDigits(value);
    var len = digits.length;
    if (!len) {
      return '';
    }
    if (len < 4) {
      return '(' + digits;
    }
    if (len < 7) {
      return '(' + digits.slice(0, 3) + ') ' + digits.slice(3);
    }
    return '(' + digits.slice(0, 3) + ') ' + digits.slice(3, 6) + '-' + digits.slice(6);
  }

  function setPhoneCaret(input, digitCount) {
    var value = input.value;
    var seen = 0;
    var index = value.length;
    var i;
    if (digitCount <= 0) {
      input.setSelectionRange(value.charAt(0) === '(' ? 1 : 0, value.charAt(0) === '(' ? 1 : 0);
      return;
    }
    for (i = 0; i < value.length; i++) {
      if (/\d/.test(value.charAt(i))) {
        seen++;
        if (seen >= digitCount) {
          index = i + 1;
          break;
        }
      }
    }
    input.setSelectionRange(index, index);
  }

  var phoneInputs = document.querySelectorAll('input[data-phone]');
  for (var p = 0; p < phoneInputs.length; p++) {
    (function (input) {
      if (input.value) {
        input.value = formatPhone(input.value);
      }
      input.addEventListener('input', function () {
        var cursor = input.selectionStart || 0;
        var digitsBefore = phoneDigits(input.value.slice(0, cursor)).length;
        input.value = formatPhone(input.value);
        setPhoneCaret(input, digitsBefore);
      });
    })(phoneInputs[p]);
  }

  var menuToggle = document.getElementById('menu-toggle');
  var navBackdrop = document.getElementById('nav-backdrop');
  function setNavOpen(open) {
    document.body.classList.toggle('nav-open', open);
    if (menuToggle) {
      menuToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      menuToggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    }
  }
  if (menuToggle) {
    menuToggle.addEventListener('click', function () {
      setNavOpen(!document.body.classList.contains('nav-open'));
    });
  }
  if (navBackdrop) {
    navBackdrop.addEventListener('click', function () {
      setNavOpen(false);
    });
  }
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      setNavOpen(false);
    }
  });
  var navLinks = document.querySelectorAll('.sidebar .nav-link');
  for (var n = 0; n < navLinks.length; n++) {
    navLinks[n].addEventListener('click', function () {
      setNavOpen(false);
    });
  }
})();
