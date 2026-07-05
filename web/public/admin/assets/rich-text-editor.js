(function () {
  function cleanPastedHtml(html) {
    if (!html) return '';
    var doc = new DOMParser().parseFromString(html, 'text/html');
    doc.querySelectorAll('script, meta, link, style, title').forEach(function (el) {
      el.remove();
    });
    var googleDocs = doc.querySelector('[id^="docs-internal-guid"]');
    if (googleDocs) {
      return googleDocs.innerHTML;
    }
    var body = doc.body;
    if (!body) return html;
    body.querySelectorAll('*').forEach(function (el) {
      Array.from(el.attributes).forEach(function (attr) {
        if (/^on/i.test(attr.name)) {
          el.removeAttribute(attr.name);
        }
        if ((attr.name === 'href' || attr.name === 'src') && /^javascript:/i.test(attr.value)) {
          el.removeAttribute(attr.name);
        }
      });
    });
    return body.innerHTML;
  }

  function initRichTextEditor(wrap) {
    if (!wrap) return null;
    if (wrap.getAttribute('data-rich-editor-ready') === '1') {
      return wrap._efpicRichEditor || null;
    }
    var editor = wrap.querySelector('[data-rich-editor]');
    var toolbar = wrap.querySelector('[data-rich-toolbar]');
    var hiddenInput = wrap.querySelector('[data-rich-input]');
    var initialEl = wrap.querySelector('[data-rich-initial]');
    var uploadUrl =
      wrap.getAttribute('data-upload-url') || window.EFPIC_SIGNATURE_UPLOAD_URL || '';
    if (!editor || !toolbar) return null;

    var initialHtml = '';
    if (initialEl) {
      try {
        initialHtml = JSON.parse(initialEl.textContent || '""');
      } catch (e) {
        initialHtml = '';
      }
    }

    function syncHiddenInput() {
      if (!hiddenInput) return;
      var html = editor.innerHTML.replace(/^\s+|\s+$/g, '');
      if (html === '<br>' || html === '<div><br></div>') html = '';
      hiddenInput.value = html;
    }

    function exec(cmd, value) {
      editor.focus();
      try {
        document.execCommand(cmd, false, value === undefined ? null : value);
      } catch (e) {
        /* ignore */
      }
      syncHiddenInput();
    }

    function uploadImage() {
      if (!uploadUrl) return;
      var input = document.createElement('input');
      input.type = 'file';
      input.accept = 'image/png,image/jpeg,image/webp,image/gif';
      input.addEventListener('change', function () {
        var file = input.files && input.files[0];
        if (!file) return;
        var formData = new FormData();
        formData.append('signature_image', file);
        fetch(uploadUrl, {
          method: 'POST',
          credentials: 'same-origin',
          body: formData,
        })
          .then(function (res) {
            return res.json();
          })
          .then(function (data) {
            if (!data || !data.ok || !data.url) {
              throw new Error((data && data.error) || 'Augšupielāde neizdevās');
            }
            exec('insertImage', data.url);
          })
          .catch(function (err) {
            window.alert(err && err.message ? err.message : 'Neizdevās augšupielādēt bildi.');
          });
      });
      input.click();
    }

    function insertLink() {
      var url = window.prompt('Saites adrese (URL):', 'https://');
      if (!url) return;
      exec('createLink', url);
    }

    toolbar.addEventListener('change', function (evt) {
      var target = evt.target;
      if (!target || !target.getAttribute) return;
      var cmd = target.getAttribute('data-cmd');
      if (!cmd) return;
      if (cmd === 'fontName' || cmd === 'fontSize') {
        exec(cmd, target.value);
      }
    });

    toolbar.addEventListener('click', function (evt) {
      var btn = evt.target && evt.target.closest ? evt.target.closest('[data-cmd]') : null;
      if (!btn || btn.tagName === 'SELECT') return;
      evt.preventDefault();
      var cmd = btn.getAttribute('data-cmd');
      if (!cmd) return;
      if (cmd === 'link') {
        insertLink();
        return;
      }
      if (cmd === 'image') {
        uploadImage();
        return;
      }
      if (cmd === 'foreColor') {
        var color = window.prompt('Teksta krāsa (piem. #111111 vai red):', '#111111');
        if (color) exec('foreColor', color);
        return;
      }
      exec(cmd);
    });

    editor.addEventListener('paste', function (evt) {
      evt.preventDefault();
      var html = evt.clipboardData ? evt.clipboardData.getData('text/html') : '';
      var text = evt.clipboardData ? evt.clipboardData.getData('text/plain') : '';
      if (html) {
        document.execCommand('insertHTML', false, cleanPastedHtml(html));
      } else if (text) {
        document.execCommand('insertText', false, text);
      }
      syncHiddenInput();
    });

    editor.addEventListener('input', syncHiddenInput);
    editor.addEventListener('blur', syncHiddenInput);

    var api = {
      wrap: wrap,
      editor: editor,
      hiddenInput: hiddenInput,
      sync: syncHiddenInput,
      setHtml: function (html) {
        editor.innerHTML = html || '';
        syncHiddenInput();
      },
      getHtml: function () {
        syncHiddenInput();
        return hiddenInput ? hiddenInput.value : editor.innerHTML;
      },
    };

    if (initialHtml) {
      api.setHtml(initialHtml);
    } else {
      syncHiddenInput();
    }

    var form = wrap.closest('form');
    if (form) {
      form.addEventListener('submit', syncHiddenInput);
    }

    wrap.setAttribute('data-rich-editor-ready', '1');
    wrap._efpicRichEditor = api;
    return api;
  }

  window.efpicInitRichTextEditor = initRichTextEditor;

  document.querySelectorAll('[data-rich-editor-wrap]').forEach(function (wrap) {
    initRichTextEditor(wrap);
  });
})();
