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

  function sceneTitleById(scenes, sceneId) {
    var hit = scenes.find(function (s) {
      return s.id === sceneId;
    });
    return hit ? hit.title || hit.id : sceneId;
  }

  function syncSceneDatalist(scenes) {
    var datalist = document.getElementById('admin-scene-datalist');
    if (!datalist) return;
    datalist.innerHTML = '';
    scenes.forEach(function (scene) {
      var opt = document.createElement('option');
      opt.value = scene.title || scene.id;
      datalist.appendChild(opt);
    });
  }

  function syncSceneInputs(scenes) {
    syncSceneDatalist(scenes);
    document.querySelectorAll('.admin-media-card').forEach(function (card) {
      var hidden = card.querySelector('.admin-scene-id');
      var text = card.querySelector('.admin-scene-input');
      if (!hidden || !text) return;
      var sid = hidden.value || card.getAttribute('data-scene-id') || 'main';
      text.value = sceneTitleById(scenes, sid);
    });
    document.querySelectorAll(
      '.admin-video-scene-select, select[name="video_upload_scene"], select[name="video_embed_scene"]'
    ).forEach(function (sel) {
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

  function currentScenesList() {
    var fromDom = readScenesFromDom();
    if (fromDom.length) {
      return fromDom;
    }
    if (scenesInput && scenesInput.value.trim() !== '') {
      try {
        var parsed = JSON.parse(scenesInput.value);
        if (Array.isArray(parsed)) {
          return parsed.map(function (s, index) {
            return {
              id: s.id,
              title: s.title || s.id,
              count: 0,
              sort: s.sort || index + 1,
            };
          });
        }
      } catch (e) {
        /* ignore */
      }
    }
    return readScenes();
  }

  function ensureSceneForTitle(title) {
    var trimmed = (title || '').trim();
    var scenes = currentScenesList();
    if (trimmed === '') {
      return { id: 'main', title: sceneTitleById(scenes, 'main') };
    }
    var lower = trimmed.toLowerCase();
    var found = scenes.find(function (s) {
      return ((s.title || '') + '').trim().toLowerCase() === lower;
    });
    if (found) {
      return { id: found.id, title: (found.title || found.id).trim() };
    }
    var id = 'scene_' + Math.random().toString(16).slice(2, 10);
    scenes.push({ id: id, title: trimmed, count: 0 });
    persistScenesJson(scenes);
    if (scenesEditor) {
      renderScenes(scenes, id);
    }
    syncSceneInputs(scenes);
    return { id: id, title: trimmed };
  }

  function setCardScene(card, sceneId, sceneTitle) {
    if (!card) return;
    var hidden = card.querySelector('.admin-scene-id');
    var text = card.querySelector('.admin-scene-input');
    if (hidden) hidden.value = sceneId;
    if (text) text.value = sceneTitle;
    card.setAttribute('data-scene-id', sceneId);
  }

  function pickedImageCards() {
    if (!imageGrid) return [];
    return Array.prototype.slice
      .call(imageGrid.querySelectorAll('.admin-image-pick:checked'))
      .map(function (cb) {
        return cb.closest('.admin-media-card');
      })
      .filter(Boolean);
  }

  function sceneChangeTargets(changedCard) {
    var picks = pickedImageCards();
    if (picks.length >= 2 && picks.indexOf(changedCard) !== -1) {
      return picks;
    }

    return [changedCard];
  }

  function applySceneTitleToCards(cards, title) {
    if (!cards.length) return;
    var scene = ensureSceneForTitle(title);
    cards.forEach(function (card) {
      setCardScene(card, scene.id, scene.title);
    });
    if (typeof window.efpicRefreshSceneFilterCounts === 'function') {
      window.efpicRefreshSceneFilterCounts();
    }
    updateSceneCountsInEditor();
    scheduleAdminAutoSave();
  }

  var adminAutoSaveTimer = 0;
  var adminAutoSaveInFlight = false;
  var adminAutoSaveQueued = false;

  function adminFormIsEditDelivery() {
    var form = document.getElementById('admin-delivery-form');
    return !!(form && form.getAttribute('data-admin-edit-slug'));
  }

  function syncImageOrderField() {
    var list = document.getElementById('sortable');
    var input = document.getElementById('image_order');
    if (!list || !input) return;
    var tokens = [];
    list.querySelectorAll('li[data-token]').forEach(function (li) {
      var tok = li.getAttribute('data-token');
      if (tok) tokens.push(tok);
    });
    input.value = tokens.join(',');
  }

  function persistScenesBeforeSave() {
    if (!scenesEditor) return;
    var scenes = readScenesFromDom();
    if (scenes.length) {
      persistScenesJson(scenes);
    } else if (scenesInput && scenesInput.value.trim() !== '') {
      /* jau hidden laukā */
    }
  }

  function showAdminAutoSaveToast(message, isError) {
    var toast = document.getElementById('admin-autosave-toast');
    if (!toast) {
      toast = document.createElement('div');
      toast.id = 'admin-autosave-toast';
      toast.className = 'admin-autosave-toast';
      toast.setAttribute('role', 'status');
      toast.setAttribute('aria-live', 'polite');
      document.body.appendChild(toast);
    }
    toast.textContent = message || (isError ? 'Neizdevās saglabāt' : 'Saglabāts');
    toast.classList.toggle('is-error', !!isError);
    toast.classList.toggle('is-saving', false);
    toast.hidden = false;
    if (toast._hideTimer) clearTimeout(toast._hideTimer);
    toast._hideTimer = setTimeout(function () {
      toast.hidden = true;
    }, isError ? 4500 : 2200);
  }

  function clearTransientVideoFields() {
    var form = document.getElementById('admin-delivery-form');
    if (!form) return;
    ['video_embed_url', 'video_embed_title', 'video_upload_title'].forEach(function (name) {
      var el = form.querySelector('[name="' + name + '"]');
      if (el) el.value = '';
    });
    var file = form.querySelector('[name="gallery_video"]');
    if (file) file.value = '';
    var mp3 = form.querySelector('[name="slideshow_admin_mp3"]');
    if (mp3) mp3.value = '';
  }

  function bindAdminVideoRowEvents() {
    document.querySelectorAll('.admin-video-delete').forEach(function (btn) {
      if (btn.dataset.bound === '1') return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', function () {
        var vid = btn.getAttribute('data-video-id');
        if (!vid) return;
        if (!window.confirm('Dzēst šo video?')) return;
        var flag = document.querySelector('input.admin-video-delete-flag[name="delete_video[' + vid + ']"]');
        if (flag) flag.value = '1';
        var card = btn.closest('.admin-video-card');
        if (card) card.classList.add('is-deleted');
        runAdminAutoSave();
      });
    });
    document.querySelectorAll('.admin-video-scene-select').forEach(function (sel) {
      if (sel.dataset.autosaveBound === '1') return;
      sel.dataset.autosaveBound = '1';
      sel.addEventListener('change', function () {
        scheduleAdminAutoSave();
      });
    });
    document.querySelectorAll('input[name^="video_title["]').forEach(function (inp) {
      if (inp.dataset.autosaveBound === '1') return;
      inp.dataset.autosaveBound = '1';
      inp.addEventListener('change', function () {
        scheduleAdminAutoSave();
      });
    });
  }

  function shouldAutoSaveTarget(target) {
    if (!target || !target.name) return false;
    if (target.classList && target.classList.contains('admin-scene-input')) return false;
    if (target.classList && target.classList.contains('admin-image-pick')) return false;
    if (target.name === 'video_embed_url' || target.name === 'video_embed_title') return false;
    if (target.type === 'file') return false;
    if (target.name === 'save' || target.name === 'sync_now') return false;
    return true;
  }

  function initAdminFormAutoSave() {
    var form = document.getElementById('admin-delivery-form');
    if (!form || !adminFormIsEditDelivery()) return;

    bindAdminVideoRowEvents();

    form.addEventListener('change', function (evt) {
      if (shouldAutoSaveTarget(evt.target)) {
        scheduleAdminAutoSave();
      }
    });

    form.addEventListener('input', function (evt) {
      var t = evt.target;
      if (!shouldAutoSaveTarget(t)) return;
      if (t.tagName === 'SELECT' || t.type === 'checkbox' || t.type === 'radio') return;
      scheduleAdminAutoSave();
    });

    var galleryVideo = form.querySelector('[name="gallery_video"]');
    if (galleryVideo) {
      galleryVideo.addEventListener('change', function () {
        if (galleryVideo.files && galleryVideo.files.length) {
          runAdminAutoSave();
        }
      });
    }

    var slideshowMp3 = form.querySelector('[name="slideshow_admin_mp3"]');
    if (slideshowMp3) {
      slideshowMp3.addEventListener('change', function () {
        if (slideshowMp3.files && slideshowMp3.files.length) {
          runAdminAutoSave();
        }
      });
    }

    var addEmbedBtn = document.getElementById('admin-add-embed-video');
    if (addEmbedBtn) {
      addEmbedBtn.addEventListener('click', function () {
        var embedUrl = form.querySelector('[name="video_embed_url"]');
        if (!embedUrl || !embedUrl.value.trim()) {
          window.alert('Ievadi YouTube vai Vimeo saiti.');
          return;
        }
        var fd = new FormData(form);
        fd.set('autosave', '1');
        fd.set('add_video_embed', '1');
        fd.delete('sync_now');
        fd.delete('create_share_set');
        adminAutoSaveInFlight = true;
        fetch(window.location.pathname + window.location.search, {
          method: 'POST',
          body: fd,
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
        })
          .then(function (res) {
            var ct = (res.headers.get('content-type') || '').toLowerCase();
            if (ct.indexOf('application/json') === -1) {
              throw new Error('Servera atbilde nav derīga.');
            }
            return res.json().then(function (data) {
              if (!res.ok || !data || !data.ok) {
                throw new Error((data && data.error) || 'Neizdevās pievienot video');
              }
              return data;
            });
          })
          .then(function (data) {
            showAdminAutoSaveToast(data.message || 'Video pievienots', false);
            clearTransientVideoFields();
            if (data.videos_html) {
              var videosList = document.getElementById('admin-videos-list');
              if (videosList) {
                videosList.innerHTML = data.videos_html;
                bindAdminVideoRowEvents();
              }
            }
          })
          .catch(function (err) {
            showAdminAutoSaveToast(err && err.message ? err.message : 'Kļūda', true);
          })
          .finally(function () {
            adminAutoSaveInFlight = false;
          });
      });
    }
  }

  function scheduleAdminAutoSave() {
    if (!adminFormIsEditDelivery()) return;
    if (adminAutoSaveTimer) clearTimeout(adminAutoSaveTimer);
    adminAutoSaveTimer = setTimeout(function () {
      adminAutoSaveTimer = 0;
      runAdminAutoSave();
    }, 450);
  }

  function runAdminAutoSave() {
    if (!adminFormIsEditDelivery()) return;
    if (adminAutoSaveInFlight) {
      adminAutoSaveQueued = true;
      return;
    }
    var form = document.getElementById('admin-delivery-form');
    if (!form) return;

    persistScenesBeforeSave();
    syncImageOrderField();

    var toast = document.getElementById('admin-autosave-toast');
    if (toast) {
      toast.classList.add('is-saving');
      toast.textContent = 'Saglabā…';
      toast.hidden = false;
    }

    var fd = new FormData(form);
    fd.set('autosave', '1');
    fd.delete('sync_now');
    fd.delete('create_share_set');
    fd.delete('share_set_tokens');

    adminAutoSaveInFlight = true;
    fetch(window.location.pathname + window.location.search, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
      .then(function (res) {
        var ct = (res.headers.get('content-type') || '').toLowerCase();
        if (ct.indexOf('application/json') === -1) {
          throw new Error('Servera atbilde nav derīga (nav JSON).');
        }
        return res.json().then(function (data) {
          if (!res.ok || !data || !data.ok) {
            throw new Error((data && data.error) || (data && data.message) || 'Neizdevās saglabāt');
          }
          return data;
        });
      })
      .then(function (data) {
        showAdminAutoSaveToast(data.message || 'Saglabāts', false);
        clearTransientVideoFields();
        if (data.videos_html) {
          var videosList = document.getElementById('admin-videos-list');
          if (videosList) {
            videosList.innerHTML = data.videos_html;
            bindAdminVideoRowEvents();
          }
        }
      })
      .catch(function (err) {
        showAdminAutoSaveToast(err && err.message ? err.message : 'Neizdevās saglabāt', true);
      })
      .finally(function () {
        adminAutoSaveInFlight = false;
        if (adminAutoSaveQueued) {
          adminAutoSaveQueued = false;
          scheduleAdminAutoSave();
        }
      });
  }

  var scenePopover = null;
  var scenePopoverAnchor = null;
  var sceneBlurTimer = 0;
  var scenePopoverIgnoreBlur = false;

  function closeScenePopover() {
    if (scenePopover) {
      scenePopover.remove();
      scenePopover = null;
      scenePopoverAnchor = null;
    }
  }

  function openScenePopover(input) {
    if (!input) return;
    var card = input.closest('.admin-media-card');
    if (!card) return;
    closeScenePopover();
    scenePopoverAnchor = input;
    var scenes = currentScenesList();
    var pop = document.createElement('div');
    pop.className = 'admin-scene-popover';
    pop.setAttribute('role', 'listbox');
    scenes.forEach(function (scene) {
      var title = (scene.title || scene.id || '').trim();
      if (title === '') return;
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'admin-scene-popover-item';
      btn.setAttribute('role', 'option');
      btn.textContent = title;
      btn.addEventListener('mousedown', function (evt) {
        evt.preventDefault();
        scenePopoverIgnoreBlur = true;
      });
      btn.addEventListener('click', function () {
        applySceneTitleToCards(sceneChangeTargets(card), title);
        input.value = title;
        closeScenePopover();
        scenePopoverIgnoreBlur = false;
      });
      pop.appendChild(btn);
    });
    var custom = document.createElement('button');
    custom.type = 'button';
    custom.className = 'admin-scene-popover-item admin-scene-popover-item--custom';
    custom.setAttribute('role', 'option');
    custom.textContent = 'Jauns nosaukums…';
    custom.addEventListener('mousedown', function (evt) {
      evt.preventDefault();
      scenePopoverIgnoreBlur = true;
    });
    custom.addEventListener('click', function () {
      closeScenePopover();
      scenePopoverIgnoreBlur = false;
      input.focus();
      input.select();
    });
    pop.appendChild(custom);
    document.body.appendChild(pop);
    scenePopover = pop;
    var rect = input.getBoundingClientRect();
    var popRect = pop.getBoundingClientRect();
    var left = rect.left;
    if (left + popRect.width > window.innerWidth - 12) {
      left = window.innerWidth - popRect.width - 12;
    }
    pop.style.left = Math.max(8, left) + 'px';
    pop.style.top = rect.bottom + 4 + 'px';
    pop.style.minWidth = Math.max(rect.width + 28, 200) + 'px';
    filterScenePopover(input.value);
  }

  function filterScenePopover(query) {
    if (!scenePopover) return;
    var q = (query || '').trim().toLowerCase();
    scenePopover.querySelectorAll('.admin-scene-popover-item:not(.admin-scene-popover-item--custom)').forEach(function (btn) {
      var text = (btn.textContent || '').toLowerCase();
      btn.hidden = q !== '' && text.indexOf(q) === -1;
    });
  }

  function scheduleSceneCommit(input) {
    if (sceneBlurTimer) {
      clearTimeout(sceneBlurTimer);
    }
    sceneBlurTimer = window.setTimeout(function () {
      sceneBlurTimer = 0;
      if (scenePopoverIgnoreBlur) return;
      commitSceneInput(input);
      closeScenePopover();
    }, 120);
  }

  function commitSceneInput(input) {
    var card = input.closest('.admin-media-card');
    if (!card) return;
    applySceneTitleToCards(sceneChangeTargets(card), input.value);
  }

  function updateSceneCountsInEditor() {
    if (!scenesEditor || !imageGrid) return;
    var counts = {};
    imageGrid.querySelectorAll('.admin-media-card').forEach(function (card) {
      var sid = card.getAttribute('data-scene-id') || 'main';
      counts[sid] = (counts[sid] || 0) + 1;
    });
    var scenes = currentScenesList();
    scenes = scenes.map(function (s) {
      return {
        id: s.id,
        title: s.title,
        count: counts[s.id] || 0,
        sort: s.sort,
      };
    });
    scenesEditor.setAttribute('data-scenes', JSON.stringify(scenes));
    updateSceneMetaCounts(scenes);
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
        syncSceneInputs(list);
        scheduleAdminAutoSave();
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
          syncSceneInputs(next);
          scheduleAdminAutoSave();
        });
        row.appendChild(del);
      }
      scenesEditor.appendChild(row);
      if (focusId && scene.id === focusId) {
        titleInput.focus();
        titleInput.select();
      }
    });
  }

  function setupSceneDragOnce() {
    if (!scenesEditor || scenesEditor.dataset.dragBound === '1') {
      return;
    }
    scenesEditor.dataset.dragBound = '1';

    var dragRow = null;
    var dragGhost = null;
    var dragGrip = null;
    var dragRaf = 0;
    var dragX = 0;
    var dragY = 0;
    var dragInsertKey = '';

    function removeGhost() {
      if (dragGhost && dragGhost.parentNode) {
        dragGhost.parentNode.removeChild(dragGhost);
      }
      dragGhost = null;
    }

    function positionGhost() {
      if (!dragGhost) return;
      dragGhost.style.transform = 'translate(' + (dragX + 14) + 'px,' + (dragY + 14) + 'px)';
    }

    function finishDrag() {
      if (!dragRow) return;
      dragRow.classList.remove('dragging');
      dragRow.style.pointerEvents = '';
      dragRow = null;
      dragGrip = null;
      dragInsertKey = '';
      removeGhost();
      if (dragRaf) {
        cancelAnimationFrame(dragRaf);
        dragRaf = 0;
      }
      var list = readScenesFromDom();
      persistScenesJson(list);
      syncSceneInputs(list);
    }

    function moveDragRow() {
      if (!dragRow) return;
      dragRow.style.pointerEvents = 'none';
      var probeX = Math.min(window.innerWidth - 8, Math.max(8, dragX));
      var el = document.elementFromPoint(probeX, dragY);
      var target = el && el.closest ? el.closest('.admin-scene-row') : null;
      if (!target || target === dragRow || !scenesEditor.contains(target)) return;
      var rect = target.getBoundingClientRect();
      var after = dragY > rect.top + rect.height / 2;
      var targetId = target.getAttribute('data-id') || '';
      var insertKey = targetId + ':' + (after ? '1' : '0');
      if (insertKey === dragInsertKey) return;
      dragInsertKey = insertKey;
      if (after) {
        scenesEditor.insertBefore(dragRow, target.nextSibling);
      } else {
        scenesEditor.insertBefore(dragRow, target);
      }
    }

    function onDragFrame() {
      dragRaf = 0;
      positionGhost();
      moveDragRow();
    }

    function scheduleDragFrame() {
      if (dragRaf) return;
      dragRaf = requestAnimationFrame(onDragFrame);
    }

    scenesEditor.addEventListener('pointerdown', function (e) {
      var grip = e.target.closest ? e.target.closest('.admin-scene-drag') : null;
      if (!grip || e.button !== 0) return;
      dragRow = grip.closest('.admin-scene-row');
      if (!dragRow) return;
      e.preventDefault();
      dragGrip = grip;
      dragX = e.clientX;
      dragY = e.clientY;
      dragInsertKey = '';
      dragRow.classList.add('dragging');
      dragRow.style.pointerEvents = 'none';
      removeGhost();
      dragGhost = document.createElement('div');
      dragGhost.className = 'admin-scene-drag-ghost';
      var titleEl = dragRow.querySelector('.admin-scene-title-input');
      dragGhost.textContent = titleEl && titleEl.value ? titleEl.value : 'Galerijas sadaļa';
      document.body.appendChild(dragGhost);
      positionGhost();
      try {
        grip.setPointerCapture(e.pointerId);
      } catch (err) {
        /* ignore */
      }
    });

    scenesEditor.addEventListener('pointermove', function (e) {
      if (!dragRow || !dragGrip) return;
      dragX = e.clientX;
      dragY = e.clientY;
      scheduleDragFrame();
    });

    scenesEditor.addEventListener('pointerup', function (e) {
      if (!dragRow) return;
      if (dragGrip) {
        try {
          dragGrip.releasePointerCapture(e.pointerId);
        } catch (err) {
          /* ignore */
        }
      }
      finishDrag();
    });

    scenesEditor.addEventListener('pointercancel', function () {
      finishDrag();
    });
  }

  function moveSceneRow(fromIndex, delta) {
    var list = readScenesFromDom();
    var toIndex = fromIndex + delta;
    if (toIndex < 0 || toIndex >= list.length) return;
    var moved = list.splice(fromIndex, 1)[0];
    list.splice(toIndex, 0, moved);
    persistScenesJson(list);
    renderScenes(list);
    syncSceneInputs(list);
  }

  if (scenesEditor) {
    var initialScenes = readScenes();
    renderScenes(initialScenes);
    syncSceneInputs(initialScenes);
    setupSceneDragOnce();
    if (addSceneBtn) {
      addSceneBtn.addEventListener('click', function () {
        var scenes = readScenesFromDom();
        var id = 'scene_' + Math.random().toString(16).slice(2, 10);
        scenes.push({ id: id, title: 'Jauna sadaļa', count: 0 });
        persistScenesJson(scenes);
        renderScenes(scenes, id);
        syncSceneInputs(scenes);
        scheduleAdminAutoSave();
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
    var floatBar = document.getElementById('admin-scene-float-bar');
    var floatCount = document.getElementById('admin-scene-float-count');
    if (floatBar) {
      floatBar.hidden = n < 2;
    }
    if (floatCount) {
      floatCount.textContent = n === 1 ? '1 bilde atlasīta' : n + ' atlasītas';
    }
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
    });

    imageGrid.addEventListener(
      'blur',
      function (evt) {
        if (evt.target && evt.target.classList && evt.target.classList.contains('admin-scene-input')) {
          scheduleSceneCommit(evt.target);
        }
      },
      true
    );

    imageGrid.addEventListener('keydown', function (evt) {
      if (evt.target && evt.target.classList && evt.target.classList.contains('admin-scene-input') && evt.key === 'Enter') {
        evt.preventDefault();
        if (sceneBlurTimer) clearTimeout(sceneBlurTimer);
        commitSceneInput(evt.target);
        closeScenePopover();
        evt.target.blur();
      }
      if (evt.key === 'Escape') {
        closeScenePopover();
      }
    });

    imageGrid.addEventListener('focusin', function (evt) {
      if (evt.target && evt.target.classList && evt.target.classList.contains('admin-scene-input')) {
        evt.stopPropagation();
        openScenePopover(evt.target);
      }
    });

    imageGrid.addEventListener('input', function (evt) {
      if (evt.target && evt.target.classList && evt.target.classList.contains('admin-scene-input')) {
        filterScenePopover(evt.target.value);
      }
    });

    imageGrid.addEventListener('change', function (evt) {
      if (evt.target && evt.target.classList && evt.target.classList.contains('admin-scene-input')) {
        if (sceneBlurTimer) clearTimeout(sceneBlurTimer);
        commitSceneInput(evt.target);
        closeScenePopover();
      }
    });

    imageGrid.addEventListener(
      'mousedown',
      function (evt) {
        if (evt.target.closest('.admin-scene-pick, .admin-scene-popover')) {
          evt.stopPropagation();
        }
      },
      true
    );

    imageGrid.addEventListener('click', function (evt) {
      var openBtn = evt.target.closest('.admin-scene-open-btn');
      if (openBtn) {
        evt.preventDefault();
        evt.stopPropagation();
        var wrap = openBtn.closest('.admin-scene-pick');
        var inp = wrap ? wrap.querySelector('.admin-scene-input') : null;
        if (inp) {
          inp.focus();
          openScenePopover(inp);
        }
        return;
      }
      if (evt.target.closest('.admin-scene-open-btn, .admin-scene-input-wrap')) {
        evt.stopPropagation();
      }
      var shiftPick = evt.shiftKey;
      var thumb = evt.target.closest('.admin-media-thumb');
      if (thumb && !shiftPick) {
        return;
      }
      if (
        evt.target.closest(
          '.admin-cover-pick, .admin-fav-pick, .admin-scene-pick, .admin-scene-input, .admin-scene-open-btn, .admin-bulk-pick'
        )
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

  document.addEventListener('click', function (evt) {
    if (!scenePopover) return;
    if (evt.target.closest('.admin-scene-popover, .admin-scene-input, .admin-scene-open-btn')) {
      return;
    }
    closeScenePopover();
  });

  var sceneFilter = document.getElementById('admin-scene-filter');
  if (sceneFilter) {
    sceneFilter.addEventListener('click', function (evt) {
      var btn = evt.target && evt.target.closest ? evt.target.closest('.admin-scene-filter-btn') : null;
      if (!btn) return;
      applySceneFilter(btn.getAttribute('data-scene-filter') || 'all');
    });
  }

  function applySceneToCheckedPicks(title, clearAfter) {
    var picks = pickedImageCards();
    if (!picks.length) {
      window.alert('Atlasiet bildes: klikšķiniet uz bildēm vai izmantojiet «Atlasīt bildes» pie sadaļas.');
      return;
    }
    applySceneTitleToCards(picks, title);
    if (clearAfter) {
      clearAllPicks();
      updatePickCount();
      closeScenePopover();
      var floatInput = document.getElementById('admin-float-scene-input');
      if (floatInput) floatInput.value = '';
    }
  }

  var assignSceneBtn = document.getElementById('admin-assign-scene');
  var bulkSceneInput = document.getElementById('admin-bulk-scene-input');
  if (assignSceneBtn && bulkSceneInput && imageGrid) {
    assignSceneBtn.addEventListener('click', function () {
      applySceneToCheckedPicks(bulkSceneInput.value, false);
    });
    bulkSceneInput.addEventListener('keydown', function (evt) {
      if (evt.key === 'Enter') {
        evt.preventDefault();
        applySceneToCheckedPicks(bulkSceneInput.value, false);
      }
    });
  }

  var floatApplyBtn = document.getElementById('admin-float-apply-scene');
  var floatSceneInput = document.getElementById('admin-float-scene-input');
  if (floatApplyBtn && floatSceneInput && imageGrid) {
    floatApplyBtn.addEventListener('click', function () {
      applySceneToCheckedPicks(floatSceneInput.value, true);
    });
    floatSceneInput.addEventListener('keydown', function (evt) {
      if (evt.key === 'Enter') {
        evt.preventDefault();
        applySceneToCheckedPicks(floatSceneInput.value, true);
      }
    });
  }

  var floatClearBtn = document.getElementById('admin-float-clear-picks');
  if (floatClearBtn && imageGrid) {
    floatClearBtn.addEventListener('click', function () {
      clearAllPicks();
      updatePickCount();
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

  var createShareSetBtn = document.getElementById('admin-create-share-set');
  var shareSetTokens = document.getElementById('share_set_tokens');
  var createShareSetFlag = document.getElementById('create_share_set');
  var deliveryForm = document.getElementById('admin-delivery-form');
  if (createShareSetBtn && imageGrid && shareSetTokens && createShareSetFlag && deliveryForm) {
    createShareSetBtn.addEventListener('click', function () {
      var picks = imageGrid.querySelectorAll('.admin-image-pick:checked');
      if (!picks.length) {
        window.alert('Vispirms atlasiet bildes: klikšķiniet uz bildēm vai izmantojiet filtrus.');
        return;
      }
      var tokens = [];
      picks.forEach(function (cb) {
        tokens.push(cb.value);
      });
      shareSetTokens.value = tokens.join(',');
      createShareSetFlag.value = '1';
      deliveryForm.requestSubmit();
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
  var orderDirty = document.getElementById('image_order_dirty');
  if (list && input) {
    var dragEl = null;

    function markOrderDirty() {
      if (orderDirty) orderDirty.value = '1';
    }

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
            '.admin-cover-pick, .admin-media-thumb, .admin-scene-pick, .admin-scene-open-btn, .admin-bulk-pick, .admin-fav-pick, .admin-image-pick'
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
        markOrderDirty();
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
  if (lightbox) {
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
  }

  (function initAdminEditTabs() {
    var form = document.getElementById('admin-delivery-form');
    if (!form || !form.classList.contains('admin-form--tabbed')) return;
    var tabs = form.querySelectorAll('.admin-edit-tab[data-admin-tab]');
    var panels = form.querySelectorAll('[data-admin-tab-panel]');
    if (!tabs.length || !panels.length) return;

    var slug = form.getAttribute('data-admin-edit-slug') || '';
    var storageKey = 'efpic_admin_tab_' + slug;

    function activate(tabId, persist) {
      tabs.forEach(function (tab) {
        var on = tab.getAttribute('data-admin-tab') === tabId;
        tab.classList.toggle('is-active', on);
        tab.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      panels.forEach(function (panel) {
        var on = panel.id === tabId;
        if (on) {
          panel.removeAttribute('hidden');
        } else {
          panel.setAttribute('hidden', '');
        }
      });
      if (persist && tabId) {
        try {
          sessionStorage.setItem(storageKey, tabId);
        } catch (e) {}
      }
    }

    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        activate(tab.getAttribute('data-admin-tab'), true);
      });
    });

    var saved = '';
    try {
      saved = sessionStorage.getItem(storageKey) || '';
    } catch (e) {}
    if (saved && form.querySelector('#' + saved + '[data-admin-tab-panel]')) {
      activate(saved, false);
    }
  })();

  document.querySelectorAll('.admin-color-input').forEach(function (input) {
    var wrap = input.closest('.admin-color-control');
    if (!wrap) return;
    var swatch = wrap.querySelector('.admin-color-swatch');
    var code = wrap.querySelector('.admin-color-value');
    var sync = function () {
      if (swatch) swatch.style.backgroundColor = input.value;
      if (code) code.textContent = input.value;
    };
    input.addEventListener('input', sync);
    sync();
  });

  initAdminFormAutoSave();
})();
