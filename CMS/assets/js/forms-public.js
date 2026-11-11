/**
 * 365CMS – Formulare: Client-Prüfung, Fehlerübersicht, Sendezustand.
 *
 * Die Regeln entsprechen FormValidator (Server); maßgeblich bleibt die Server-Prüfung.
 * Texte kommen aus `data-messages` am Formular (Sprache der Seite).
 */
(function () {
  'use strict';

  function format(message, value) {
    return String(message || '').replace(/%(\d\$)?[sd]/, value === undefined ? '' : String(value));
  }

  function formatDate(iso, lang) {
    var match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
    return match && lang !== 'en' ? match[3] + '.' + match[2] + '.' + match[1] : iso;
  }

  function compilePattern(body) {
    if (!body) {
      return null;
    }
    var flags = 'u';
    var inline = /^\(\?([imsx]+)\)/.exec(body);
    if (inline) {
      body = body.slice(inline[0].length);
      if (inline[1].indexOf('i') !== -1) {
        flags += 'i';
      }
      if (inline[1].indexOf('s') !== -1) {
        flags += 's';
      }
      if (inline[1].indexOf('m') !== -1) {
        flags += 'm';
      }
    }
    try {
      return new RegExp(body, flags);
    } catch (e) {
      return null;
    }
  }

  function textLength(value) {
    return Array.from ? Array.from(value).length : value.length;
  }

  function validateField(wrapper, messages, lang) {
    var type = wrapper.getAttribute('data-type');
    var required = wrapper.getAttribute('data-required') === '1';

    if (type === 'radio' || (type === 'checkbox' && wrapper.querySelector('fieldset'))) {
      var checked = wrapper.querySelectorAll('input:checked').length > 0;
      return required && !checked ? messages.required_choice : '';
    }
    if (type === 'checkbox' || type === 'consent') {
      var box = wrapper.querySelector('input[type="checkbox"]');
      if (required && box && !box.checked) {
        return type === 'consent' ? messages.consent : messages.required_check;
      }
      return '';
    }
    if (type === 'file') {
      var fileInput = wrapper.querySelector('input[type="file"]');
      var files = fileInput && fileInput.files ? Array.prototype.slice.call(fileInput.files) : [];
      if (files.length === 0) {
        return required ? messages.required_file : '';
      }
      var maxFiles = parseInt(wrapper.getAttribute('data-max-files') || '1', 10);
      if (files.length > maxFiles) {
        return format(messages.file_count, maxFiles);
      }
      var accept = (wrapper.getAttribute('data-accept') || '').split(',').filter(Boolean);
      var maxKb = parseInt(wrapper.getAttribute('data-max-kb') || '0', 10);
      for (var i = 0; i < files.length; i++) {
        var ext = (files[i].name.split('.').pop() || '').toLowerCase();
        if (accept.length && accept.indexOf(ext) === -1) {
          return format(messages.file_type, accept.join(', '));
        }
        if (maxKb > 0 && files[i].size > maxKb * 1024) {
          return format(messages.file_size, wrapper.getAttribute('data-max-size') || maxKb + ' KB');
        }
      }
      return '';
    }

    var input = wrapper.querySelector('.cms-form__input');
    if (!input) {
      return '';
    }
    var value = String(input.value || '').trim();
    if (value === '') {
      if (type === 'number' && input.validity && input.validity.badInput) {
        return messages.number;
      }
      return required ? messages.required : '';
    }

    if (type === 'email' && (!/^[^\s@<>"'(),;:\\]+@[^\s@<>"'(),;:\\]+\.[^\s@<>"'(),;:\\]{2,}$/.test(value))) {
      return messages.email;
    }
    if (type === 'url' && !/^https?:\/\/[^\s/$.?#].[^\s]*$/i.test(value)) {
      return messages.url;
    }
    if (type === 'tel' && (!/^\+?[0-9 ()\/.\-]{3,40}$/.test(value) || !/[0-9]/.test(value))) {
      return messages.tel;
    }
    if (type === 'number') {
      var number = Number(value.replace(',', '.'));
      if (!isFinite(number)) {
        return messages.number;
      }
      var min = input.getAttribute('min');
      var max = input.getAttribute('max');
      var step = input.getAttribute('step');
      if (min !== null && number < Number(min)) {
        return format(messages.number_min, min);
      }
      if (max !== null && number > Number(max)) {
        return format(messages.number_max, max);
      }
      if (step && step !== 'any' && Number(step) > 0) {
        var ratio = (number - (min !== null ? Number(min) : 0)) / Number(step);
        if (Math.abs(ratio - Math.round(ratio)) > 1e-7) {
          return messages.number_step;
        }
      }
    }
    if (type === 'date') {
      if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
        return messages.date;
      }
      var minDate = input.getAttribute('min');
      var maxDate = input.getAttribute('max');
      if (minDate && value < minDate) {
        return format(messages.date_min, formatDate(minDate, lang));
      }
      if (maxDate && value > maxDate) {
        return format(messages.date_max, formatDate(maxDate, lang));
      }
    }

    var minLength = parseInt(input.getAttribute('minlength') || '0', 10);
    var maxLength = parseInt(input.getAttribute('maxlength') || '0', 10);
    if (minLength > 0 && textLength(value) < minLength) {
      return format(messages.min_length, minLength);
    }
    if (maxLength > 0 && textLength(value) > maxLength) {
      return format(messages.max_length, maxLength);
    }

    var pattern = compilePattern(input.getAttribute('data-pattern'));
    if (pattern && !pattern.test(value)) {
      return wrapper.getAttribute('data-pattern-message') || messages.pattern;
    }

    return '';
  }

  function setError(wrapper, message) {
    var error = wrapper.querySelector('.cms-form__error');
    var controls = wrapper.querySelectorAll('input, select, textarea, fieldset');
    wrapper.classList.toggle('has-error', message !== '');
    if (error) {
      error.textContent = message;
      error.hidden = message === '';
    }
    for (var i = 0; i < controls.length; i++) {
      if (controls[i].type === 'radio' || (controls[i].type === 'checkbox' && wrapper.querySelector('fieldset'))) {
        continue;
      }
      if (message !== '') {
        controls[i].setAttribute('aria-invalid', 'true');
      } else {
        controls[i].removeAttribute('aria-invalid');
      }
    }
  }

  function fieldLabel(wrapper) {
    var label = wrapper.querySelector('legend, .cms-form__label, .cms-form__check-label');
    return label ? label.textContent.replace(/\*\s*$/, '').trim() : wrapper.getAttribute('data-field');
  }

  function focusTarget(wrapper) {
    return wrapper.querySelector('input:not([type="hidden"]), select, textarea');
  }

  function showSummary(form, items, messages) {
    var box = form.querySelector('[data-cms-form-client-summary]');
    if (!box) {
      box = document.createElement('div');
      box.className = 'cms-form__notice cms-form__notice--error';
      box.setAttribute('role', 'alert');
      box.setAttribute('tabindex', '-1');
      box.setAttribute('data-cms-form-client-summary', '');
      form.insertBefore(box, form.firstChild);
    }
    box.innerHTML = '';
    var intro = document.createElement('p');
    intro.textContent = messages.summary || '';
    box.appendChild(intro);
    var list = document.createElement('ul');
    items.forEach(function (item) {
      var li = document.createElement('li');
      var link = document.createElement('a');
      var target = focusTarget(item.wrapper);
      link.href = target && target.id ? '#' + target.id : '#';
      link.textContent = item.label + ': ' + item.message;
      link.addEventListener('click', function (event) {
        if (target) {
          event.preventDefault();
          target.focus();
        }
      });
      li.appendChild(link);
      list.appendChild(li);
    });
    box.appendChild(list);
    box.focus();
  }

  function clearSummary(form) {
    var box = form.querySelector('[data-cms-form-client-summary]');
    if (box) {
      box.parentNode.removeChild(box);
    }
  }

  function setBusy(form, busy) {
    var button = form.querySelector('.cms-form__submit');
    if (!button) {
      return;
    }
    if (busy) {
      if (!button.hasAttribute('data-idle-label')) {
        button.setAttribute('data-idle-label', button.textContent);
      }
      button.textContent = button.getAttribute('data-busy-label') || button.textContent;
      button.setAttribute('aria-busy', 'true');
      button.disabled = true;
    } else {
      if (button.hasAttribute('data-idle-label')) {
        button.textContent = button.getAttribute('data-idle-label');
      }
      button.removeAttribute('aria-busy');
      button.disabled = false;
    }
  }

  function init(form) {
    if (form.getAttribute('data-cms-form-ready') === '1') {
      return;
    }
    form.setAttribute('data-cms-form-ready', '1');

    var messages = {};
    try {
      messages = JSON.parse(form.getAttribute('data-messages') || '{}');
    } catch (e) {
      messages = {};
    }
    var root = form.closest('[data-cms-form]');
    var lang = (root && root.getAttribute('lang')) || document.documentElement.lang || 'de';
    var wrappers = Array.prototype.slice.call(form.querySelectorAll('.cms-form__field[data-field]'));

    wrappers.forEach(function (wrapper) {
      var revalidate = function () {
        if (wrapper.classList.contains('has-error')) {
          setError(wrapper, validateField(wrapper, messages, lang));
        }
      };
      wrapper.addEventListener('input', revalidate);
      wrapper.addEventListener('change', revalidate);
    });

    form.addEventListener('submit', function (event) {
      var problems = [];
      wrappers.forEach(function (wrapper) {
        var message = validateField(wrapper, messages, lang);
        setError(wrapper, message);
        if (message) {
          problems.push({ wrapper: wrapper, message: message, label: fieldLabel(wrapper) });
        }
      });

      if (problems.length > 0) {
        event.preventDefault();
        showSummary(form, problems, messages);
        return;
      }

      clearSummary(form);
      setBusy(form, true);
    });
  }

  function boot() {
    var forms = document.querySelectorAll('[data-cms-form] form.cms-form__form');
    for (var i = 0; i < forms.length; i++) {
      init(forms[i]);
    }

    var notice = document.querySelector('[data-cms-form] [data-cms-form-focus]');
    if (notice) {
      notice.focus({ preventScroll: true });
      notice.scrollIntoView({ block: 'center' });
    }
  }

  // Zurück-Navigation (bfcache): Sendezustand zurücksetzen.
  window.addEventListener('pageshow', function (event) {
    if (!event.persisted) {
      return;
    }
    var forms = document.querySelectorAll('[data-cms-form] form.cms-form__form');
    for (var i = 0; i < forms.length; i++) {
      setBusy(forms[i], false);
    }
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
