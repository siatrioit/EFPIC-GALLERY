(function () {
  var modal = document.getElementById('clientEmailComposeModal');
  if (!modal) return;

  var form = document.getElementById('admin-delivery-form');
  var subjectInput = document.getElementById('clientEmailComposeSubject');
  var groupLabel = document.getElementById('clientEmailComposeGroupLabel');
  var groupHidden = document.getElementById('clientEmailComposeGroupHidden');
  var subjectHidden = document.getElementById('clientEmailComposeSubjectHidden');
  var bodyHidden = document.getElementById('clientEmailComposeBodyHidden');
  var errorEl = document.getElementById('clientEmailComposeError');
  var editorWrap = modal.querySelector('[data-rich-editor-wrap]');
  var editorApi = null;
  var activeGroup = '';
  var previewUrl = window.EFPIC_CLIENT_EMAIL_PREVIEW_URL || '';

  function showError(msg) {
    if (!errorEl) return;
    if (!msg) {
      errorEl.hidden = true;
      errorEl.textContent = '';
      return;
    }
    errorEl.hidden = false;
    errorEl.textContent = msg;
  }

  function closeModal() {
    modal.hidden = true;
    showError('');
    document.body.style.overflow = '';
  }

  function templateIdForGroup(group) {
    var select = document.querySelector('select[name="client_msg_' + group + '_email"]');
    return select ? select.value || '' : '';
  }

  function passwordOverrides() {
    var galleryPassword = document.querySelector('input[name="gallery_password"]');
    var portalPassword = document.querySelector('input[name="client_password"]');
    var params = new URLSearchParams();
    if (galleryPassword && galleryPassword.value.trim() !== '') {
      params.set('gallery_password', galleryPassword.value.trim());
    }
    if (portalPassword && portalPassword.value.trim() !== '') {
      params.set('portal_password', portalPassword.value.trim());
    }
    return params;
  }

  function loadDraft(group) {
    if (!previewUrl) return Promise.reject(new Error('Nav pieejams priekšskatījums'));
    var params = passwordOverrides();
    params.set('group', group);
    var templateId = templateIdForGroup(group);
    if (templateId) params.set('template_id', templateId);
    return fetch(previewUrl + '?' + params.toString(), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
      .then(function (res) {
        return res.json().then(function (data) {
          if (!res.ok || !data || !data.ok) {
            throw new Error((data && data.error) || 'Neizdevās ielādēt sagatavi');
          }
          return data;
        });
      });
  }

  function openModal(group, groupTitle) {
    activeGroup = group;
    if (groupLabel) {
      groupLabel.textContent = groupTitle || group;
    }
    showError('');
    modal.hidden = false;
    document.body.style.overflow = 'hidden';

    if (!editorApi && editorWrap) {
      editorApi = editorWrap._efpicRichEditor
        || (window.efpicInitRichTextEditor && window.efpicInitRichTextEditor(editorWrap));
    }
    if (subjectInput) subjectInput.value = 'Ielādē…';
    if (editorApi) editorApi.setHtml('<p class="muted">Ielādē sagatavi…</p>');

    loadDraft(group)
      .then(function (data) {
        if (subjectInput) subjectInput.value = data.subject || '';
        if (editorApi) editorApi.setHtml(data.body_html || '');
      })
      .catch(function (err) {
        showError(err && err.message ? err.message : 'Neizdevās ielādēt sagatavi');
        if (subjectInput) subjectInput.value = '';
        if (editorApi) editorApi.setHtml('');
      });
  }

  document.querySelectorAll('[data-client-email-compose]').forEach(function (btn) {
    btn.addEventListener('click', function (evt) {
      evt.preventDefault();
      var group = btn.getAttribute('data-client-email-compose') || '';
      var title = btn.getAttribute('data-client-email-group-label') || group;
      if (!group) return;
      openModal(group, title);
    });
  });

  document.querySelectorAll('[data-client-email-compose-close]').forEach(function (btn) {
    btn.addEventListener('click', function (evt) {
      evt.preventDefault();
      closeModal();
    });
  });

  modal.addEventListener('click', function (evt) {
    if (evt.target === modal) closeModal();
  });

  document.querySelectorAll('[data-client-email-compose-send]').forEach(function (btn) {
    btn.addEventListener('click', function (evt) {
      evt.preventDefault();
      if (!form || !activeGroup) return;
      if (!subjectInput || subjectInput.value.trim() === '') {
        showError('Ievadi e-pasta tematu.');
        return;
      }
      if (!editorApi || editorApi.getHtml().trim() === '') {
        showError('E-pasta teksts nevar būt tukšs.');
        return;
      }
      if (groupHidden) groupHidden.value = activeGroup;
      if (subjectHidden) subjectHidden.value = subjectInput.value.trim();
      if (bodyHidden) bodyHidden.value = editorApi.getHtml();
      closeModal();
      if (typeof form.submit === 'function') {
        form.submit();
      }
    });
  });
})();
