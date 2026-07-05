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
    var previewFrame = document.getElementById('clientEmailComposePreview');
    var editorWrap = workspace.querySelector('[data-rich-editor-wrap]');
    var editorApi = null;
    var activeGroup = '';
    var previewUrl = window.EFPIC_CLIENT_EMAIL_PREVIEW_URL || '';
    var previewTimer = null;

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

    function buildPreviewPostUrl() {
      if (!previewUrl) return '';
      try {
        var url = new URL(previewUrl, window.location.href);
        url.searchParams.delete('poll');
        url.searchParams.delete('group');
        url.searchParams.delete('template_id');
        url.searchParams.delete('gallery_password');
        url.searchParams.delete('portal_password');
        return url.pathname + '?' + url.searchParams.toString();
      } catch (e) {
        return previewUrl.split('&poll=')[0].split('?poll=')[0];
      }
    }

    function setPreviewHtml(html) {
      if (!previewFrame) return;
      var doc = previewFrame.contentDocument || (previewFrame.contentWindow && previewFrame.contentWindow.document);
      if (!doc) return;
      doc.open();
      doc.write(html || '');
      doc.close();
    }

    function schedulePreviewUpdate() {
      if (previewTimer) window.clearTimeout(previewTimer);
      previewTimer = window.setTimeout(updatePreview, 350);
    }

    function updatePreview() {
      var postUrl = buildPreviewPostUrl();
      var editor = ensureEditor();
      if (!postUrl || !editor) return;

      var body = new FormData();
      body.append('poll', 'client_email_preview');
      body.append('content_html', editor.getHtml());
      body.append('subject', subjectInput ? subjectInput.value.trim() : '');

      fetch(postUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body: body,
        headers: { Accept: 'application/json' },
      })
        .then(function (res) {
          return res.text().then(function (text) {
            var data = null;
            try {
              data = text ? JSON.parse(text) : null;
            } catch (e) {
              throw new Error('Neizdevās atjaunot priekšskatījumu.');
            }
            if (!res.ok || !data || !data.ok) {
              throw new Error((data && data.error) || 'Neizdevās atjaunot priekšskatījumu');
            }
            return data;
          });
        })
        .then(function (data) {
          setPreviewHtml(data.preview_html || '');
        })
        .catch(function () {
          /* keep last good preview */
        });
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
        if (editorApi && editorWrap) {
          var editable = editorWrap.querySelector('[contenteditable="true"]');
          var hiddenInput = document.getElementById('clientEmailComposeBodyInput');
          if (editable) {
            editable.addEventListener('input', schedulePreviewUpdate);
          }
          if (hiddenInput) {
            hiddenInput.addEventListener('input', schedulePreviewUpdate);
          }
        }
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
      setPreviewHtml('<p style="font-family:sans-serif;color:#666;padding:24px;">Ielādē priekšskatījumu…</p>');

      loadDraft(group)
        .then(function (data) {
          if (subjectInput) subjectInput.value = data.subject || '';
          var editor = ensureEditor();
          if (editor) editor.setHtml(data.body_html || '');
          setPreviewHtml(data.preview_html || '');
        })
        .catch(function (err) {
          showError(err && err.message ? err.message : 'Neizdevās ielādēt sagatavi');
          if (subjectInput) subjectInput.value = '';
          var editor = ensureEditor();
          if (editor) editor.setHtml('');
          setPreviewHtml('');
        });
    }

    if (subjectInput) {
      subjectInput.addEventListener('input', schedulePreviewUpdate);
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
