(function () {
  function initClientEmailCompose() {
    var workspace = document.getElementById('clientEmailComposeWorkspace');
    if (!workspace) return;

    var form = document.getElementById('admin-delivery-form');
    var subjectInput = document.getElementById('clientEmailComposeSubject');
    var groupLabel = document.getElementById('clientEmailComposeGroupLabel');
    var groupHidden = document.getElementById('clientEmailComposeGroupHidden');
    var subjectHidden = document.getElementById('clientEmailComposeSubjectHidden');
    var bodyHidden = document.getElementById('clientEmailComposeBodyHidden');
    var errorEl = document.getElementById('clientEmailComposeError');
    var editorWrap = workspace.querySelector('[data-rich-editor-wrap]');
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

    function clearComposeTarget() {
      document.querySelectorAll('.admin-client-msg-group.is-compose-target').forEach(function (card) {
        card.classList.remove('is-compose-target');
      });
    }

    function closeWorkspace() {
      workspace.hidden = true;
      clearComposeTarget();
      showError('');
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

    function buildPreviewRequestUrl(group) {
      var params = passwordOverrides();
      params.set('group', group);
      var templateId = templateIdForGroup(group);
      if (templateId) params.set('template_id', templateId);
      var sep = previewUrl.indexOf('?') >= 0 ? '&' : '?';
      return previewUrl + sep + params.toString();
    }

    function loadDraft(group) {
      if (!previewUrl) return Promise.reject(new Error('Nav pieejams priekšskatījums'));
      return fetch(buildPreviewRequestUrl(group), {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      }).then(function (res) {
        return res.text().then(function (text) {
          var data = null;
          try {
            data = text ? JSON.parse(text) : null;
          } catch (e) {
            throw new Error('Serveris atgrieza nederīgu atbildi (nav JSON).');
          }
          if (!res.ok || !data || !data.ok) {
            throw new Error((data && data.error) || 'Neizdevās ielādēt sagatavi');
          }
          return data;
        });
      });
    }

    function ensureEditor() {
      if (!editorWrap) return null;
      if (!editorApi) {
        editorApi = editorWrap._efpicRichEditor
          || (window.efpicInitRichTextEditor && window.efpicInitRichTextEditor(editorWrap));
      }
      return editorApi;
    }

    function openWorkspace(group, groupTitle) {
      activeGroup = group;
      clearComposeTarget();
      var trigger = document.querySelector('[data-client-email-compose="' + group + '"]');
      if (trigger) {
        var card = trigger.closest('.admin-client-msg-group');
        if (card) card.classList.add('is-compose-target');
      }
      if (groupLabel) {
        groupLabel.textContent = groupTitle ? 'Grupa: ' + groupTitle : '';
      }
      showError('');
      workspace.hidden = false;
      if (typeof window.efpicActivateAdminTab === 'function') {
        window.efpicActivateAdminTab('admin-tab-messages', true);
      }
      workspace.scrollIntoView({ behavior: 'smooth', block: 'start' });

      var api = ensureEditor();
      if (subjectInput) subjectInput.value = 'Ielādē…';
      if (api) api.setHtml('<p class="muted">Ielādē sagatavi…</p>');

      loadDraft(group)
        .then(function (data) {
          if (subjectInput) subjectInput.value = data.subject || '';
          var editor = ensureEditor();
          if (editor) editor.setHtml(data.body_html || '');
        })
        .catch(function (err) {
          showError(err && err.message ? err.message : 'Neizdevās ielādēt sagatavi');
          if (subjectInput) subjectInput.value = '';
          var editor = ensureEditor();
          if (editor) editor.setHtml('');
        });
    }

    document.querySelectorAll('[data-client-email-compose]').forEach(function (btn) {
      btn.addEventListener('click', function (evt) {
        evt.preventDefault();
        var group = btn.getAttribute('data-client-email-compose') || '';
        var title = btn.getAttribute('data-client-email-group-label') || group;
        if (!group) return;
        openWorkspace(group, title);
      });
    });

    document.querySelectorAll('[data-client-email-compose-close]').forEach(function (btn) {
      btn.addEventListener('click', function (evt) {
        evt.preventDefault();
        closeWorkspace();
      });
    });

    document.querySelectorAll('[data-client-email-compose-send]').forEach(function (btn) {
      btn.addEventListener('click', function (evt) {
        evt.preventDefault();
        if (!form || !activeGroup) return;
        var editor = ensureEditor();
        if (!subjectInput || subjectInput.value.trim() === '') {
          showError('Ievadi e-pasta tematu.');
          return;
        }
        if (!editor || editor.getHtml().trim() === '') {
          showError('E-pasta teksts nevar būt tukšs.');
          return;
        }
        if (groupHidden) groupHidden.value = activeGroup;
        if (subjectHidden) subjectHidden.value = subjectInput.value.trim();
        if (bodyHidden) bodyHidden.value = editor.getHtml();
        closeWorkspace();
        if (typeof form.submit === 'function') {
          form.submit();
        }
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initClientEmailCompose);
  } else {
    initClientEmailCompose();
  }
})();
