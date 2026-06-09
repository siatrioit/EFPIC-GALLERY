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
    var changed = false;
    cards.forEach(function (card) {
      var hidden = card.querySelector('.admin-scene-id');
      var prev = hidden ? hidden.value : card.getAttribute('data-scene-id') || 'main';
      if (prev !== scene.id) {
        changed = true;
      }
      setCardScene(card, scene.id, scene.title);
    });
    if (!changed) return;
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

  function syncSlideshowOrderField() {
    document.querySelectorAll('.admin-slideshow-order-list').forEach(function (list) {
      var listId = list.getAttribute('id') || '';
      var input = listId ? document.getElementById(listId.replace('-order-list', '-image-order')) : null;
      if (!input) {
        var wrap = list.closest('.admin-slideshow-order');
        input = wrap ? wrap.querySelector('input[type="hidden"][name*="_image_order"]') : null;
      }
      if (!input) return;
      var tokens = [];
      list.querySelectorAll('li[data-token]').forEach(function (li) {
        var tok = li.getAttribute('data-token');
        if (tok) tokens.push(tok);
      });
      input.value = tokens.join(',');
    });
  }

  function markSlideshowOrderDirty() {
    var el = document.getElementById('slideshow-admin-image-order-dirty');
    if (el) el.value = '1';
  }

  function syncSlideshowAudioOrderField() {
    document.querySelectorAll('.admin-slideshow-audio-list').forEach(function (list) {
      var listId = list.getAttribute('id') || '';
      var input = listId ? document.getElementById(listId.replace('-audio-list', '-audio-order')) : null;
      if (!input) {
        var wrap = list.closest('.admin-slideshow-audio');
        input = wrap ? wrap.querySelector('input[type="hidden"][name*="_audio_order"]') : null;
      }
      if (!input) return;
      var files = [];
      list.querySelectorAll('.admin-slideshow-audio-item[data-audio-file]').forEach(function (li) {
        var file = li.getAttribute('data-audio-file');
        if (file) files.push(file);
      });
      input.value = files.join(',');
    });
  }

  function isSlideshowEnabledField(name) {
    if (!name) return false;
    if (name === 'slideshow_client_enabled' || name === 'slideshow_admin_enabled') return true;
    return name.indexOf('slideshow_item_') === 0 && name.slice(-8) === '_enabled';
  }

  function syncReadySlideshowPayload() {
    var input = document.getElementById('ready-slideshow-payload');
    if (!input) return;
    var out = [];
    document.querySelectorAll('.admin-slideshow-ready[data-slideshow-id]').forEach(function (article) {
      var id = article.getAttribute('data-slideshow-id') || '';
      if (!id) return;
      var owner = id === 'client' ? 'client' : 'admin';
      var cb = article.querySelector('.admin-slideshow-ready__toggle input[type="checkbox"]');
      var titleEl = article.querySelector('input[name$="_section_title"]');
      var placementEl = article.querySelector('select[name$="_section_placement"]');
      var afterEl = article.querySelector('select[name$="_section_after_scene"]');
      out.push({
        id: id,
        owner: owner,
        enabled: cb ? !!cb.checked : false,
        section_title: titleEl ? titleEl.value : '',
        section_placement: placementEl ? placementEl.value : 'top',
        section_after_scene: afterEl ? afterEl.value : '',
      });
    });
    try {
      input.value = JSON.stringify(out);
    } catch (e) {
      input.value = '';
    }
  }

  function markFavoritesDirty() {
    var el = document.getElementById('favorites_dirty');
    if (el) el.value = '1';
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
    form.querySelectorAll('input[type="file"][name$="_mp3[]"]').forEach(function (mp3) {
      mp3.value = '';
    });
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
      var t = evt.target;
      if (t && t.name && t.name.indexOf('image_fav_admin[') === 0) {
        markFavoritesDirty();
      }
      if (t && t.name && isSlideshowEnabledField(t.name)) {
        runAdminAutoSave();
        return;
      }
      if (shouldAutoSaveTarget(t)) {
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

    form.querySelectorAll('input[type="file"][name$="_mp3[]"]').forEach(function (slideshowMp3) {
      if (slideshowMp3.dataset.autosaveBound === '1') return;
      slideshowMp3.dataset.autosaveBound = '1';
      slideshowMp3.addEventListener('change', function () {
        if (slideshowMp3.files && slideshowMp3.files.length) {
          runAdminAutoSave();
        }
      });
    });

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
            applyAdminShareAutosavePayload(data);
          })
          .catch(function (err) {
            showAdminAutoSaveToast(err && err.message ? err.message : 'Kļūda', true);
          })
          .finally(function () {
            adminAutoSaveInFlight = false;
          });
      });
    }

    form.addEventListener('submit', function () {
      persistScenesBeforeSave();
      syncImageOrderField();
      syncSlideshowOrderField();
      syncSlideshowAudioOrderField();
      syncReadySlideshowPayload();
      var orderDirty = document.getElementById('image_order_dirty');
      if (orderDirty) orderDirty.value = '1';
    });
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
    syncSlideshowOrderField();
    syncSlideshowAudioOrderField();
    syncReadySlideshowPayload();
    var fd = new FormData(form);
    form.querySelectorAll('.admin-no-autosave').forEach(function (el) {
      if (el.name) fd.delete(el.name);
    });

    var toast = document.getElementById('admin-autosave-toast');
    if (toast) {
      toast.classList.add('is-saving');
      toast.textContent = 'Saglabā…';
      toast.hidden = false;
    }

    fd.set('autosave', '1');
    fd.delete('sync_now');
    fd.delete('create_share_set');
    fd.delete('share_set_tokens');
    fd.delete('slideshow_draft_generate_video');
    fd.forEach(function (_value, key) {
      if (key.indexOf('_remove_video') !== -1) fd.delete(key);
    });

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
        var favDirty = document.getElementById('favorites_dirty');
        if (favDirty) favDirty.value = '0';
        if (data.videos_html) {
          var videosList = document.getElementById('admin-videos-list');
          if (videosList) {
            videosList.innerHTML = data.videos_html;
            bindAdminVideoRowEvents();
          }
        }
        applyAdminGalleryLinksPayload(data);
        applyAdminShareAutosavePayload(data);
        applyAdminSlideshowRenderPayload(data);
        applyAdminFavoritesAutosavePayload(data);
        applyAdminReadySlideshowAutosavePayload(data);
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

  function applyAdminGalleryLinksPayload(data) {
    if (!data || !data.public_link_html || !data.gallery_token) return;
    var row = document.getElementById('admin-public-link-row');
    if (!row) return;
    var current = row.getAttribute('data-gallery-token') || '';
    if (data.gallery_token === current && row.querySelector('.admin-link-row')) {
      return;
    }
    row.setAttribute('data-gallery-token', data.gallery_token);
    var strong = document.createElement('strong');
    strong.textContent = 'Publiska saite:';
    row.replaceChildren(strong);
    var tmp = document.createElement('span');
    tmp.innerHTML = data.public_link_html;
    while (tmp.firstChild) {
      row.appendChild(tmp.firstChild);
    }
    bindAdminLinkActions(row);
  }

  function applyAdminShareAutosavePayload(data) {
    if (!data) return;
    if (data.share_sets_html) {
      var shareBody = document.getElementById('admin-share-sets-body');
      if (shareBody) {
        shareBody.innerHTML = data.share_sets_html;
        bindAdminShareSetEvents();
        bindAdminLinkActions(shareBody);
      }
    }
    if (data.share_index && imageGrid) {
      updateShareIndexOnCards(data.share_index, data.share_counts);
    }
  }

  function copyTextToClipboard(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function (resolve, reject) {
      try {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        resolve();
      } catch (e) {
        reject(e);
      }
    });
  }

  function bindAdminLinkActions(root) {
    root = root || document;
    root.querySelectorAll('.admin-link-copy:not([data-bound])').forEach(function (btn) {
      btn.dataset.bound = '1';
      btn.addEventListener('click', function () {
        var url = btn.getAttribute('data-copy-url') || '';
        if (!url) return;
        copyTextToClipboard(url)
          .then(function () {
            showAdminAutoSaveToast('Saite nokopēta', false);
          })
          .catch(function () {
            window.prompt('Kopē saiti:', url);
          });
      });
    });
    root.querySelectorAll('.admin-link-share:not([data-bound])').forEach(function (btn) {
      btn.dataset.bound = '1';
      btn.addEventListener('click', function () {
        var url = btn.getAttribute('data-share-url') || '';
        if (!url) return;
        if (navigator.share) {
          navigator.share({ url: url, title: document.title }).catch(function () {});
          return;
        }
        copyTextToClipboard(url)
          .then(function () {
            showAdminAutoSaveToast('Saite nokopēta kopīgošanai', false);
          })
          .catch(function () {
            window.prompt('Kopē saiti:', url);
          });
      });
    });
  }

  function updateShareIndexOnCards(tokens, counts) {
    if (!imageGrid) return;
    var set = {};
    (tokens || []).forEach(function (t) {
      if (t) set[t] = true;
    });
    imageGrid.querySelectorAll('.admin-media-card').forEach(function (card) {
      var tok = card.getAttribute('data-token');
      var shareCount = tok && counts && counts[tok] ? parseInt(counts[tok], 10) : 0;
      if (!shareCount && tok && set[tok]) {
        shareCount = 1;
      }
      var inShare = shareCount > 0;
      card.setAttribute('data-in-share', inShare ? '1' : '0');
      card.setAttribute('data-share-count', String(shareCount));
      var badge = card.querySelector('.admin-share-badge');
      var metaRow = card.querySelector('.admin-media-card__row--meta');
      if (inShare) {
        if (!badge && metaRow) {
          badge = document.createElement('span');
          badge.className = 'admin-share-badge';
          metaRow.insertBefore(badge, metaRow.firstChild);
        }
        if (badge) {
          badge.title = 'Iekļauta ' + shareCount + ' kopīgojamā izlasē';
          badge.textContent = '⎘ Kopīgots · ' + shareCount;
        }
      } else if (badge) {
        badge.remove();
      }
      if (activeSceneFilter !== 'all') {
        card.classList.toggle('is-filtered-out', !cardMatchesFilter(card));
      }
    });
    var inShareBtn = document.querySelector('.admin-scene-filter-btn[data-scene-filter="in-share"]');
    if (inShareBtn) {
      var label = inShareBtn.textContent.replace(/\s*\(\d+\)\s*$/, '').trim();
      inShareBtn.textContent = label + ' (' + (tokens ? tokens.length : 0) + ')';
    }
  }

  function getPickedImageTokens() {
    if (!imageGrid) return [];
    var tokens = [];
    imageGrid.querySelectorAll('.admin-image-pick:checked').forEach(function (cb) {
      if (cb.value) tokens.push(cb.value);
    });
    return tokens;
  }

  function normalizeShareGuestToken(token) {
    var t = String(token || '').trim();
    return t && t !== 'null' ? t : '';
  }

  function postAdminShareRequest(extra) {
    var fd = new FormData();
    fd.set('admin_share_api', '1');
    Object.keys(extra || {}).forEach(function (key) {
      fd.set(key, extra[key]);
    });
    return fetch(window.location.pathname + window.location.search, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    }).then(function (res) {
      var ct = (res.headers.get('content-type') || '').toLowerCase();
      if (ct.indexOf('application/json') === -1) {
        throw new Error('Servera atbilde nav derīga.');
      }
      return res.json().then(function (data) {
        if (!res.ok || !data || !data.ok) {
          throw new Error((data && data.error) || 'Neizdevās saglabāt');
        }
        return data;
      });
    });
  }

  var shareEditMode = null;

  function updateShareEditBar() {
    var bar = document.getElementById('admin-share-edit-bar');
    var labelEl = document.getElementById('admin-share-edit-bar-label');
    if (!bar || !labelEl) return;
    if (!shareEditMode) {
      bar.hidden = true;
      bar.classList.remove('is-floating');
      document.body.classList.remove('admin-share-edit-active');
      if (imageGrid) {
        imageGrid.querySelectorAll('.admin-media-card').forEach(function (card) {
          card.classList.remove('is-share-edit-pick');
        });
      }
      return;
    }
    bar.hidden = false;
    bar.classList.add('is-floating');
    document.body.classList.add('admin-share-edit-active');
    var name = shareEditMode.label || 'Izlase';
    if (shareEditMode.isNew) {
      labelEl.innerHTML = 'Jauna izlase: <strong>' + escapeHtml(name) + '</strong> — atzīmē bildes un spied Saglabāt.';
    } else {
      labelEl.innerHTML = 'Labot izlasi: <strong>' + escapeHtml(name) + '</strong> — pievieno vai noņem bildes, tad Saglabāt.';
    }
    updatePickCount();
  }

  function escapeHtml(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function selectShareEditTokens(tokens) {
    if (!imageGrid) return;
    var set = {};
    (tokens || []).forEach(function (t) {
      if (t) set[t] = true;
    });
    clearAllPicks();
    imageGrid.querySelectorAll('.admin-media-card').forEach(function (card) {
      var tok = card.getAttribute('data-token');
      if (tok && set[tok]) {
        setCardPicked(card, true);
        card.classList.add('is-share-edit-pick');
      } else {
        card.classList.remove('is-share-edit-pick');
      }
    });
    updatePickCount();
  }

  function enterShareEditMode(opts) {
    var guestToken = normalizeShareGuestToken(opts.guestToken);
    shareEditMode = {
      guestToken: guestToken || null,
      label: opts.label || '',
      includeVideos: !!opts.includeVideos,
      isNew: !guestToken,
    };
    selectShareEditTokens(opts.tokens || []);
    applySceneFilter('all');
    updateShareEditBar();
    if (typeof window.efpicActivateAdminTab === 'function') {
      window.efpicActivateAdminTab('admin-tab-images', true);
    }
    window.setTimeout(function () {
      var panel = document.getElementById('admin-tab-images');
      if (panel) {
        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    }, 60);
  }

  function exitShareEditMode(returnToShare) {
    shareEditMode = null;
    clearAllPicks();
    updateShareEditBar();
    updatePickCount();
    if (returnToShare !== false && typeof window.efpicActivateAdminTab === 'function') {
      window.efpicActivateAdminTab('admin-tab-share', true);
    }
  }

  function saveShareEditMode() {
    if (!shareEditMode) return;
    var tokens = getPickedImageTokens();
    if (!tokens.length) {
      window.alert('Izvēlies vismaz vienu bildi izlasei.');
      return;
    }
    var extra;
    if (shareEditMode.isNew) {
      extra = {
        share_action: 'create',
        share_set_label: shareEditMode.label,
        share_set_tokens: tokens.join(','),
      };
      if (shareEditMode.includeVideos) {
        extra.share_include_videos = '1';
      }
    } else {
      var guestToken = normalizeShareGuestToken(shareEditMode.guestToken);
      if (!guestToken) {
        window.alert('Nav atrasts izlases žetons — aizver un atver «Labot izlasi» vēlreiz.');
        return;
      }
      extra = {
        share_action: 'replace',
        share_guest_token: guestToken,
        share_set_label: shareEditMode.label,
        share_set_tokens: tokens.join(','),
      };
      if (shareEditMode.includeVideos) {
        extra.share_include_videos = '1';
      }
    }
    var saveBtn = document.getElementById('admin-share-edit-save');
    if (saveBtn) saveBtn.disabled = true;
    postAdminShareRequest(extra)
      .then(function (data) {
        showAdminAutoSaveToast(shareEditMode.isNew ? 'Izlase izveidota' : 'Izlase saglabāta', false);
        applyAdminShareAutosavePayload(data);
        exitShareEditMode();
      })
      .catch(function (err) {
        showAdminAutoSaveToast(err && err.message ? err.message : 'Kļūda', true);
      })
      .finally(function () {
        if (saveBtn) saveBtn.disabled = false;
      });
  }

  function bindAdminShareSetEvents() {
    var startNewBtn = document.getElementById('admin-share-start-new');
    if (startNewBtn && startNewBtn.dataset.bound !== '1') {
      startNewBtn.dataset.bound = '1';
      startNewBtn.addEventListener('click', function () {
        var labelEl = document.getElementById('admin-share-new-label');
        var label = labelEl ? labelEl.value.trim() : '';
        if (!label) {
          window.alert('Ievadi kam paredzēta izlase (piem. Dekoratore Anna).');
          if (labelEl) labelEl.focus();
          return;
        }
        var videosEl = document.getElementById('admin-share-new-videos');
        enterShareEditMode({
          label: label,
          includeVideos: !!(videosEl && videosEl.checked),
          tokens: [],
        });
      });
    }

    document.querySelectorAll('.admin-share-edit').forEach(function (btn) {
      if (btn.dataset.bound === '1') return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', function () {
        var item = btn.closest('.admin-share-set-item');
        if (!item) return;
        var raw = item.getAttribute('data-share-tokens') || '';
        var tokens = raw ? raw.split(',').filter(Boolean) : [];
        var guestToken = normalizeShareGuestToken(
          item.getAttribute('data-guest-token') || btn.getAttribute('data-guest-token')
        );
        enterShareEditMode({
          guestToken: guestToken || null,
          label: item.getAttribute('data-share-label') || '',
          includeVideos: item.getAttribute('data-share-videos') === '1',
          tokens: tokens,
        });
      });
    });

    document.querySelectorAll('.admin-share-delete').forEach(function (btn) {
      if (btn.dataset.bound === '1') return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', function () {
        var gtok = btn.getAttribute('data-guest-token');
        if (!gtok) return;
        if (!window.confirm('Dzēst šo kopīgojamo izlasi?')) return;
        btn.disabled = true;
        postAdminShareRequest({ delete_share_token: gtok })
          .then(function (data) {
            showAdminAutoSaveToast('Izlase dzēsta', false);
            applyAdminShareAutosavePayload(data);
            if (shareEditMode && shareEditMode.guestToken === gtok) {
              exitShareEditMode();
            }
          })
          .catch(function (err) {
            showAdminAutoSaveToast(err && err.message ? err.message : 'Kļūda', true);
          })
          .finally(function () {
            btn.disabled = false;
          });
      });
    });

    document.querySelectorAll('.admin-share-videos-cb').forEach(function (cb) {
      if (cb.dataset.bound === '1') return;
      cb.dataset.bound = '1';
      cb.addEventListener('change', function () {
        var gtok = cb.getAttribute('data-guest-token');
        if (!gtok) return;
        var extra = {
          share_action: 'update_videos',
          share_guest_token: gtok,
        };
        if (cb.checked) {
          extra.share_include_videos = '1';
        }
        postAdminShareRequest(extra)
          .then(function (data) {
            showAdminAutoSaveToast('Saglabāts', false);
            applyAdminShareAutosavePayload(data);
          })
          .catch(function (err) {
            showAdminAutoSaveToast(err && err.message ? err.message : 'Kļūda', true);
            cb.checked = !cb.checked;
          });
      });
    });
  }

  function initAdminShareSets() {
    bindAdminShareSetEvents();
    bindAdminLinkActions(document);
    var saveBtn = document.getElementById('admin-share-edit-save');
    var cancelBtn = document.getElementById('admin-share-edit-cancel');
    if (saveBtn && saveBtn.dataset.bound !== '1') {
      saveBtn.dataset.bound = '1';
      saveBtn.addEventListener('click', saveShareEditMode);
    }
    if (cancelBtn && cancelBtn.dataset.bound !== '1') {
      cancelBtn.dataset.bound = '1';
      cancelBtn.addEventListener('click', function () {
        exitShareEditMode(true);
      });
    }
  }

  var scenePopover = null;
  var scenePopoverAnchor = null;
  var sceneBlurTimer = 0;
  var scenePopoverIgnoreBlur = false;
  var scenePopoverScrollHandler = null;

  function unbindScenePopoverScrollClose() {
    if (scenePopoverScrollHandler) {
      window.removeEventListener('scroll', scenePopoverScrollHandler, true);
      scenePopoverScrollHandler = null;
    }
  }

  function bindScenePopoverScrollClose() {
    unbindScenePopoverScrollClose();
    scenePopoverScrollHandler = function (evt) {
      if (!scenePopover) return;
      var target = evt.target;
      if (target && target.nodeType === 3 && target.parentNode) {
        target = target.parentNode;
      }
      if (target && target.closest && target.closest('.admin-scene-popover')) {
        return;
      }
      closeScenePopover();
    };
    window.addEventListener('scroll', scenePopoverScrollHandler, true);
  }

  function positionScenePopover(pop, input) {
    var rect = input.getBoundingClientRect();
    var popRect = pop.getBoundingClientRect();
    var left = rect.left;
    if (left + popRect.width > window.innerWidth - 12) {
      left = window.innerWidth - popRect.width - 12;
    }
    pop.style.left = Math.max(8, left) + 'px';
    pop.style.minWidth = Math.max(rect.width + 28, 200) + 'px';
    var belowTop = rect.bottom + 4;
    var aboveTop = rect.top - popRect.height - 4;
    if (belowTop + popRect.height > window.innerHeight - 12 && aboveTop > 8) {
      pop.style.top = Math.max(8, aboveTop) + 'px';
    } else {
      pop.style.top = belowTop + 'px';
    }
  }

  function closeScenePopover() {
    if (scenePopover) {
      scenePopover.remove();
      scenePopover = null;
      scenePopoverAnchor = null;
    }
    unbindScenePopoverScrollClose();
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
    document.body.appendChild(pop);
    scenePopover = pop;
    positionScenePopover(pop, input);
    bindScenePopoverScrollClose();
    filterScenePopover('');
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
    if (fid === 'in-share') {
      return card.getAttribute('data-in-share') === '1';
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
      floatBar.hidden = n < 2 || !!shareEditMode;
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
        markFavoritesDirty();
        scheduleAdminAutoSave();
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

  initAdminShareSets();

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

    window.efpicActivateAdminTab = function (tabId, persist) {
      if (tabId) activate(tabId, persist !== false);
    };
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

  function initAdminConfirmForms() {
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
      if (form.dataset.confirmBound === '1') return;
      form.dataset.confirmBound = '1';
      form.addEventListener('submit', function (evt) {
        var msg = form.getAttribute('data-confirm') || '';
        if (msg && !window.confirm(msg)) {
          evt.preventDefault();
        }
      });
    });
  }

  function applyAdminReadySlideshowAutosavePayload(data) {
    if (data && data.slideshow_meta_diag && window.console && console.info) {
      console.info('[slideshow meta.json]', data.slideshow_meta_diag);
    }
    var items = (data && data.ready_slideshow_state) || [];
    if (!items.length) return;
    items.forEach(function (item) {
      if (!item || !item.id) return;
      var article = document.querySelector('.admin-slideshow-ready[data-slideshow-id="' + item.id + '"]');
      if (!article) return;
      var cb = article.querySelector('.admin-slideshow-ready__toggle input[type="checkbox"]');
      if (cb) cb.checked = !!item.enabled;
      var status = article.querySelector('.admin-slideshow-public-status');
      if (status && item.public_status) status.textContent = item.public_status;
    });
  }

  function applyAdminFavoritesAutosavePayload(data) {
    if (!data) return;
    var updated = false;
    if (data.favorites_tab_grid_html) {
      var tabGrid = document.getElementById('admin-favorites-slideshow-grid');
      if (tabGrid) {
        tabGrid.innerHTML = data.favorites_tab_grid_html;
        updated = true;
      }
    }
    if (data.composer_favorites_panel_html) {
      document.querySelectorAll('[data-composer-favorites-panel]').forEach(function (panel) {
        panel.innerHTML = data.composer_favorites_panel_html;
      });
      updated = true;
    }
    if (updated) {
      initAdminSlideshowOrderDrag();
    }
  }

  function applyAdminSlideshowRenderPayload(data) {
    var items = (data && data.slideshow_items) || (data && data.items) || [];
    if (!items.length && data && data.render_label) {
      items = [{
        id: '',
        render_status: data.render_status,
        render_label: data.render_label,
      }];
    }
    items.forEach(function (item) {
      if (!item || !item.id) return;
      var row = document.getElementById('slideshow-item-' + item.id + '-render-status');
      if (!row) return;
      var strong = row.querySelector('strong');
      if (!strong) return;
      if (item.render_status) {
        strong.setAttribute('data-render-status', item.render_status);
      }
      strong.textContent = item.render_label || '';
    });
  }

  function initAdminGalleryLinksPoll() {
    var form = document.getElementById('admin-delivery-form');
    if (!form || !adminFormIsEditDelivery()) return;
    var slug = form.getAttribute('data-admin-edit-slug');
    var row = document.getElementById('admin-public-link-row');
    if (!slug || !row) return;

    function poll() {
      fetch('delivery_edit.php?slug=' + encodeURIComponent(slug) + '&poll=links', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      })
        .then(function (res) {
          if (!res.ok) return null;
          return res.json();
        })
        .then(function (data) {
          if (!data || !data.ok) return;
          applyAdminGalleryLinksPayload(data);
          applyAdminShareAutosavePayload(data);
        })
        .catch(function () {});
    }

    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) poll();
    });
    window.setInterval(poll, 15000);
  }

  function initAdminSlideshowRenderPoll() {
    var form = document.getElementById('admin-delivery-form');
    if (!form || !adminFormIsEditDelivery()) return;
    var slug = form.getAttribute('data-admin-edit-slug');
    if (!slug) return;

    function shouldPoll() {
      var active = false;
      document.querySelectorAll('[id^="slideshow-item-"][id$="-render-status"] strong').forEach(function (strong) {
        var st = strong.getAttribute('data-render-status') || '';
        if (st === 'queued' || st === 'processing') active = true;
      });
      return active;
    }

    function poll() {
      if (!shouldPoll()) return;
      fetch('delivery_edit.php?slug=' + encodeURIComponent(slug) + '&poll=slideshow', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      })
        .then(function (res) {
          if (!res.ok) return null;
          return res.json();
        })
        .then(function (data) {
          if (!data || !data.ok) return;
          applyAdminSlideshowRenderPayload(data);
        })
        .catch(function () {});
    }

    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) poll();
    });
    window.setInterval(poll, 12000);
  }

  function initAdminSidebar() {
    var hideBtn = document.getElementById('adminSidebarHide');
    var reopenBtn = document.getElementById('adminSidebarReopen');
    if (!hideBtn || !reopenBtn) return;

    var storageKey = 'efpic_admin_sidebar_hidden';

    function setSidebarHidden(hidden, persist) {
      document.body.classList.toggle('admin-sidebar-hidden', hidden);
      reopenBtn.hidden = !hidden;
      if (persist) {
        try {
          sessionStorage.setItem(storageKey, hidden ? '1' : '0');
        } catch (e) {
          /* ignore */
        }
      }
    }

    hideBtn.addEventListener('click', function () {
      setSidebarHidden(true, true);
    });

    reopenBtn.addEventListener('click', function () {
      setSidebarHidden(false, true);
    });

    var saved = '';
    try {
      saved = sessionStorage.getItem(storageKey) || '';
    } catch (e) {
      /* ignore */
    }
    if (saved === '1') {
      setSidebarHidden(true, false);
    } else {
      setSidebarHidden(false, false);
    }
  }

  initAdminConfirmForms();
  initAdminRegeneratePublicLink();
  initAdminBackfillDimensions();
  initAdminGalleryLinksPoll();
  initAdminSlideshowRenderPoll();
  initAdminSlideshowOrderDrag();
  initAdminSlideshowAudioDrag();
  initAdminSlideshowSourceToggle();
  initAdminSidebar();
  initAdminRenderQueueMonitor();

  function initAdminRenderQueueMonitor() {
    var panel = document.getElementById('admin-render-queue-panel');
    if (!panel || panel.dataset.bound === '1') {
      return;
    }
    panel.dataset.bound = '1';

    function esc(s) {
      return String(s || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
    }

    function renderRows(jobs) {
      var tbody = document.getElementById('admin-render-queue-body');
      var empty = document.getElementById('admin-render-queue-empty');
      if (!tbody) return;
      if (!jobs || !jobs.length) {
        tbody.innerHTML = '';
        if (empty) empty.hidden = false;
        return;
      }
      if (empty) empty.hidden = true;
      tbody.innerHTML = jobs
        .map(function (job) {
          var slug = job.slug || '';
          var galleryCell = slug
            ? '<a href="delivery_edit.php?slug=' + esc(encodeURIComponent(slug)) + '">' + esc(job.gallery_name || slug) + '</a>'
            : esc(job.gallery_name || '');
          var actions = '';
          if (job.can_retry) {
            actions +=
              '<button type="submit" class="btn admin-btn-sm" name="render_queue_action" value="retry:' +
              esc(job.id) +
              '">Retry</button> ';
          }
          if (job.can_cancel) {
            actions +=
              '<button type="submit" class="btn admin-btn-sm admin-btn-danger" name="render_queue_action" value="cancel:' +
              esc(job.id) +
              '" onclick="return confirm(\'Atcelt render job?\');">Atcelt</button>';
          }
          var err = job.error
            ? '<br><span class="muted admin-render-error">' + esc(job.error) + '</span>'
            : '';
          return (
            '<tr data-job-id="' +
            esc(job.id) +
            '"><td>' +
            galleryCell +
            '</td><td>' +
            esc(job.owner_label) +
            '</td><td><span class="admin-render-status admin-render-status--' +
            esc(job.status) +
            '">' +
            esc(job.status_label) +
            '</span>' +
            err +
            '</td><td>' +
            esc(String(job.attempt || 1)) +
            '/' +
            esc(String(job.max_attempts || 3)) +
            '</td><td>' +
            esc(job.updated_ago) +
            '</td><td class="admin-render-actions">' +
            actions +
            '</td></tr>'
          );
        })
        .join('');
    }

    function applyPayload(data) {
      if (!data) return;
      var worker = data.worker || {};
      var stats = data.stats || {};
      var workerEl = panel.querySelector('[data-render-worker-status]');
      if (workerEl) {
        workerEl.setAttribute('data-render-worker-status', worker.status || 'offline');
        workerEl.textContent = worker.status_label || workerEl.textContent;
      }
      var workerWrap = panel.querySelector('.admin-render-worker');
      if (workerWrap) {
        workerWrap.className = 'admin-render-worker admin-render-worker--' + (worker.status || 'offline');
      }
      ['queued', 'processing', 'failed'].forEach(function (key) {
        var el = panel.querySelector('[data-stat="' + key + '"]');
        if (el) el.textContent = String(stats[key] || 0);
      });
      renderRows(data.jobs || []);
    }

    function poll() {
      fetch('settings.php?poll=render_queue', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      })
        .then(function (res) {
          if (!res.ok) return null;
          return res.json();
        })
        .then(function (data) {
          if (!data || !data.ok) return;
          applyPayload(data);
        })
        .catch(function () {});
    }

    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) poll();
    });
    window.setInterval(poll, 15000);
  }

  function insertSlideshowGridDragItem(list, dragEl, clientX, clientY) {
    var items = Array.prototype.slice.call(
      list.querySelectorAll('.admin-slideshow-order-item:not(.dragging)')
    );
    var closest = null;
    var closestDist = Infinity;
    items.forEach(function (item) {
      if (item === dragEl) {
        return;
      }
      var box = item.getBoundingClientRect();
      var cx = box.left + box.width / 2;
      var cy = box.top + box.height / 2;
      var dist = Math.hypot(clientX - cx, clientY - cy);
      if (dist < closestDist) {
        closestDist = dist;
        closest = item;
      }
    });
    if (!closest) {
      if (list.lastElementChild !== dragEl) {
        list.appendChild(dragEl);
      }
      return;
    }
    var box = closest.getBoundingClientRect();
    var after = clientX > box.left + box.width / 2;
    if (after) {
      list.insertBefore(dragEl, closest.nextSibling);
    } else {
      list.insertBefore(dragEl, closest);
    }
  }

  function bindAdminSlideshowOrderList(list) {
    if (!list || list.dataset.bound === '1') {
      return;
    }
    list.dataset.bound = '1';
    var dragEl = null;

    list.querySelectorAll('.admin-slideshow-order-item').forEach(function (li) {
      li.addEventListener('dragstart', function () {
        dragEl = li;
        li.classList.add('dragging');
      });
      li.addEventListener('dragend', function () {
        li.classList.remove('dragging');
        dragEl = null;
        syncSlideshowOrderField();
        markSlideshowOrderDirty();
        scheduleAdminAutoSave();
      });
      li.addEventListener('dragover', function (e) {
        e.preventDefault();
        if (!dragEl || dragEl === li) {
          return;
        }
        insertSlideshowGridDragItem(list, dragEl, e.clientX, e.clientY);
      });
      var checkbox = li.querySelector('input[type="checkbox"]');
      if (checkbox) {
        checkbox.addEventListener('change', function () {
          var card = li.querySelector('.admin-fav-card');
          if (card) {
            card.classList.toggle('is-selected', checkbox.checked);
          }
          if (checkbox.name && checkbox.name.indexOf('image_fav_admin[') === 0) {
            markFavoritesDirty();
          }
          syncSlideshowOrderField();
          markSlideshowOrderDirty();
          scheduleAdminAutoSave();
        });
      }
    });
  }

  function initAdminSlideshowOrderDrag() {
    document.querySelectorAll('.admin-slideshow-order-list').forEach(bindAdminSlideshowOrderList);
  }

  function bindAdminSlideshowAudioList(list) {
    if (!list || list.dataset.bound === '1') {
      return;
    }
    list.dataset.bound = '1';
    var dragEl = null;

    list.querySelectorAll('.admin-slideshow-audio-item').forEach(function (li) {
      li.addEventListener('dragstart', function () {
        dragEl = li;
        li.classList.add('dragging');
      });
      li.addEventListener('dragend', function () {
        li.classList.remove('dragging');
        dragEl = null;
        syncSlideshowAudioOrderField();
        scheduleAdminAutoSave();
      });
      li.addEventListener('dragover', function (e) {
        e.preventDefault();
        if (!dragEl || dragEl === li) {
          return;
        }
        var rect = li.getBoundingClientRect();
        var after = e.clientY > rect.top + rect.height / 2;
        if (after) {
          list.insertBefore(dragEl, li.nextSibling);
        } else {
          list.insertBefore(dragEl, li);
        }
      });
     });
  }

  function initAdminSlideshowAudioDrag() {
    document.querySelectorAll('.admin-slideshow-audio-list').forEach(bindAdminSlideshowAudioList);
  }

  function initAdminSlideshowSourceToggle() {
    document.querySelectorAll('[data-slideshow-source]').forEach(function (wrap) {
      if (wrap.dataset.bound === '1') return;
      wrap.dataset.bound = '1';
      var hidden = wrap.querySelector('[data-slideshow-source-input]');
      var toggles = wrap.querySelectorAll('[data-slideshow-source-value]');

      function syncFromValue(value) {
        if (!value) value = 'favorites';
        if (hidden) hidden.value = value;
        toggles.forEach(function (input) {
          input.checked = input.getAttribute('data-slideshow-source-value') === value;
        });
        wrap.querySelectorAll('.admin-slideshow-source__panel').forEach(function (panel) {
          panel.classList.toggle('is-visible', panel.classList.contains('admin-slideshow-source__panel--' + value));
        });
      }

      toggles.forEach(function (input) {
        input.addEventListener('change', function () {
          var value = input.getAttribute('data-slideshow-source-value') || 'favorites';
          if (!input.checked) {
            input.checked = true;
            return;
          }
          syncFromValue(value);
          scheduleAdminAutoSave();
        });
      });

      syncFromValue(hidden ? hidden.value : 'favorites');
    });
  }

  function adminDimsMissingCount() {
    var missingEl = document.getElementById('admin-dims-missing');
    if (!missingEl) return 0;
    return parseInt(missingEl.textContent, 10) || 0;
  }

  function adminUpdateDimsDebugUi(stats) {
    var countEl = document.getElementById('admin-dims-count');
    var missingEl = document.getElementById('admin-dims-missing');
    var btn = document.getElementById('admin-backfill-dimensions');
    if (countEl && stats.with_dims !== undefined && stats.total !== undefined) {
      countEl.textContent = stats.with_dims + ' / ' + stats.total;
    }
    if (missingEl && stats.missing !== undefined) {
      missingEl.textContent = String(stats.missing);
    }
    if (btn && (stats.missing || 0) <= 0) {
      btn.hidden = true;
    }
  }

  function adminFetchBackfillDimensions(all) {
    var fd = new FormData();
    fd.set('backfill_dimensions_api', '1');
    if (all) {
      fd.set('backfill_all', '1');
    }
    return fetch(window.location.pathname + window.location.search, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    }).then(function (res) {
      return res.json().then(function (data) {
        if (!res.ok || !data || !data.ok) {
          throw new Error((data && data.error) || 'Neizdevās ievākt izmērus');
        }
        return data;
      });
    });
  }

  var adminDimsBackfillInFlight = false;

  function runAdminBackfillDimensions(opts) {
    opts = opts || {};
    var btn = document.getElementById('admin-backfill-dimensions');
    var statusEl = document.getElementById('admin-dims-status');
    if (adminDimsBackfillInFlight) {
      return Promise.resolve();
    }
    if (!opts.force && adminDimsMissingCount() <= 0) {
      return Promise.resolve();
    }

    adminDimsBackfillInFlight = true;
    var label = btn ? btn.textContent : '';
    if (btn) {
      btn.disabled = true;
      btn.textContent = 'Ievācu…';
    }
    if (statusEl) {
      statusEl.hidden = false;
      statusEl.textContent = opts.silent
        ? 'Ievācu izmērus fonā no Failiem…'
        : 'Savienojos ar Failiem…';
    }
    if (opts.silent) {
      showAdminAutoSaveToast('Ievācu bildes izmērus fonā…', false);
    }

    function step(all) {
      return adminFetchBackfillDimensions(all).then(function (data) {
        var stats = data.stats || {};
        adminUpdateDimsDebugUi(stats);
        if ((stats.missing || 0) > 0 && (data.updated || 0) > 0) {
          if (statusEl) {
            statusEl.textContent = 'Ievākti ' + (stats.with_dims || 0) + ' / ' + (stats.total || 0) + '…';
          }
          return step(false);
        }
        return data;
      });
    }

    return step(!!opts.all)
      .then(function (data) {
        var stats = data.stats || {};
        var msg = 'Izmēri: ' + (stats.with_dims || 0) + ' / ' + (stats.total || 0) + '.';
        if ((stats.missing || 0) > 0) {
          msg += ' Palika ' + stats.missing + '.';
          if ((data.updated || 0) === 0) {
            msg += ' Neizdevās nolasīt — pārbaudi Failiem piekļuvi serverī.';
          }
          showAdminAutoSaveToast(msg, (data.updated || 0) === 0);
        } else {
          msg += ' Viss gatavs.';
          showAdminAutoSaveToast(msg, false);
        }
        if (statusEl) {
          statusEl.textContent = msg;
        }
      })
      .catch(function (err) {
        var errMsg = (err && err.message) ? err.message : 'Kļūda';
        if (statusEl) {
          statusEl.textContent = errMsg;
        }
        showAdminAutoSaveToast(errMsg, true);
      })
      .finally(function () {
        adminDimsBackfillInFlight = false;
        if (btn) {
          btn.disabled = false;
          btn.textContent = label;
        }
      });
  }

  function initAdminBackfillDimensions() {
    var btn = document.getElementById('admin-backfill-dimensions');
    var form = document.getElementById('admin-delivery-form');
    if (btn && btn.dataset.bound !== '1') {
      btn.dataset.bound = '1';
      btn.addEventListener('click', function () {
        runAdminBackfillDimensions({ all: true });
      });
    }
    if (form && form.getAttribute('data-dims-after-sync') === '1' && adminDimsMissingCount() > 0) {
      setTimeout(function () {
        runAdminBackfillDimensions({ all: true, silent: true });
      }, 400);
    }
  }

  function initAdminRegeneratePublicLink() {
    var btn = document.getElementById('admin-regenerate-public-link');
    var form = document.getElementById('admin-delivery-form');
    if (!btn || !form || btn.dataset.bound === '1') return;
    btn.dataset.bound = '1';
    btn.addEventListener('click', function () {
      var msg = btn.getAttribute('data-confirm') || '';
      if (msg && !window.confirm(msg)) return;
      form.querySelectorAll('input[data-regen-temp]').forEach(function (el) {
        el.remove();
      });
      ['regenerate_public_link', 'confirm_regenerate'].forEach(function (name) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = '1';
        input.setAttribute('data-regen-temp', '1');
        form.appendChild(input);
      });
      form.submit();
    });
  }
})();
