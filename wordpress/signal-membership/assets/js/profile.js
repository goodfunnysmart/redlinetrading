(function () {
  'use strict';

  var cfg = window.SIG || {};
  var root = document.getElementById('sig-profile');
  if (!root) {
    return;
  }

  var rest = cfg.rest || '';
  var nonce = cfg.nonce || '';

  function api(path, opts) {
    opts = opts || {};
    var headers = {
      Accept: 'application/json',
      'X-WP-Nonce': nonce
    };
    if (opts.body && typeof opts.body === 'string') {
      headers['Content-Type'] = 'application/json';
    }
    return fetch(rest + path, {
      method: opts.method || 'GET',
      credentials: 'same-origin',
      headers: headers,
      body: opts.body || null
    }).then(function (res) {
      return res.json().catch(function () {
        return {};
      }).then(function (body) {
        if (!res.ok) {
          var msg = (body && (body.message || body.error)) || ('HTTP ' + res.status);
          throw new Error(msg);
        }
        return body;
      });
    });
  }

  function setMsg(sel, text, show) {
    var el = root.querySelector(sel);
    if (!el) {
      return;
    }
    el.textContent = text || '';
    el.hidden = !show;
  }

  function fill(data) {
    var name = root.querySelector('[name="display_name"]');
    var email = root.querySelector('[name="email"]');
    var phone = root.querySelector('[name="phone"]');
    var capital = root.querySelector('[name="capital"]');
    if (name) {
      name.value = data.display_name || '';
    }
    if (email) {
      email.value = data.email || '';
    }
    if (phone) {
      phone.value = data.phone || '';
    }
    if (capital) {
      var cap = Number(data.capital);
      capital.value = isFinite(cap) && cap > 0 ? cap : 100000;
    }
  }

  function load() {
    setMsg('[data-error]', '', false);
    setMsg('[data-ok]', '', false);
    api('me/profile').then(function (data) {
      fill(data || {});
    }).catch(function (e) {
      setMsg('[data-error]', e.message || 'Could not load profile.', true);
    });
  }

  var form = root.querySelector('[data-profile-form]');
  if (form) {
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      setMsg('[data-error]', '', false);
      setMsg('[data-ok]', '', false);
      var payload = {
        display_name: (form.querySelector('[name="display_name"]') || {}).value || '',
        email: (form.querySelector('[name="email"]') || {}).value || '',
        phone: (form.querySelector('[name="phone"]') || {}).value || '',
        capital: Number((form.querySelector('[name="capital"]') || {}).value || 0)
      };
      api('me/profile', {
        method: 'POST',
        body: JSON.stringify(payload)
      }).then(function (data) {
        fill(data || payload);
        setMsg('[data-ok]', 'Profile saved.', true);
      }).catch(function (e) {
        setMsg('[data-error]', e.message || 'Could not save profile.', true);
      });
    });
  }

  load();
})();
