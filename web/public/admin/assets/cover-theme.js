(function () {
  'use strict';

  var SERIF = '"Cormorant Garamond", Georgia, "Times New Roman", serif';
  var SANS = 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif';

  var TITLE_SIZES = {
    mood: { sm: 'clamp(1.35rem, 3.2vw, 1.85rem)', md: 'clamp(1.6rem, 4.5vw, 2.4rem)', lg: 'clamp(2rem, 5.5vw, 3.2rem)' },
    standard: { sm: 'clamp(1.4rem, 4vw, 2rem)', md: 'clamp(1.75rem, 5vw, 3rem)', lg: 'clamp(2.2rem, 6vw, 3.6rem)' },
    split: { sm: 'clamp(1.5rem, 4vw, 2.2rem)', md: 'clamp(2rem, 5vw, 3.5rem)', lg: 'clamp(2.4rem, 6vw, 4rem)' },
  };

  var DATE_SIZES = {
    mood: { sm: '0.85rem', md: 'clamp(0.95rem, 2.5vw, 1.1rem)', lg: '1.25rem' },
    standard: { sm: '0.9rem', md: '1.05rem', lg: '1.2rem' },
  };

  function escapeHtml(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function parsePreviewData(el) {
    if (!el) return {};
    try {
      return JSON.parse(el.getAttribute('data-preview') || '{}');
    } catch (e) {
      return {};
    }
  }

  function formatDate(raw, fmt) {
    raw = String(raw || '').trim();
    if (!raw) return '';
    var parts = raw.split('-');
    if (parts.length < 3) return raw;
    var y = parseInt(parts[0], 10);
    var m = parseInt(parts[1], 10);
    var d = parseInt(parts[2], 10);
    if (!y || !m || !d) return raw;
    if (fmt === 'iso') return raw;
    if (fmt === 'en') {
      var months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
      return months[m - 1] + ' ' + d + ', ' + y;
    }
    var lvMonths = ['janvāris', 'februāris', 'marts', 'aprīlis', 'maijs', 'jūnijs', 'jūlijs', 'augusts', 'septembris', 'oktobris', 'novembris', 'decembris'];
    return d + '. ' + lvMonths[m - 1] + ' ' + y;
  }

  function titleSizeCss(theme, layout, key) {
    if (layout === 'half-left' || layout === 'half-right') {
      return TITLE_SIZES.split[key] || TITLE_SIZES.split.md;
    }
    if (theme === 'efpic-mood') {
      return TITLE_SIZES.mood[key] || TITLE_SIZES.mood.md;
    }
    return TITLE_SIZES.standard[key] || TITLE_SIZES.standard.md;
  }

  function dateSizeCss(theme, key) {
    var bucket = theme === 'efpic-mood' ? DATE_SIZES.mood : DATE_SIZES.standard;
    return bucket[key] || bucket.md;
  }

  function fontCss(key) {
    return key === 'sans' ? SANS : SERIF;
  }

  function readColorInput(name) {
    var el = document.querySelector('input[name="' + name + '"]');
    return el && el.value ? el.value : '';
  }

  function readCoverImageUrl() {
    var checked = document.querySelector('input[name="cover_image_token"]:checked');
    if (!checked) return '';
    var card = checked.closest('.admin-media-card');
    if (!card) return '';
    var thumb = card.querySelector('.admin-media-thumb');
    return thumb ? thumb.getAttribute('data-preview') || '' : '';
  }

  function collectState(root, previewEl, base) {
    var themeSelect = document.getElementById('admin-gallery-theme-select');
    var theme = themeSelect ? themeSelect.value : (base.theme || 'efpic-modern');
    var layoutInput = root.querySelector('input[name="cover_layout"]:checked');
    var layout = layoutInput ? layoutInput.value : (base.layout || 'right');
    var fx = document.getElementById('cover_focal_x');
    var fy = document.getElementById('cover_focal_y');
    var nameInput = document.querySelector('input[name="name"]');
    var dateInput = document.querySelector('input[name="event_date"]');
    var fontSel = document.getElementById('mood_font_family');
    var dateFmtSel = document.getElementById('mood_date_format');
    var titleSel = document.getElementById('mood_title_font_size');
    var dateSizeSel = document.getElementById('mood_date_font_size');
    var dateRaw = dateInput ? dateInput.value : (base.dateRaw || '');
    var dateFormat = dateFmtSel ? dateFmtSel.value : (base.dateFormat || 'lv');
    var fontKey = fontSel ? fontSel.value : (base.fontFamily || 'serif');
    var titleKey = titleSel ? titleSel.value : (base.titleSize || 'md');
    var dateKey = dateSizeSel ? dateSizeSel.value : (base.dateSize || 'md');
    var heroAccent = readColorInput('hero_accent_color') || base.heroAccent || '#9a9578';
    var coverUrl = readCoverImageUrl() || base.coverUrl || '';

    return {
      theme: theme,
      layout: layout,
      name: nameInput ? nameInput.value : (base.name || 'Galerija'),
      dateRaw: dateRaw,
      dateFormatted: formatDate(dateRaw, dateFormat),
      byline: base.byline || 'GALLERY',
      coverUrl: coverUrl,
      heroAccent: heroAccent,
      focalX: fx ? parseFloat(fx.value) || 50 : (base.focalX || 50),
      focalY: fy ? parseFloat(fy.value) || 50 : (base.focalY || 50),
      fontFamily: fontKey,
      dateFormat: dateFormat,
      titleSize: titleKey,
      dateSize: dateKey,
    };
  }

  function photoHtml(url, focalX, focalY) {
    if (!url) return '';
    return '<img class="gallery-intro-photo" src="' + escapeHtml(url) + '" alt="" decoding="async"'
      + ' style="object-position:' + focalX + '% ' + focalY + '%;">';
  }

  function introStyle(state) {
    var font = fontCss(state.fontFamily);
    var title = titleSizeCss(state.theme, state.layout, state.titleSize);
    var date = dateSizeCss(state.theme, state.dateSize);
    return '--hero-accent:' + state.heroAccent + ';--hero-text:#1a1a1a;--intro-font:' + font
      + ';--intro-title-size:' + title + ';--intro-date-size:' + date + ';';
  }

  function renderSplit(state, layout) {
    var text = '<div class="gallery-intro-split-text">'
      + '<div class="gallery-intro-split-top">'
      + '<p class="gallery-intro-byline">' + escapeHtml(state.byline) + '</p>';
    if (state.dateFormatted) {
      text += '<p class="gallery-intro-date gallery-intro-date--split">' + escapeHtml(state.dateFormatted) + '</p>';
    }
    text += '</div><h1 class="gallery-intro-title">' + escapeHtml(state.name) + '</h1></div>';
    var media = '<div class="gallery-intro-split-media">' + photoHtml(state.coverUrl, state.focalX, state.focalY) + '</div>';
    var inner = layout === 'half-left' ? media + text : text + media;
    return '<section class="gallery-intro gallery-intro--split gallery-intro--layout-' + layout + '" style="' + introStyle(state) + '">'
      + '<div class="gallery-intro-split">' + inner + '</div></section>';
  }

  function renderMood(state) {
    var html = '<section class="gallery-intro gallery-intro--mood" style="' + introStyle(state) + '">';
    html += '<p class="gallery-intro-byline">' + escapeHtml(state.byline) + '</p>';
    html += '<div class="gallery-intro-blob-wrap"><div class="gallery-intro-blob">';
    html += photoHtml(state.coverUrl, state.focalX, state.focalY);
    html += '</div></div><div class="gallery-intro-footer">';
    html += '<h1 class="gallery-intro-title">' + escapeHtml(state.name) + '</h1>';
    if (state.dateFormatted) {
      html += '<p class="gallery-intro-date">' + escapeHtml(state.dateFormatted) + '</p>';
    }
    html += '</div></section>';
    return html;
  }

  function renderStandard(state) {
    var layout = state.layout || 'right';
    var html = '<section class="gallery-intro gallery-intro--layout-' + layout + '" style="' + introStyle(state) + '">';
    html += '<p class="gallery-intro-byline">' + escapeHtml(state.byline) + '</p>';
    html += '<div class="gallery-intro-head"><figure class="gallery-intro-figure">';
    html += photoHtml(state.coverUrl, state.focalX, state.focalY);
    if (state.dateFormatted && layout !== 'full') {
      html += '<figcaption class="gallery-intro-date">' + escapeHtml(state.dateFormatted) + '</figcaption>';
    }
    html += '</figure></div>';
    if (layout === 'full' && state.dateFormatted) {
      html += '<p class="gallery-intro-date gallery-intro-date--below">' + escapeHtml(state.dateFormatted) + '</p>';
    }
    html += '<h1 class="gallery-intro-title">' + escapeHtml(state.name) + '</h1></section>';
    return html;
  }

  function renderLivePreview(previewEl, state) {
    if (!previewEl) return;
    var html = '';
    if (state.theme === 'efpic-mood') {
      html = renderMood(state);
    } else if (state.layout === 'half-left' || state.layout === 'half-right') {
      html = renderSplit(state, state.layout);
    } else {
      html = renderStandard(state);
    }
    previewEl.innerHTML = html;
  }

  function initCoverTheme() {
    var root = document.getElementById('admin-cover-theme');
    if (!root) return;

    var themeSelect = document.getElementById('admin-gallery-theme-select');
    var layoutBlock = document.getElementById('admin-cover-layout-block');
    var moodNote = document.getElementById('admin-cover-layout-mood-note');
    var crop = document.getElementById('admin-cover-crop');
    var frame = document.getElementById('admin-cover-crop-frame');
    var cropImg = document.getElementById('admin-cover-crop-img');
    var previewEl = document.getElementById('admin-cover-live-preview');
    var fx = document.getElementById('cover_focal_x');
    var fy = document.getElementById('cover_focal_y');
    var base = parsePreviewData(previewEl);
    var layoutInputs = root.querySelectorAll('input[name="cover_layout"]');

    function isMood() {
      return themeSelect && themeSelect.value === 'efpic-mood';
    }

    function hasCoverImage() {
      var url = readCoverImageUrl() || (cropImg && cropImg.getAttribute('src')) || base.coverUrl;
      return !!url;
    }

    function getCoverUrl() {
      return readCoverImageUrl() || (cropImg && cropImg.getAttribute('src')) || base.coverUrl || '';
    }

    function syncThemePanels() {
      var mood = isMood();
      if (layoutBlock) {
        layoutBlock.classList.toggle('is-disabled', mood);
        layoutBlock.querySelectorAll('input[name="cover_layout"]').forEach(function (el) {
          el.disabled = mood;
        });
      }
      if (moodNote) {
        moodNote.hidden = !mood;
      }
      if (crop) {
        crop.hidden = mood || !hasCoverImage();
      }
      root.dataset.theme = themeSelect ? themeSelect.value : '';
    }

    function getSelectedLayout() {
      var checked = root.querySelector('input[name="cover_layout"]:checked');
      return checked ? checked.value : 'right';
    }

    function layoutAspect(layout) {
      if (layout === 'half-left' || layout === 'half-right') return '3 / 4';
      if (layout === 'full') return '2 / 1';
      return '16 / 10';
    }

    function updateCropAspect() {
      if (!frame) return;
      var layout = getSelectedLayout();
      frame.style.aspectRatio = layoutAspect(layout);
      if (crop) crop.dataset.layout = layout;
    }

    function applyFocal() {
      if (!cropImg || !fx || !fy) return;
      cropImg.style.objectPosition = fx.value + '% ' + fy.value + '%';
    }

    function refreshPreview() {
      var state = collectState(root, previewEl, base);
      state.coverUrl = getCoverUrl();
      if (cropImg && state.coverUrl && cropImg.getAttribute('src') !== state.coverUrl) {
        cropImg.setAttribute('src', state.coverUrl);
      }
      renderLivePreview(previewEl, state);
      syncThemePanels();
      updateCropAspect();
    }

    function bindLiveInput(sel) {
      document.querySelectorAll(sel).forEach(function (el) {
        el.addEventListener('input', refreshPreview);
        el.addEventListener('change', refreshPreview);
      });
    }

    if (themeSelect) {
      themeSelect.addEventListener('change', function () {
        syncThemePanels();
        refreshPreview();
      });
    }

    layoutInputs.forEach(function (input) {
      input.addEventListener('change', function () {
        updateCropAspect();
        refreshPreview();
      });
    });

    bindLiveInput('input[name="name"], input[name="event_date"], input[name="hero_accent_color"]');
    bindLiveInput('#mood_font_family, #mood_date_format, #mood_title_font_size, #mood_date_font_size');

    document.addEventListener('change', function (evt) {
      if (evt.target && evt.target.matches && evt.target.matches('input[name="cover_image_token"]')) {
        refreshPreview();
      }
    });

    syncThemePanels();
    updateCropAspect();
    applyFocal();
    refreshPreview();

    if (!frame || !cropImg || !fx || !fy) return;

    var dragging = false;
    var startX = 0;
    var startY = 0;
    var startFx = 50;
    var startFy = 50;

    frame.addEventListener('pointerdown', function (evt) {
      if (isMood() || !hasCoverImage()) return;
      dragging = true;
      startX = evt.clientX;
      startY = evt.clientY;
      startFx = parseFloat(fx.value) || 50;
      startFy = parseFloat(fy.value) || 50;
      frame.setPointerCapture(evt.pointerId);
      frame.classList.add('is-dragging');
      evt.preventDefault();
    });

    frame.addEventListener('pointermove', function (evt) {
      if (!dragging) return;
      var rect = frame.getBoundingClientRect();
      if (!rect.width || !rect.height) return;
      var dx = ((evt.clientX - startX) / rect.width) * 100;
      var dy = ((evt.clientY - startY) / rect.height) * 100;
      var nx = Math.max(0, Math.min(100, startFx - dx));
      var ny = Math.max(0, Math.min(100, startFy - dy));
      fx.value = nx.toFixed(2);
      fy.value = ny.toFixed(2);
      applyFocal();
      refreshPreview();
    });

    function endDrag(evt) {
      if (!dragging) return;
      dragging = false;
      frame.classList.remove('is-dragging');
      try {
        frame.releasePointerCapture(evt.pointerId);
      } catch (e) {
        /* ignore */
      }
    }

    frame.addEventListener('pointerup', endDrag);
    frame.addEventListener('pointercancel', endDrag);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCoverTheme);
  } else {
    initCoverTheme();
  }
})();
