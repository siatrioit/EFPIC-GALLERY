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

  function readScenesFromDom() {
    if (!scenesEditor) return [];
    var scenes = [];
    scenesEditor.querySelectorAll('.admin-scene-row').forEach(function (row, index) {
      var input = row.querySelector('.admin-scene-title-input');
      var id = row.getAttribute('data-id') || row.dataset.id || '';
      var count = 0;
      var meta = row.querySelector('.admin-scene-meta');
      if (meta) {
        var m = meta.textContent.match(/(\d+)/);
        count = m ? parseInt(m[1], 10) : 0;
      }
      scenes.push({
        id: id,
        title: input ? input.value : '',
        count: count,
        sort: index + 1,
      });
    });
    return scenes;
  }

  function persistScenesJson(scenes) {
    if (!scenesEditor) return;
    scenesEditor.setAttribute('data-scenes', JSON.stringify(scenes));
    if (scenesInput) {
      scenesInput.value = JSON.stringify(
        scenes.map(function (s, i) {
          return { id: s.id, title: s.title, sort: i + 1 };
        })
      );
    }
  }

  function updateSceneMetaCounts(scenes) {
    if (!scenesEditor) return;
    scenesEditor.querySelectorAll('.admin-scene-row').forEach(function (row) {
      var id = row.getAttribute('data-id') || row.dataset.id;
      var scene = scenes.find(function (s) {
        return s.id === id;
      });
      var meta = row.querySelector('.admin-scene-meta');
      if (meta && scene) {
        meta.textContent = (scene.count || 0) + ' bildes';
      }
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
    var bulkTarget = document.getElementById('admin-bulk-scene-target');
    if (bulkTarget) {
      var bulkCurrent = bulkTarget.value;
      bulkTarget.innerHTML = '';
      scenes.forEach(function (scene) {
        var opt = document.createElement('option');
        opt.value = scene.id;
        opt.textContent = scene.title || scene.id;
        if (scene.id === bulkCurrent) opt.selected = true;
        bulkTarget.appendChild(opt);
      });
    }
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

  function renderScenes(scenes, focusId) {
    if (!scenesEditor) return;
    scenesEditor.innerHTML = '';
    scenes.forEach(function (scene, index) {
      var row = document.createElement('div');
      row.className = 'admin-scene-row';
      row.setAttribute('data-id', scene.id);
      row.dataset.id = scene.id;
      var moveWrap = document.createElement('div');
      moveWrap.className = 'admin-scene-move-wrap';
      var grip = document.createElement('span');
      grip.className = 'admin-scene-drag';
      grip.setAttribute('role', 'button');
      grip.setAttribute('tabindex', '0');
      grip.setAttribute('aria-label', 'Velciet, lai mainītu secību');
      grip.textContent = '⋮⋮';
      var upBtn = document.createElement('button');
      upBtn.type = 'button';
      upBtn.className = 'btn admin-scene-move';
      upBtn.textContent = '↑';
      upBtn.setAttribute('aria-label', 'Augstāk');
      upBtn.disabled = index === 0;
      upBtn.addEventListener('click', function () {
        moveSceneRow(index, -1);
      });
      var downBtn = document.createElement('button');
      downBtn.type = 'button';
      downBtn.className = 'btn admin-scene-move';
      downBtn.textContent = '↓';
      downBtn.setAttribute('aria-label', 'Zemāk');
      downBtn.disabled = index >= scenes.length - 1;
      downBtn.addEventListener('click', function () {
        moveSceneRow(index, 1);
      });
      moveWrap.appendChild(grip);
      moveWrap.appendChild(upBtn);
      moveWrap.appendChild(downBtn);
      var titleInput = document.createElement('input');
      titleInput.type = 'text';
      titleInput.value = scene.title || '';
      titleInput.placeholder = 'Sadaļas nosaukums';
      titleInput.className = 'admin-scene-title-input';
      titleInput.addEventListener('input', function () {
        var list = readScenesFromDom();
        persistScenesJson(list);
        syncSceneSelects(list);
      });
      var meta = document.createElement('span');
      meta.className = 'admin-scene-meta';
      meta.textContent = (scene.count || 0) + ' bildes';
      var pickSceneBtn = document.createElement('button');
      pickSceneBtn.type = 'button';
      pickSceneBtn.className = 'btn admin-scene-pick-images';
      pickSceneBtn.textContent = 'Atlasīt bildes';
      pickSceneBtn.addEventListener('click', function () {
        if (typeof window.efpicSelectImagesByScene === 'function') {
          window.efpicSelectImagesByScene(scene.id, true);
        }
      });
      row.appendChild(moveWrap);
      row.appendChild(titleInput);
      row.appendChild(meta);
      row.appendChild(pickSceneBtn);
      if (scene.id !== 'main') {
        var del = document.createElement('button');
        del.type = 'button';
        del.className = 'btn admin-scene-del';
        del.textContent = 'Dzēst';
        del.addEventListener('click', function () {
          var count = parseInt(scene.count || '0', 10);
          if (count > 0 && !window.confirm('Sadaļā ir bildes. Dzēšot, tās pāries uz «Galerija».')) {
            return;
          }
          var next = readScenesFromDom().filter(function (s) {
            return s.id !== scene.id;
          });
          persistScenesJson(next);
          renderScenes(next);
          syncSceneSelects(next);
        });
        row.appendChild(del);
      }
      scenesEditor.appendChild(row);
      if (focusId && scene.id === focusId) {
        titleInput.focus();
        titleInput.select();
      }
    });
    initSceneDrag();
  }

  function moveSceneRow(fromIndex, delta) {
    var list = readScenesFromDom();
    var toIndex = fromIndex + delta;
    if (toIndex < 0 || toIndex >= list.length) return;
    var moved = list.splice(fromIndex, 1)[0];
    list.splice(toIndex, 0, moved);
    persistScenesJson(list);
    renderScenes(list);
    syncSceneSelects(list);
  }

  function initSceneDrag() {
    if (!scenesEditor) return;
    var dragRow = null;
    var activeGrip = null;
    var dragGhost = null;

    function removeGhost() {
      if (dragGhost && dragGhost.parentNode) {
        dragGhost.parentNode.removeChild(dragGhost);
      }
      dragGhost = null;
    }

    function positionGhost(clientX, clientY) {
      if (!dragGhost) return;
      dragGhost.style.left = clientX + 12 + 'px';
      dragGhost.style.top = clientY + 12 + 'px';
    }

    function finishDrag(e) {
      if (!dragRow) return;
      var grip = activeGrip;
      dragRow.classList.remove('dragging');
      dragRow.style.pointerEvents = '';
      dragRow = null;
      activeGrip = null;
      removeGhost();
      if (e && grip && grip.releasePointerCapture) {
        try {
          grip.releasePointerCapture(e.pointerId);
        } catch (err) {
          /* ignore */
        }
      }
      var list = readScenesFromDom();
      persistScenesJson(list);
      syncSceneSelects(list);
    }

    function moveDragRow(clientY) {
      if (!dragRow) return;
      dragRow.style.pointerEvents = 'none';
      var el = document.elementFromPoint(
        dragRow.getBoundingClientRect().left + Math.min(dragRow.offsetWidth / 2, 120),
        clientY
      );
      var target = el && el.closest ? el.closest('.admin-scene-row') : null;
      if (!target || target === dragRow || !scenesEditor.contains(target)) return;
      var rect = target.getBoundingClientRect();
      var after = clientY > rect.top + rect.height / 2;
      if (after) {
        scenesEditor.insertBefore(dragRow, target.nextSibling);
      } else {
        scenesEditor.insertBefore(dragRow, target);
      }
    }

    function startDrag(row, grip, clientX, clientY, pointerId) {
      dragRow = row;
      activeGrip = grip;
      row.classList.add('dragging');
      row.style.pointerEvents = 'none';
      dragGhost = row.cloneNode(true);
      dragGhost.classList.add('admin-scene-drag-ghost');
      dragGhost.classList.remove('dragging');
      dragGhost.style.pointerEvents = 'none';
      document.body.appendChild(dragGhost);
      positionGhost(clientX, clientY);
      if (typeof pointerId === 'number' && grip.setPointerCapture) {
        try {
          grip.setPointerCapture(pointerId);
        } catch (err) {
          /* ignore */
        }
      }
      moveDragRow(clientY);
    }

    scenesEditor.querySelectorAll('.admin-scene-row').forEach(function (row) {
      var grip = row.querySelector('.admin-scene-drag');
      if (!grip) return;

      grip.addEventListener('pointerdown', function (e) {
        if (e.button !== 0) return;
        e.preventDefault();
        startDrag(row, grip, e.clientX, e.clientY, e.pointerId);
      });

      grip.addEventListener('pointermove', function (e) {
        if (!dragRow || dragRow !== row) return;
        e.preventDefault();
        positionGhost(e.clientX, e.clientY);
        moveDragRow(e.clientY);
      });

      grip.addEventListener('pointerup', function (e) {
        if (dragRow !== row) return;
        e.preventDefault();
        finishDrag(e);
      });

      grip.addEventListener('pointercancel', function (e) {
        if (dragRow !== row) return;
        finishDrag(e);
      });

      grip.addEventListener('dragstart', function (e) {
        e.preventDefault();
      });
    });

    document.addEventListener('mousemove', function (e) {
      if (!dragRow || e.buttons !== 1) return;
      positionGhost(e.clientX, e.clientY);
      moveDragRow(e.clientY);
    });

    document.addEventListener('mouseup', function () {
      if (!dragRow) return;
      finishDrag(null);
    });
  }

  if (scenesEditor) {
    renderScenes(readScenes());
    if (addSceneBtn) {
      addSceneBtn.addEventListener('click', function () {
        var scenes = readScenesFromDom();
        var id = 'scene_' + Math.random().toString(16).slice(2, 10);
        scenes.push({ id: id, title: 'Jauna sadaļa', count: 0 });
        persistScenesJson(scenes);
        renderScenes(scenes, id);
        syncSceneSelects(scenes);
      });
    }
    var deliveryForm = scenesEditor.closest('form');
    if (deliveryForm) {
      deliveryForm.addEventListener('submit', function () {
        var scenes = readScenesFromDom();
        persistScenesJson(scenes);
      });
    }
  }

  var imageGrid = document.getElementById('sortable');
  var activeSceneFilter = 'all';
  var lastPickedCard = null;

  function cardMatchesFilter(card, filterId) {
    var fid = filterId !== undefined ? filterId : activeSceneFilter;
    if (fid === 'all') {
      return true;
    }
    if (fid === 'admin-fav') {
      return card.getAttribute('data-admin-fav') === '1';
    }
    if (fid === 'client-fav') {
      return card.getAttribute('data-client-fav') === '1';
    }
    if (fid === 'liked') {
      return parseInt(card.getAttribute('data-likes') || '0', 10) > 0;
    }

    return (card.getAttribute('data-scene-id') || 'main') === fid;
  }

  function visibleImageCards() {
    if (!imageGrid) return [];
    return Array.prototype.slice.call(imageGrid.querySelectorAll('.admin-media-card')).filter(function (card) {
      return cardMatchesFilter(card);
    });
  }

  function clearAllPicks() {
    if (!imageGrid) return;
    imageGrid.querySelectorAll('.admin-image-pick').forEach(function (cb) {
      setCardPicked(cb.closest('.admin-media-card'), false);
    });
    lastPickedCard = null;
  }

  function setCardPicked(card, picked) {
    var cb = card.querySelector('.admin-image-pick');
    if (!cb) return;
    cb.checked = picked;
    card.classList.toggle('is-picked', picked);
  }

  function updatePickCount() {
    var el = document.getElementById('admin-pick-count');
    if (!el || !imageGrid) return;
    var n = imageGrid.querySelectorAll('.admin-image-pick:checked').length;
    el.textContent = n === 1 ? '1 bilde atlasīta' : n + ' atlasītas';
  }

  function applySceneFilter(filterId) {
    activeSceneFilter = filterId;
    if (!imageGrid) return;
    imageGrid.querySelectorAll('.admin-media-card').forEach(function (card) {
      card.classList.toggle('is-filtered-out', !cardMatchesFilter(card, filterId));
    });
    document.querySelectorAll('.admin-scene-filter-btn').forEach(function (btn) {
      btn.classList.toggle('is-active', btn.getAttribute('data-scene-filter') === filterId);
    });
  }

  window.efpicSelectImagesByScene = function (sceneId, scrollToGrid) {
    if (!imageGrid) return;
    applySceneFilter(sceneId);
    clearAllPicks();
    imageGrid.querySelectorAll('.admin-media-card[data-scene-id="' + sceneId + '"]').forEach(function (card) {
      setCardPicked(card, true);
    });
    lastPickedCard = imageGrid.querySelector('.admin-media-card[data-scene-id="' + sceneId + '"]');
    updatePickCount();
    if (scrollToGrid) {
      var bar = document.getElementById('admin-image-bulk-bar');
      if (bar) bar.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  };

  if (imageGrid) {
    imageGrid.addEventListener('change', function (evt) {
      if (evt.target && evt.target.classList && evt.target.classList.contains('admin-image-pick')) {
        var card = evt.target.closest('.admin-media-card');
        if (card) {
          card.classList.toggle('is-picked', evt.target.checked);
          if (evt.target.checked) lastPickedCard = card;
        }
        updatePickCount();
      }
      if (evt.target && evt.target.closest && evt.target.closest('.admin-fav-pick input')) {
        var favCard = evt.target.closest('.admin-media-card');
        if (favCard) {
          favCard.setAttribute('data-admin-fav', evt.target.checked ? '1' : '0');
        }
      }
      if (evt.target && evt.target.closest && evt.target.closest('.admin-scene-pick select')) {
        var cardSel = evt.target.closest('.admin-media-card');
        if (cardSel) cardSel.setAttribute('data-scene-id', evt.target.value);
      }
    });

    imageGrid.addEventListener('click', function (evt) {
      var shiftPick = evt.shiftKey;
      var thumb = evt.target.closest('.admin-media-thumb');
      if (thumb && !shiftPick) {
        return;
      }
      if (
        evt.target.closest('.admin-cover-pick, .admin-fav-pick, .admin-scene-pick, .admin-bulk-pick')
      ) {
        return;
      }
      var card = evt.target.closest('.admin-media-card');
      if (!card) return;
      if (activeSceneFilter !== 'all' && !cardMatchesFilter(card)) {
        return;
      }
      var cb = card.querySelector('.admin-image-pick');
      if (!cb) return;

      evt.preventDefault();

      var cards = visibleImageCards();
      if (shiftPick) {
        if (!lastPickedCard || cards.indexOf(lastPickedCard) === -1) {
          lastPickedCard = cards[0] || card;
        }
        var start = cards.indexOf(lastPickedCard);
        var end = cards.indexOf(card);
        if (start === -1) start = end;
        if (end === -1) end = start;
        var lo = Math.min(start, end);
        var hi = Math.max(start, end);
        for (var i = lo; i <= hi; i++) {
          setCardPicked(cards[i], true);
        }
        lastPickedCard = card;
        updatePickCount();
        return;
      }

      setCardPicked(card, !cb.checked);
      lastPickedCard = card;
      updatePickCount();
    });

    updatePickCount();
  }

  var sceneFilter = document.getElementById('admin-scene-filter');
  if (sceneFilter) {
    sceneFilter.addEventListener('click', function (evt) {
      var btn = evt.target && evt.target.closest ? evt.target.closest('.admin-scene-filter-btn') : null;
      if (!btn) return;
      applySceneFilter(btn.getAttribute('data-scene-filter') || 'all');
    });
  }

  var assignSceneBtn = document.getElementById('admin-assign-scene');
  var bulkSceneTarget = document.getElementById('admin-bulk-scene-target');
  if (assignSceneBtn && bulkSceneTarget && imageGrid) {
    assignSceneBtn.addEventListener('click', function () {
      var sceneId = bulkSceneTarget.value;
      var picks = imageGrid.querySelectorAll('.admin-image-pick:checked');
      if (!picks.length) {
        window.alert('Atlasiet bildes: klikšķiniet uz bildēm vai izmantojiet «Atlasīt bildes» pie sadaļas.');
        return;
      }
      picks.forEach(function (cb) {
        var card = cb.closest('.admin-media-card');
        if (!card) return;
        var sel = card.querySelector('.admin-scene-pick select');
        if (sel) {
          sel.value = sceneId;
          card.setAttribute('data-scene-id', sceneId);
        }
        setCardPicked(card, false);
      });
      updatePickCount();
      if (typeof window.efpicRefreshSceneFilterCounts === 'function') {
        window.efpicRefreshSceneFilterCounts();
      }
    });
  }

  var selectAllImages = document.getElementById('admin-select-all-images');
  if (selectAllImages && imageGrid) {
    selectAllImages.addEventListener('click', function () {
      applySceneFilter('all');
      imageGrid.querySelectorAll('.admin-image-pick').forEach(function (cb) {
        setCardPicked(cb.closest('.admin-media-card'), true);
      });
      updatePickCount();
    });
  }

  var selectVisibleImages = document.getElementById('admin-select-visible-images');
  if (selectVisibleImages && imageGrid) {
    selectVisibleImages.addEventListener('click', function () {
      clearAllPicks();
      visibleImageCards().forEach(function (card) {
        setCardPicked(card, true);
      });
      var visible = visibleImageCards();
      lastPickedCard = visible.length ? visible[visible.length - 1] : null;
      updatePickCount();
    });
  }

  var clearImageSelection = document.getElementById('admin-clear-image-selection');
  if (clearImageSelection && imageGrid) {
    clearImageSelection.addEventListener('click', function () {
      clearAllPicks();
      updatePickCount();
    });
  }

  window.efpicRefreshSceneFilterCounts = function () {
    if (!imageGrid || !sceneFilter) return;
    var counts = { all: imageGrid.querySelectorAll('.admin-media-card').length };
    imageGrid.querySelectorAll('.admin-media-card').forEach(function (card) {
      var sid = card.getAttribute('data-scene-id') || 'main';
      counts[sid] = (counts[sid] || 0) + 1;
    });
    sceneFilter.querySelectorAll('.admin-scene-filter-btn').forEach(function (btn) {
      var fid = btn.getAttribute('data-scene-filter') || 'all';
      var label = btn.textContent.replace(/\s*\(\d+\)\s*$/, '').trim();
      var n = fid === 'all' ? counts.all : counts[fid] || 0;
      btn.textContent = label + ' (' + n + ')';
    });
  };

  var galleryBulkForm = document.getElementById('admin-gallery-bulk-form');
  if (galleryBulkForm) {
    galleryBulkForm.addEventListener('submit', function (evt) {
      var submitter = evt.submitter;
      if (!submitter || submitter.getAttribute('data-confirm-delete') !== '1') {
        return;
      }
      var typed = window.prompt('Lai apstiprinātu, ievadiet DELETE:');
      if (typed !== 'DELETE') {
        evt.preventDefault();
        return;
      }
      var hidden = document.getElementById('confirm_delete');
      if (hidden) hidden.value = 'DELETE';
    });
    var selectAllGalleries = document.getElementById('admin-gallery-select-all');
    if (selectAllGalleries) {
      selectAllGalleries.addEventListener('change', function () {
        document.querySelectorAll('.admin-gallery-pick').forEach(function (cb) {
          cb.checked = selectAllGalleries.checked;
        });
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
        if (
          evt.target &&
          evt.target.closest &&
          evt.target.closest(
            '.admin-cover-pick, .admin-media-thumb, .admin-scene-pick, .admin-bulk-pick, .admin-fav-pick, .admin-image-pick'
          )
        ) {
          evt.preventDefault();
          return;
        }
        if (li.classList.contains('is-picked')) {
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
    var btn = evt.target && evt.target.closest ? evt.target.closest('.admin-media-thumb, .admin-fav-preview') : null;
    if (btn) {
      if (evt.shiftKey) return;
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
