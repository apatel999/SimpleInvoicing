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
      '<td class="inv-col-desc" data-label="Description"><input type="text" name="items[description][]" placeholder="Item description"></td>' +
      '<td class="num inv-col-qty" data-label="Qty"><input type="number" name="items[quantity][]" min="0" step="0.01" value="1"></td>' +
      '<td class="num inv-col-price" data-label="Price"><span class="inv-price"><em>' + symbol + '</em><input type="number" name="items[unit_price][]" min="0" step="0.01" value="0"></span></td>' +
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
    setMoney('live-balance', total);
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

  var customerModal = document.getElementById('customer-modal');
  var search = document.getElementById('customer-search');
  var customerId = document.getElementById('customer_id');
  var customerMode = document.getElementById('customer_mode');
  var selectedFields = document.getElementById('selected-customer-fields');
  var customerEmpty = document.getElementById('customer-empty');
  var chooseNew = document.getElementById('choose-new-customer');
  var viewName = document.getElementById('view-customer-name');
  var viewCompany = document.getElementById('view-customer-company');
  var viewEmail = document.getElementById('view-customer-email');
  var viewPhone = document.getElementById('view-customer-phone');
  var searchView = document.getElementById('customer-modal-search-view');
  var formView = document.getElementById('customer-modal-form-view');
  var modalTitle = document.getElementById('customer-modal-title');
  var addFromSearch = document.getElementById('customer-add-from-search');
  var formError = document.getElementById('customer-form-error');
  var nameField = document.getElementById('new_name');
  var companyField = document.getElementById('new_company');
  var emailField = document.getElementById('new_email');
  var phoneField = document.getElementById('new_phone');
  var modalSnapshot = { id: '', mode: 'existing' };
  var keepCustomerChoice = false;

  function fillPartyLine(el, value) {
    if (!el) return;
    var text = String(value || '').trim();
    el.textContent = text;
    el.hidden = text === '';
  }

  function fillSelectedCustomer(name, company, email, phone) {
    fillPartyLine(viewName, name);
    fillPartyLine(viewCompany, company);
    fillPartyLine(viewEmail, email);
    fillPartyLine(viewPhone, typeof formatPhone === 'function' ? formatPhone(phone) : phone);
    if (selectedFields) selectedFields.hidden = false;
    if (customerEmpty) customerEmpty.hidden = true;
  }

  function fieldValue(el) {
    return el ? String(el.value || '').trim() : '';
  }

  function setFormFields(name, company, email, phone) {
    if (nameField) nameField.value = name || '';
    if (companyField) companyField.value = company || '';
    if (emailField) emailField.value = email || '';
    if (phoneField) phoneField.value = typeof formatPhone === 'function' ? formatPhone(phone || '') : (phone || '');
  }

  function showSearchView() {
    if (modalTitle) modalTitle.textContent = 'Select customer';
    if (searchView) searchView.hidden = false;
    if (formView) formView.hidden = true;
    filterCustomers();
    if (search) {
      search.focus();
      search.select();
    }
  }

  function showFormView(isEdit, presetName) {
    if (modalTitle) modalTitle.textContent = isEdit ? 'Edit customer' : 'New customer';
    if (searchView) searchView.hidden = true;
    if (formView) formView.hidden = false;
    var apply = document.getElementById('customer-form-apply');
    if (apply) apply.textContent = isEdit ? 'Update' : 'Use customer';
    if (formError) formError.hidden = true;
    if (presetName && nameField && !nameField.value) nameField.value = presetName;
    if (nameField) nameField.focus();
  }

  function openCustomerModal(view) {
    if (!customerModal) return;
    modalSnapshot.id = customerId ? customerId.value : '';
    modalSnapshot.mode = customerMode ? customerMode.value : 'existing';
    keepCustomerChoice = false;
    customerModal.hidden = false;
    document.body.classList.add('modal-open');
    if (view === 'form') {
      var hasId = customerId && customerId.value && customerId.value !== '0';
      showFormView(!!hasId);
    } else {
      if (search) search.value = '';
      showSearchView();
    }
  }

  function closeCustomerModal() {
    if (!keepCustomerChoice) {
      if (customerId) customerId.value = modalSnapshot.id;
      if (customerMode) customerMode.value = modalSnapshot.mode;
    }
    keepCustomerChoice = false;
    if (customerModal) customerModal.hidden = true;
    if (!document.getElementById('confirm-modal') || document.getElementById('confirm-modal').hidden) {
      document.body.classList.remove('modal-open');
    }
  }

  function filterCustomers() {
    var q = search ? search.value.toLowerCase().trim() : '';
    var items = document.querySelectorAll('#customer-list .inv-picker-item');
    var visible = 0;
    for (var i = 0; i < items.length; i++) {
      var hay = (items[i].getAttribute('data-name') + ' ' + items[i].getAttribute('data-company') + ' ' + items[i].getAttribute('data-email')).toLowerCase();
      var show = q === '' || hay.indexOf(q) !== -1;
      items[i].style.display = show ? '' : 'none';
      if (show) visible++;
    }
    var empty = document.getElementById('customer-empty-list');
    if (empty) empty.hidden = visible > 0;
    if (addFromSearch) {
      if (q !== '') {
        addFromSearch.hidden = false;
        addFromSearch.textContent = 'Add “' + search.value.trim() + '” as a new customer';
      } else {
        addFromSearch.hidden = true;
      }
    }
    return visible;
  }

  if (search) {
    search.addEventListener('input', filterCustomers);
  }

  var pickerItems = document.querySelectorAll('#customer-list .inv-picker-item');
  for (var c = 0; c < pickerItems.length; c++) {
    pickerItems[c].addEventListener('click', function () {
      var name = this.getAttribute('data-name') || '';
      var company = this.getAttribute('data-company') || '';
      var email = this.getAttribute('data-email') || '';
      var phone = this.getAttribute('data-phone') || '';
      if (customerId) customerId.value = this.getAttribute('data-id');
      if (customerMode) customerMode.value = 'existing';
      setFormFields('', '', '', '');
      fillSelectedCustomer(name, company, email, phone);
      keepCustomerChoice = true;
      closeCustomerModal();
    });
  }

  function startNewCustomer(presetName) {
    if (customerMode) customerMode.value = 'new';
    if (customerId) customerId.value = '';
    setFormFields(presetName || '', '', '', '');
    showFormView(false, presetName || '');
  }

  if (chooseNew) {
    chooseNew.addEventListener('click', function () {
      startNewCustomer(search ? search.value.trim() : '');
    });
  }
  if (addFromSearch) {
    addFromSearch.addEventListener('click', function () {
      startNewCustomer(search ? search.value.trim() : '');
    });
  }

  var openSelect = document.getElementById('open-customer-modal');
  var customerChange = document.getElementById('customer-change');
  var customerEdit = document.getElementById('customer-edit');
  if (openSelect) openSelect.addEventListener('click', function () { openCustomerModal('search'); });
  if (customerChange) customerChange.addEventListener('click', function () { openCustomerModal('search'); });
  if (customerEdit) {
    customerEdit.addEventListener('click', function () {
      setFormFields(
        viewName ? viewName.textContent : '',
        viewCompany && !viewCompany.hidden ? viewCompany.textContent : '',
        viewEmail && !viewEmail.hidden ? viewEmail.textContent : '',
        viewPhone && !viewPhone.hidden ? viewPhone.textContent : ''
      );
      openCustomerModal('form');
    });
  }

  var modalCancel = document.getElementById('customer-modal-cancel');
  var formBack = document.getElementById('customer-form-back');
  var formApply = document.getElementById('customer-form-apply');
  if (modalCancel) modalCancel.addEventListener('click', closeCustomerModal);
  if (formBack) formBack.addEventListener('click', showSearchView);
  if (formApply) {
    formApply.addEventListener('click', function () {
      var name = fieldValue(nameField);
      if (name === '') {
        if (formError) {
          formError.textContent = 'Customer name is required.';
          formError.hidden = false;
        }
        if (nameField) nameField.focus();
        return;
      }
      if (customerId && customerId.value && customerId.value !== '0') {
        if (customerMode) customerMode.value = 'edit';
      } else if (customerMode) {
        customerMode.value = 'new';
      }
      fillSelectedCustomer(name, fieldValue(companyField), fieldValue(emailField), fieldValue(phoneField));
      keepCustomerChoice = true;
      closeCustomerModal();
    });
  }
  if (customerModal) {
    customerModal.addEventListener('click', function (event) {
      if (event.target === customerModal) closeCustomerModal();
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && customerModal && !customerModal.hidden) {
        closeCustomerModal();
      }
      if (event.key === 'Enter' && customerModal && !customerModal.hidden && event.target && event.target.tagName !== 'BUTTON' && event.target.tagName !== 'TEXTAREA') {
        event.preventDefault();
        if (formView && !formView.hidden && formApply) {
          formApply.click();
        }
      }
    });
    if (customerModal.getAttribute('data-open') === '1') {
      openCustomerModal(customerModal.getAttribute('data-open-form') === '1' ? 'form' : 'search');
    }
  }

  function phoneDigits(value) {
    var digits = String(value || '').replace(/\D/g, '');
    var max = digits.charAt(0) === '1' ? 11 : 10;
    return digits.slice(0, max);
  }

  function formatPhone(value) {
    var digits = phoneDigits(value);
    var len = digits.length;
    if (!len) {
      return '';
    }
    if (digits.charAt(0) === '1') {
      var out = '1';
      var rest = digits.slice(1);
      if (!rest) {
        return out;
      }
      out += '-' + rest.slice(0, 3);
      if (rest.length <= 3) {
        return out;
      }
      out += '-' + rest.slice(3, 6);
      if (rest.length <= 6) {
        return out;
      }
      return out + '-' + rest.slice(6, 10);
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
  var sidebarCollapse = document.getElementById('sidebar-collapse');
  function setNavCollapsed(collapsed) {
    document.body.classList.toggle('nav-collapsed', collapsed);
    try {
      localStorage.setItem('ih-nav-collapsed', collapsed ? '1' : '0');
    } catch (err) {}
    if (sidebarCollapse) {
      sidebarCollapse.setAttribute('aria-label', collapsed ? 'Expand menu' : 'Collapse menu');
      sidebarCollapse.title = collapsed ? 'Expand menu' : 'Collapse menu';
    }
  }
  if (sidebarCollapse) {
    setNavCollapsed(document.body.classList.contains('nav-collapsed'));
    sidebarCollapse.addEventListener('click', function () {
      setNavCollapsed(!document.body.classList.contains('nav-collapsed'));
    });
  }
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
