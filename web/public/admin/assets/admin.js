(function () {
  var list = document.getElementById('sortable');
  var input = document.getElementById('image_order');
  if (list && input) {
    var dragEl = null;

    function syncOrder() {
      var tokens = [];
      list.querySelectorAll('li[data-token]').forEach(function (li) {
        tokens.push(li.getAttribute('data-token'));
      });
      input.value = tokens.join(',');
    }

    list.querySelectorAll('li').forEach(function (li) {
      li.setAttribute('draggable', 'true');
      li.addEventListener('dragstart', function (evt) {
        if (evt.target && evt.target.closest && evt.target.closest('.admin-cover-pick, .admin-media-thumb')) {
          evt.preventDefault();
          return;
        }
        dragEl = li;
        li.classList.add('dragging');
      });
      li.addEventListener('dragend', function () {
        li.classList.remove('dragging');
        dragEl = null;
        syncOrder();
      });
      li.addEventListener('dragover', function (e) {
        e.preventDefault();
        if (!dragEl || dragEl === li) return;
        var rect = li.getBoundingClientRect();
        var midX = rect.left + rect.width / 2;
        var midY = rect.top + rect.height / 2;
        var after = e.clientX > midX || (Math.abs(e.clientX - midX) < 8 && e.clientY > midY);
        if (after) {
          li.parentNode.insertBefore(dragEl, li.nextSibling);
        } else {
          li.parentNode.insertBefore(dragEl, li);
        }
      });
    });

    syncOrder();
    var form = list.closest('form');
    if (form) {
      form.addEventListener('submit', syncOrder);
    }
  }

  var lightbox = document.getElementById('admin-lightbox');
  if (!lightbox) return;

  var lightImg = lightbox.querySelector('img');
  var closeBtn = lightbox.querySelector('.admin-lightbox-close');

  function openLightbox(url) {
    if (!url || !lightImg) return;
    lightImg.src = url;
    lightbox.hidden = false;
    document.body.style.overflow = 'hidden';
  }

  function closeLightbox() {
    lightbox.hidden = true;
    if (lightImg) lightImg.removeAttribute('src');
    document.body.style.overflow = '';
  }

  document.addEventListener('click', function (evt) {
    var btn = evt.target && evt.target.closest ? evt.target.closest('.admin-media-thumb') : null;
    if (btn) {
      evt.preventDefault();
      openLightbox(btn.getAttribute('data-preview'));
      return;
    }
    if (evt.target === lightbox || evt.target === closeBtn) {
      closeLightbox();
    }
  });

  document.addEventListener('keydown', function (evt) {
    if (evt.key === 'Escape' && !lightbox.hidden) {
      closeLightbox();
    }
  });
})();
