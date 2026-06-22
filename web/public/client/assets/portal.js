(function () {
  'use strict';

  if (!document.body.classList.contains('page-portal')) return;

  var imageGrid = document.getElementById('portal-image-grid');
  var shareEditMode = null;

  function escapeHtml(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
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

  function showPortalToast(message, isError) {
    var toast = document.getElementById('portal-autosave-toast');
    if (!toast) {
      toast = document.createElement('div');
      toast.id = 'portal-autosave-toast';
      toast.className = 'admin-autosave-toast';
      toast.setAttribute('role', 'status');
      toast.setAttribute('aria-live', 'polite');
      document.body.appendChild(toast);
    }
    toast.textContent = message || (isError ? 'Neizdevās saglabāt' : 'Saglabāts');
    toast.classList.toggle('is-error', !!isError);
    toast.hidden = false;
    if (toast._hideTimer) clearTimeout(toast._hideTimer);
    toast._hideTimer = setTimeout(function () {
      toast.hidden = true;
    }, 2800);
  }

  function bindPortalLinkActions(root) {
    root = root || document;
    root.querySelectorAll('.admin-link-copy:not([data-bound])').forEach(function (btn) {
      btn.dataset.bound = '1';
      btn.addEventListener('click', function () {
        var url = btn.getAttribute('data-copy-url') || '';
        if (!url) return;
        copyTextToClipboard(url)
          .then(function () {
            showPortalToast('Saite nokopēta', false);
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
            showPortalToast('Saite nokopēta kopīgošanai', false);
          })
          .catch(function () {
            window.prompt('Kopē saiti:', url);
          });
      });
    });
  }

  function getPickInputs() {
    if (!imageGrid) return [];
    return Array.prototype.slice.call(
      imageGrid.querySelectorAll('.portal-share-pick, .admin-image-pick')
    );
  }

  function setCardPicked(card, picked) {
    if (!card) return;
    var cb = card.querySelector('.portal-share-pick, .admin-image-pick');
    if (cb) cb.checked = !!picked;
    card.classList.toggle('is-picked', !!picked);
  }

  function clearAllPicks() {
    if (!imageGrid) return;
    getPickInputs().forEach(function (cb) {
      setCardPicked(cb.closest('.admin-media-card'), false);
    });
  }

  function getPickedImageTokens() {
    var tokens = [];
    getPickInputs().forEach(function (cb) {
      if (cb.checked && cb.value) tokens.push(cb.value);
    });
    return tokens;
  }

  function hideSceneFloatBar() {
    var bar = document.getElementById('admin-scene-float-bar');
    if (bar) bar.hidden = true;
  }

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
    hideSceneFloatBar();
    var name = shareEditMode.label || 'Izlase';
    if (shareEditMode.isNew) {
      labelEl.innerHTML =
        'Jauna izlase: <strong>' + escapeHtml(name) + '</strong> — atzīmē bildes un spied Saglabāt.';
    } else {
      labelEl.innerHTML =
        'Labot izlasi: <strong>' + escapeHtml(name) + '</strong> — pievieno vai noņem bildes, tad Saglabāt.';
    }
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
  }

  function normalizeShareGuestToken(token) {
    var t = String(token || '').trim();
    return t && t !== 'null' ? t : '';
  }

  function postPortalShareRequest(extra) {
    var fd = new FormData();
    fd.set('portal_share_api', '1');
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

  function applyPortalShareAutosavePayload(data) {
    if (!data) return;
    if (data.share_sets_html) {
      var shareBody = document.getElementById('admin-share-sets-body');
      if (shareBody) {
        shareBody.innerHTML = data.share_sets_html;
        bindPortalShareSetEvents();
        bindPortalLinkActions(shareBody);
      }
    }
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
    updateShareEditBar();
    if (typeof window.efpicActivatePortalTab === 'function') {
      window.efpicActivatePortalTab('admin-tab-images', true);
    }
    window.setTimeout(function () {
      var panel = document.getElementById('admin-tab-images');
      if (panel) panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 60);
  }

  function exitShareEditMode(returnToShare) {
    shareEditMode = null;
    clearAllPicks();
    updateShareEditBar();
    if (returnToShare !== false && typeof window.efpicActivatePortalTab === 'function') {
      window.efpicActivatePortalTab('admin-tab-share', true);
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
    postPortalShareRequest(extra)
      .then(function (data) {
        showPortalToast(shareEditMode.isNew ? 'Izlase izveidota' : 'Izlase saglabāta', false);
        applyPortalShareAutosavePayload(data);
        exitShareEditMode();
      })
      .catch(function (err) {
        showPortalToast(err && err.message ? err.message : 'Kļūda', true);
      })
      .finally(function () {
        if (saveBtn) saveBtn.disabled = false;
      });
  }

  function bindPortalShareSetEvents() {
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
        postPortalShareRequest({ delete_share_token: gtok })
          .then(function (data) {
            showPortalToast('Izlase dzēsta', false);
            applyPortalShareAutosavePayload(data);
            if (shareEditMode && shareEditMode.guestToken === gtok) {
              exitShareEditMode();
            }
          })
          .catch(function (err) {
            showPortalToast(err && err.message ? err.message : 'Kļūda', true);
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
        postPortalShareRequest(extra)
          .then(function (data) {
            showPortalToast('Saglabāts', false);
            applyPortalShareAutosavePayload(data);
          })
          .catch(function (err) {
            showPortalToast(err && err.message ? err.message : 'Kļūda', true);
            cb.checked = !cb.checked;
          });
      });
    });
  }

  function initPortalShareSets() {
    bindPortalShareSetEvents();
    bindPortalLinkActions(document);
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

  function initPortalTabs() {
    var shell = document.querySelector('.admin-shell--portal');
    if (!shell) return;
    var tabs = shell.querySelectorAll('[data-admin-tab]');
    var panels = shell.querySelectorAll('[data-admin-tab-panel]');
    if (!tabs.length || !panels.length) return;

    var storageKey = 'efpic_portal_tab';

    function activate(tabId, persist) {
      tabs.forEach(function (tab) {
        var on = tab.getAttribute('data-admin-tab') === tabId;
        if (tab.classList.contains('admin-edit-tab')) {
          tab.classList.toggle('is-active', on);
        } else {
          tab.classList.toggle('active', on);
        }
        if (tab.getAttribute('role') === 'tab') {
          tab.setAttribute('aria-selected', on ? 'true' : 'false');
        }
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
    if (saved && shell.querySelector('#' + saved + '[data-admin-tab-panel]')) {
      activate(saved, false);
    }

    window.efpicActivatePortalTab = function (tabId, persist) {
      if (tabId) activate(tabId, persist !== false);
    };
  }

  function initPortalSidebar() {
    var hideBtn = document.getElementById('adminSidebarHide');
    var reopenBtn = document.getElementById('adminSidebarReopen');
    if (!hideBtn || !reopenBtn) return;

    var storageKey = 'efpic_portal_sidebar_hidden';

    function setSidebarHidden(hidden, persist) {
      document.body.classList.toggle('admin-sidebar-hidden', hidden);
      reopenBtn.hidden = !hidden;
      if (persist) {
        try {
          sessionStorage.setItem(storageKey, hidden ? '1' : '0');
        } catch (e) {}
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
    } catch (e) {}
    if (saved === '1') {
      setSidebarHidden(true, false);
    }
  }

  function initPortalColorInputs() {
    document.querySelectorAll('.portal-color-input, .admin-color-input').forEach(function (input) {
      var wrap = input.closest('.portal-color-control, .admin-color-control');
      if (!wrap) return;
      var swatch = wrap.querySelector('.portal-color-swatch, .admin-color-swatch');
      var code = wrap.querySelector('.portal-color-value, .admin-color-value');
      var sync = function () {
        if (swatch) swatch.style.backgroundColor = input.value;
        if (code) code.textContent = input.value;
      };
      input.addEventListener('input', sync);
      sync();
    });
  }

  if (imageGrid) {
    imageGrid.addEventListener('change', function (evt) {
      var cb = evt.target;
      if (!cb || (!cb.classList.contains('portal-share-pick') && !cb.classList.contains('admin-image-pick'))) {
        return;
      }
      setCardPicked(cb.closest('.admin-media-card'), cb.checked);
      if (shareEditMode) hideSceneFloatBar();
    });
  }

  initPortalTabs();
  initPortalSidebar();
  initPortalShareSets();
  initPortalColorInputs();
  initPortalLightbox();
  initPortalScenesEditor();
  bindPortalVideoRowEvents();
  initPortalConfirmForms();
  initPortalSlideshowOrderDrag();
  initPortalSlideshowAudioDrag();
  initPortalSlideshowRenderPoll();

  function syncPortalSlideshowOrderField() {
    var list = document.getElementById('portal-slideshow-order-list');
    var input = document.getElementById('slideshow-client-image-order');
    if (!list || !input) return;
    var tokens = [];
    list.querySelectorAll('li[data-token]').forEach(function (li) {
      var tok = li.getAttribute('data-token');
      if (tok) tokens.push(tok);
    });
    input.value = tokens.join(',');
  }

  function syncPortalSlideshowAudioOrderField() {
    var list = document.getElementById('portal-slideshow-audio-list');
    var input = document.getElementById('slideshow-client-audio-order');
    if (!list || !input) return;
    var files = [];
    list.querySelectorAll('.admin-slideshow-audio-item[data-audio-file]').forEach(function (li) {
      var file = li.getAttribute('data-audio-file');
      if (file) files.push(file);
    });
    input.value = files.join(',');
  }

  function insertPortalSlideshowGridDragItem(list, dragEl, clientX, clientY) {
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

  function initPortalSlideshowOrderDrag() {
    var list = document.getElementById('portal-slideshow-order-list');
    if (!list || list.dataset.bound === '1') return;
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
        syncPortalSlideshowOrderField();
      });
      li.addEventListener('dragover', function (e) {
        e.preventDefault();
        if (!dragEl || dragEl === li) return;
        insertPortalSlideshowGridDragItem(list, dragEl, e.clientX, e.clientY);
      });
      var checkbox = li.querySelector('input[type="checkbox"]');
      if (checkbox) {
        checkbox.addEventListener('change', function () {
          var card = li.querySelector('.admin-fav-card');
          if (card) {
            card.classList.toggle('is-selected', checkbox.checked);
          }
          syncPortalSlideshowOrderField();
        });
      }
    });

    var form = document.getElementById('portal-slideshow-form');
    if (form && form.dataset.orderSyncBound !== '1') {
      form.dataset.orderSyncBound = '1';
      form.addEventListener('submit', function () {
        syncPortalSlideshowOrderField();
        syncPortalSlideshowAudioOrderField();
      });
    }
  }

  function initPortalSlideshowAudioDrag() {
    var list = document.getElementById('portal-slideshow-audio-list');
    if (!list || list.dataset.bound === '1') return;
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
        syncPortalSlideshowAudioOrderField();
      });
      li.addEventListener('dragover', function (e) {
        e.preventDefault();
        if (!dragEl || dragEl === li) return;
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

  function applyPortalSlideshowRenderPayload(data) {
    if (!data || !data.render_label) return;
    var row = document.getElementById('slideshow-client-render-status');
    if (!row) return;
    var strong = row.querySelector('strong');
    if (!strong) return;
    if (data.render_status) {
      strong.setAttribute('data-render-status', data.render_status);
    }
    strong.textContent = data.render_label;
  }

  function initPortalSlideshowRenderPoll() {
    var row = document.getElementById('slideshow-client-render-status');
    if (!row) return;

    function shouldPoll() {
      var strong = row.querySelector('strong');
      var st = strong ? strong.getAttribute('data-render-status') : '';
      return st === 'queued' || st === 'processing';
    }

    function poll() {
      if (!shouldPoll()) return;
      fetch(window.location.pathname + window.location.search + (window.location.search ? '&' : '?') + 'poll=slideshow', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      })
        .then(function (res) {
          if (!res.ok) return null;
          return res.json();
        })
        .then(function (data) {
          if (!data || !data.ok) return;
          applyPortalSlideshowRenderPayload(data);
          if (data.render_status === 'ready' || data.render_status === 'failed') {
            window.location.reload();
          }
        })
        .catch(function () {});
    }

    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) poll();
    });
    window.setInterval(poll, 12000);
  }

  function initPortalConfirmForms() {
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

  function initPortalLightbox() {
    var lightbox = document.getElementById('portal-lightbox') || document.getElementById('admin-lightbox');
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
      var thumb = evt.target && evt.target.closest ? evt.target.closest('.admin-media-thumb, .admin-fav-preview') : null;
      if (thumb) {
        if (evt.shiftKey) return;
        if (thumb.closest('.portal-share-pick-label, .admin-bulk-pick')) return;
        evt.preventDefault();
        openLightbox(thumb.getAttribute('data-preview'));
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

  function initPortalScenesEditor() {
    var editor = document.getElementById('portal-scenes-editor');
    var hiddenInput = document.getElementById('portal_scenes_json');
    var form = document.getElementById('portal-scenes-form');
    if (!editor || !hiddenInput) return;

    function readScenes() {
      try {
        var raw = editor.getAttribute('data-scenes') || '[]';
        var data = JSON.parse(raw);
        return Array.isArray(data) ? data : [];
      } catch (e) {
        return [];
      }
    }

    function readScenesFromDom() {
      var scenes = [];
      editor.querySelectorAll('.admin-scene-row').forEach(function (row, index) {
        var input = row.querySelector('.admin-scene-title-input');
        var visibleCb = row.querySelector('.portal-scene-visible-cb');
        scenes.push({
          id: row.getAttribute('data-id') || row.dataset.id || '',
          title: input ? input.value : '',
          sort: index + 1,
          hidden_from_guests: visibleCb ? !visibleCb.checked : false,
        });
      });
      return scenes;
    }

    function persistScenesJson(scenes) {
      editor.setAttribute('data-scenes', JSON.stringify(scenes));
      hiddenInput.value = JSON.stringify(
        scenes.map(function (s, i) {
          return {
            id: s.id,
            title: s.title,
            sort: i + 1,
            hidden_from_guests: !!s.hidden_from_guests,
          };
        })
      );
    }

    function renderScenes(scenes) {
      editor.innerHTML = '';
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
        upBtn.disabled = index === 0;
        upBtn.addEventListener('click', function () {
          moveSceneRow(index, -1);
        });
        var downBtn = document.createElement('button');
        downBtn.type = 'button';
        downBtn.className = 'btn admin-scene-move';
        downBtn.textContent = '↓';
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
          persistScenesJson(readScenesFromDom());
        });

        var visibleLabel = document.createElement('label');
        visibleLabel.className = 'portal-scene-visible admin-toggle-field admin-toggle-field--inline';
        var visibleLabelText = document.createElement('span');
        visibleLabelText.className = 'admin-toggle-field__label';
        visibleLabelText.textContent = 'Rādīt publiskajā saitē';
        var visibleToggle = document.createElement('span');
        visibleToggle.className = 'admin-toggle';
        var visibleCb = document.createElement('input');
        visibleCb.type = 'checkbox';
        visibleCb.className = 'portal-scene-visible-cb';
        visibleCb.value = '1';
        visibleCb.checked = !scene.hidden_from_guests;
        visibleCb.addEventListener('change', function () {
          persistScenesJson(readScenesFromDom());
        });
        var visibleTrack = document.createElement('span');
        visibleTrack.className = 'admin-toggle__track';
        visibleTrack.setAttribute('aria-hidden', 'true');
        var visibleThumb = document.createElement('span');
        visibleThumb.className = 'admin-toggle__thumb';
        visibleTrack.appendChild(visibleThumb);
        visibleToggle.appendChild(visibleCb);
        visibleToggle.appendChild(visibleTrack);
        visibleLabel.appendChild(visibleLabelText);
        visibleLabel.appendChild(visibleToggle);

        row.appendChild(moveWrap);
        row.appendChild(titleInput);
        row.appendChild(visibleLabel);
        editor.appendChild(row);
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
    }

    function setupSceneDrag() {
      if (editor.dataset.dragBound === '1') return;
      editor.dataset.dragBound = '1';
      var dragRow = null;
      var dragGrip = null;

      editor.addEventListener('pointerdown', function (e) {
        var grip = e.target.closest ? e.target.closest('.admin-scene-drag') : null;
        if (!grip || e.button !== 0) return;
        dragRow = grip.closest('.admin-scene-row');
        if (!dragRow) return;
        e.preventDefault();
        dragGrip = grip;
        dragRow.classList.add('dragging');
        try {
          grip.setPointerCapture(e.pointerId);
        } catch (err) {}
      });

      function finishDrag() {
        if (!dragRow) return;
        dragRow.classList.remove('dragging');
        dragRow = null;
        dragGrip = null;
        persistScenesJson(readScenesFromDom());
        renderScenes(readScenesFromDom());
      }

      editor.addEventListener('pointermove', function (e) {
        if (!dragRow || !dragGrip) return;
        var probeX = Math.min(window.innerWidth - 8, Math.max(8, e.clientX));
        var el = document.elementFromPoint(probeX, e.clientY);
        var target = el && el.closest ? el.closest('.admin-scene-row') : null;
        if (!target || target === dragRow || !editor.contains(target)) return;
        var rect = target.getBoundingClientRect();
        var after = e.clientY > rect.top + rect.height / 2;
        if (after) {
          editor.insertBefore(dragRow, target.nextSibling);
        } else {
          editor.insertBefore(dragRow, target);
        }
      });

      editor.addEventListener('pointerup', finishDrag);
      editor.addEventListener('pointercancel', finishDrag);
    }

    renderScenes(readScenes());
    setupSceneDrag();

    if (form) {
      form.addEventListener('submit', function () {
        persistScenesJson(readScenesFromDom());
      });
    }
  }

  function bindPortalVideoRowEvents() {
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
        var form = document.getElementById('portal-videos-form');
        if (form) form.submit();
      });
    });

  }

  function initPortalGalleryDownload() {
    var portalDlBase = window.EFPIC_PORTAL_DL_URL || '';
    var zipProgressModal = document.getElementById('zipProgressModal');
    var zipFetchAbort = null;

    function setZipProgressUi(opts) {
      var spinner = document.getElementById('zipProgressSpinner');
      var titleEl = document.getElementById('zipProgressTitle');
      var hintEl = document.getElementById('zipProgressHint');
      var okBtn = document.getElementById('zipProgressOkBtn');
      if (titleEl && opts.title) titleEl.textContent = opts.title;
      if (hintEl && opts.hint !== undefined) hintEl.textContent = opts.hint;
      if (spinner) spinner.hidden = !opts.loading;
      if (okBtn) okBtn.hidden = opts.loading;
      if (zipProgressModal) {
        zipProgressModal.classList.toggle('is-success', !opts.loading && !!opts.success);
        zipProgressModal.setAttribute('aria-busy', opts.loading ? 'true' : 'false');
      }
    }

    function openZipProgressLoading(title, hint) {
      if (!zipProgressModal) return;
      zipProgressModal.hidden = false;
      document.body.style.overflow = 'hidden';
      setZipProgressUi({
        loading: true,
        success: false,
        title: title || 'Sagatavo lejupielādi…',
        hint: hint || 'Lūdzu uzgaidiet…',
      });
    }

    function showZipProgressError(hint) {
      setZipProgressUi({
        loading: false,
        success: false,
        title: 'Lejupielāde neizdevās',
        hint: hint || 'Neizdevās lejupielādēt.',
      });
    }

    function closeZipProgress() {
      if (zipFetchAbort) {
        zipFetchAbort.abort();
        zipFetchAbort = null;
      }
      if (!zipProgressModal) return;
      zipProgressModal.hidden = true;
      zipProgressModal.classList.remove('is-success');
      document.body.style.overflow = '';
    }

    function humanZipError(text) {
      if (!text) return 'Neizdevās lejupielādēt.';
      if (text.indexOf('<') >= 0 || text.indexOf('Internal Server Error') >= 0) {
        return 'Servera timeout — mēģini vēlreiz pēc mirkļa.';
      }
      if (text.length > 200) {
        return text.slice(0, 200) + '…';
      }
      return text;
    }

    function triggerBrowserDownload(url) {
      if (!url) return;
      window.location.assign(url);
    }

    function triggerBlobDownload(blob, filename) {
      var url = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = url;
      a.download = filename;
      a.rel = 'noopener';
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(function () {
        URL.revokeObjectURL(url);
      }, 2000);
    }

    function downloadServerZip(url, filename, hint) {
      if (!url) return;
      openZipProgressLoading('Sagatavo lejupielādi…', hint || 'Veido ZIP arhīvu…');
      zipFetchAbort = typeof AbortController !== 'undefined' ? new AbortController() : null;
      var fetchOpts = { credentials: 'same-origin' };
      if (zipFetchAbort) fetchOpts.signal = zipFetchAbort.signal;
      fetch(url, fetchOpts)
        .then(function (res) {
          if (!res.ok) {
            return res.text().then(function (text) {
              throw new Error(humanZipError(text));
            });
          }
          return res.blob();
        })
        .then(function (blob) {
          zipFetchAbort = null;
          triggerBlobDownload(blob, filename || 'galerija.zip');
          closeZipProgress();
        })
        .catch(function (err) {
          zipFetchAbort = null;
          if (err && err.name === 'AbortError') return;
          showZipProgressError(humanZipError(err && err.message ? err.message : ''));
        });
    }

    function startPortalZipDownload(size) {
      if (!portalDlBase) return;
      var downloadUrl = portalDlBase + '/download.zip?size=' + encodeURIComponent(size);
      var usesFolderZip =
        window.EFPIC_PORTAL_FAILIEM_FOLDER_ZIP === true || window.EFPIC_PORTAL_FAILIEM_FOLDER_ZIP === '1';

      if (usesFolderZip) {
        openZipProgressLoading('Sagatavo lejupielādi…', 'Sagatavo Failiem ZIP…');
        triggerBrowserDownload(downloadUrl);
        closeZipProgress();
        return;
      }

      openZipProgressLoading(
        'Sagatavo lejupielādi…',
        'Sagatavo ZIP ar visām bildēm. Lielai galerijai tas var aizņemt līdz 1–2 minūtēm.'
      );
      fetch(downloadUrl + '&prepare=1', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      })
        .then(function (res) {
          return res.json().then(function (data) {
            if (!res.ok || !data || !data.ok) {
              throw new Error((data && data.error) || 'Neizdevās sagatavot lejupielādi');
            }
            return data;
          });
        })
        .then(function (data) {
          if (data.mode === 'failiem' && data.url) {
            triggerBrowserDownload(data.url);
            closeZipProgress();
            return;
          }
          if (data.mode === 'stream_ready') {
            triggerBrowserDownload(downloadUrl + '&dl=1');
            closeZipProgress();
            return;
          }
          if (data.mode === 'server') {
            downloadServerZip(
              downloadUrl + '&dl=1',
              data.filename || 'galerija-' + size + '.zip',
              data.hint || 'Veido ZIP arhīvu…'
            );
            return;
          }
          throw new Error('Neatbalstīts lejupielādes režīms');
        })
        .catch(function (err) {
          showZipProgressError(humanZipError(err && err.message ? err.message : ''));
        });
    }

    document.querySelectorAll('[data-portal-dl-size]').forEach(function (btn) {
      btn.addEventListener('click', function (evt) {
        evt.preventDefault();
        startPortalZipDownload(btn.getAttribute('data-portal-dl-size') || 'web');
      });
    });

    document.querySelectorAll('[data-zip-progress-ok]').forEach(function (btn) {
      btn.addEventListener('click', closeZipProgress);
    });

    document.addEventListener('keydown', function (evt) {
      if (evt.key === 'Escape') closeZipProgress();
    });
  }

  initPortalGalleryDownload();
})();
