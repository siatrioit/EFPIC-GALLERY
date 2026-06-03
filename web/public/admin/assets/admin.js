(function () {
  var scenesEditor = document.getElementById('admin-scenes-editor');
  var scenesInput = document.getElementById('scenes_json');
  var addSceneBtn = document.getElementById('admin-add-scene');

  function readScenes() {
    if (!scenesEditor) return [];
    try {
      var raw = scenesEditor.getAttribute('data-scenes') || '[]';
      var data = JSON.parse(raw);
      return Array.isArray(data) ? data : [];
    } catch (e) {
      return [];
    }
  }

  function writeScenes(scenes) {
    if (!scenesEditor) return;
    scenesEditor.setAttribute('data-scenes', JSON.stringify(scenes));
    if (scenesInput) {
      scenesInput.value = JSON.stringify(
        scenes.map(function (s, i) {
          return { id: s.id, title: s.title, sort: i + 1 };
        })
      );
    }
    renderScenes(scenes);
    syncSceneSelects(scenes);
  }

  function renderScenes(scenes) {
    if (!scenesEditor) return;
    scenesEditor.innerHTML = '';
    scenes.forEach(function (scene) {
      var row = document.createElement('div');
      row.className = 'admin-scene-row';
      row.dataset.id = scene.id;
      var titleInput = document.createElement('input');
      titleInput.type = 'text';
      titleInput.value = scene.title || '';
      titleInput.placeholder = 'Sadaļas nosaukums';
      titleInput.className = 'admin-scene-title-input';
      var meta = document.createElement('span');
      meta.className = 'admin-scene-meta';
      meta.textContent = (scene.count || 0) + ' bildes';
      row.appendChild(titleInput);
      row.appendChild(meta);
      if (scene.id !== 'main') {
        var del = document.createElement('button');
        del.type = 'button';
        del.className = 'btn admin-scene-del';
        del.textContent = 'Dzēst';
        del.addEventListener('click', function () {
          var count = parseInt(scene.count || '0', 10);
          if (count > 0) {
            if (!window.confirm('Sadaļā ir bildes. Dzēšot, tās pāries uz «Galerija».')) {
              return;
            }
          }
          var next = readScenes().filter(function (s) {
            return s.id !== scene.id;
          });
          writeScenes(next);
        });
        row.appendChild(del);
      }
      titleInput.addEventListener('input', function () {
        var list = readScenes();
        list.forEach(function (s) {
          if (s.id === scene.id) s.title = titleInput.value;
        });
        writeScenes(list);
      });
      scenesEditor.appendChild(row);
    });
  }

  function syncSceneSelects(scenes) {
    document.querySelectorAll('.admin-scene-pick select').forEach(function (sel) {
      var current = sel.value;
      sel.innerHTML = '';
      scenes.forEach(function (scene) {
        var opt = document.createElement('option');
        opt.value = scene.id;
        opt.textContent = scene.title || scene.id;
        if (scene.id === current) opt.selected = true;
        sel.appendChild(opt);
      });
    });
    document.querySelectorAll('select[name="video_upload_scene"], select[name="video_embed_scene"]').forEach(function (sel) {
      var current = sel.value;
      sel.innerHTML = '';
      scenes.forEach(function (scene) {
        var opt = document.createElement('option');
        opt.value = scene.id;
        opt.textContent = scene.title || scene.id;
        if (scene.id === current) opt.selected = true;
        sel.appendChild(opt);
      });
    });
  }

  if (scenesEditor) {
    renderScenes(readScenes());
    if (addSceneBtn) {
      addSceneBtn.addEventListener('click', function () {
        var scenes = readScenes();
        var id = 'scene_' + Math.random().toString(16).slice(2, 10);
        scenes.push({ id: id, title: 'Jauna sadaļa', count: 0 });
        writeScenes(scenes);
      });
    }
    var form = scenesEditor.closest('form');
    if (form) {
      form.addEventListener('submit', function () {
        writeScenes(readScenes());
      });
    }
  }

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
        if (evt.target && evt.target.closest && evt.target.closest('.admin-cover-pick, .admin-media-thumb, .admin-scene-pick')) {
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
    var orderForm = list.closest('form');
    if (orderForm) {
      orderForm.addEventListener('submit', syncOrder);
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
