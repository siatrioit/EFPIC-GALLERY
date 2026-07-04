(function () {
  var mount = document.getElementById('signatureEditor');
  if (!mount || typeof Quill === 'undefined') return;

  var hiddenInput = document.getElementById('galleryEmailSignatureInput');
  var initialEl = document.getElementById('signatureEditorInitial');
  var uploadUrl = window.EFPIC_SIGNATURE_UPLOAD_URL || 'settings.php?upload=signature_image';
  var initialHtml = '';
  if (initialEl) {
    try {
      initialHtml = JSON.parse(initialEl.textContent || '""');
    } catch (e) {
      initialHtml = '';
    }
  }

  function uploadSignatureImage() {
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
          var range = quill.getSelection(true);
          quill.insertEmbed(range.index, 'image', data.url, 'user');
          quill.setSelection(range.index + 1);
        })
        .catch(function (err) {
          window.alert(err && err.message ? err.message : 'Neizdevās augšupielādēt bildi.');
        });
    });
    input.click();
  }

  var quill = new Quill('#signatureEditor', {
    theme: 'snow',
    modules: {
      toolbar: {
        container: [
          [{ font: [] }],
          [{ size: ['small', false, 'large', 'huge'] }],
          ['bold', 'italic', 'underline'],
          [{ color: [] }, { background: [] }],
          ['link', 'image'],
          [{ align: [] }],
          [{ list: 'ordered' }, { list: 'bullet' }],
        ],
        handlers: {
          image: uploadSignatureImage,
        },
      },
    },
    placeholder: 'Ar cieņu,\nEdgars Pohevics',
  });

  if (initialHtml) {
    if (quill.clipboard && typeof quill.clipboard.dangerouslyPasteHTML === 'function') {
      quill.clipboard.dangerouslyPasteHTML(initialHtml);
    } else {
      quill.root.innerHTML = initialHtml;
    }
  }

  function syncHiddenInput() {
    if (!hiddenInput) return;
    var html = quill.getSemanticHTML ? quill.getSemanticHTML() : quill.root.innerHTML;
    if (html === '<p><br></p>') html = '';
    hiddenInput.value = html;
  }

  quill.on('text-change', syncHiddenInput);
  syncHiddenInput();

  var form = mount.closest('form');
  if (form) {
    form.addEventListener('submit', function () {
      syncHiddenInput();
    });
  }
})();
