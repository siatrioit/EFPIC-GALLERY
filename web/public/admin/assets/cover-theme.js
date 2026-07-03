(function () {
  'use strict';

  var DEFAULT_FONTS = {
    cormorant: "'Cormorant Garamond', Georgia, 'Times New Roman', serif",
    montserrat: "'Montserrat', system-ui, -apple-system, 'Segoe UI', sans-serif",
  };

  var BYLINE_SIZES = {
    sm: '0.65rem',
    md: 'clamp(0.75rem, 2vw, 0.95rem)',
    lg: '1.05rem',
  };

  var TITLE_SIZES = {
    mood: { sm: 'clamp(1.15rem, 3vw, 1.55rem)', md: 'clamp(1.65rem, 4.8vw, 2.5rem)', lg: 'clamp(2.35rem, 6.5vw, 3.75rem)' },
    standard: { sm: 'clamp(1.25rem, 3.5vw, 1.75rem)', md: 'clamp(1.85rem, 5.5vw, 3.15rem)', lg: 'clamp(2.6rem, 7.5vw, 4.25rem)' },
    split: { sm: 'clamp(1.25rem, 3.5vw, 1.75rem)', md: 'clamp(1.85rem, 5.5vw, 3.15rem)', lg: 'clamp(2.6rem, 7.5vw, 4.25rem)' },
  };

  var DATE_SIZES = {
    mood: { sm: '0.78rem', md: 'clamp(0.95rem, 2.5vw, 1.1rem)', lg: '1.35rem' },
    standard: { sm: '0.8rem', md: '1.05rem', lg: '1.35rem' },
  };

  var PREVIEW_DISPLAY_H = 400;
  var previewFocusLock = null;

  function lockPreviewFocus(el) {
    if (!el || typeof el.focus !== 'function') {
      previewFocusLock = null;
      return;
    }
    previewFocusLock = {
      el: el,
      start: typeof el.selectionStart === 'number' ? el.selectionStart : null,
      end: typeof el.selectionEnd === 'number' ? el.selectionEnd : null,
    };
  }

  function restorePreviewFocus() {
    var lock = previewFocusLock;
    if (!lock || !lock.el || !document.body.contains(lock.el)) {
      return;
    }
    lock.el.focus();
    if (lock.start !== null && lock.end !== null && typeof lock.el.setSelectionRange === 'function') {
      try {
        lock.el.setSelectionRange(lock.start, lock.end);
      } catch (e) {
        /* ignore */
      }
    }
  }

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

  function readFontMap(root) {
    if (!root) return DEFAULT_FONTS;
    try {
      return JSON.parse(root.getAttribute('data-fonts') || '{}');
    } catch (e) {
      return DEFAULT_FONTS;
    }
  }

  function readFontGroupMap(root) {
    if (!root) return {};
    try {
      return JSON.parse(root.getAttribute('data-font-groups') || '{}');
    } catch (e) {
      return {};
    }
  }

  function fontGroup(key, groupMap) {
    return (groupMap && groupMap[key]) || 'serif';
  }

  function titleWeightCss(key, groupMap) {
    return fontGroup(key, groupMap) === 'sans' ? '500' : '400';
  }

  function titleTrackingCss(key, groupMap) {
    return fontGroup(key, groupMap) === 'sans' ? '0.06em' : '0.03em';
  }

  function titleTrackingCapsCss(key, groupMap) {
    return fontGroup(key, groupMap) === 'sans' ? '0.1em' : '0.12em';
  }

  function safeStyleVar(value) {
    return String(value || '').replace(/"/g, "'");
  }

  function fontCss(key, fontMap) {
    var family = '';
    if (fontMap && fontMap[key]) family = fontMap[key];
    else if (key === 'sans' || key === 'montserrat') family = DEFAULT_FONTS.montserrat;
    else family = DEFAULT_FONTS.cormorant;
    return safeStyleVar(family);
  }

  function bylineSizeCss(key) {
    return BYLINE_SIZES[key] || BYLINE_SIZES.md;
  }

  function sectionClass(state, extra) {
    var cls = extra || '';
    if (state.allCaps) cls += ' gallery-intro--all-caps';
    return cls;
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

  function getCoverUrl(cropImg, base) {
    return readCoverImageUrl()
      || (cropImg && cropImg.getAttribute('src')) 
      || (base && base.coverUrl)
      || '';
  }

  function hasCoverImage(cropImg, base) {
    return getCoverUrl(cropImg, base) !== '';
  }

  function readPreviewAssets(root) {
    if (!root) {
      return { clientCss: '', fontUrls: [] };
    }
    var fontUrls = [];
    try {
      fontUrls = JSON.parse(root.getAttribute('data-font-urls') || '[]');
    } catch (e) {
      fontUrls = [];
    }
    return {
      clientCss: root.getAttribute('data-client-css') || '',
      fontUrls: fontUrls,
    };
  }

  function buildPreviewDocument(html, assets) {
    var links = (assets.fontUrls || []).map(function (url) {
      return '<link rel="stylesheet" href="' + escapeHtml(url) + '">';
    }).join('');
    return '<!DOCTYPE html><html lang="lv"><head><meta charset="utf-8">'
      + '<meta name="viewport" content="width=device-width,initial-scale=1">'
      + '<link rel="preconnect" href="https://fonts.googleapis.com">'
      + '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
      + links
      + '<link rel="stylesheet" href="' + escapeHtml(assets.clientCss) + '">'
      + '<style>html,body{margin:0;padding:0;height:100%;overflow:hidden;background:#fff;}</style>'
      + '</head><body>' + html + '</body></html>';
  }

  function collectState(root, base) {
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
    var allCapsEl = document.getElementById('intro_all_caps');
    var heroAccent = readColorInput('hero_accent_color') || base.heroAccent || '#9a9578';
    var introTextColor = readColorInput('intro_text_color') || base.introTextColor || '#1a1a1a';
    var coverUrl = getCoverUrl(document.getElementById('admin-cover-crop-img'), base);

    return {
      theme: theme,
      layout: layout,
      name: nameInput ? nameInput.value : (base.name || 'Galerija'),
      dateRaw: dateRaw,
      dateFormatted: formatDate(dateRaw, dateFormat),
      byline: base.byline || 'GALLERY',
      coverUrl: coverUrl,
      heroAccent: heroAccent,
      introTextColor: introTextColor,
      focalX: fx ? parseFloat(fx.value) || 50 : (base.focalX || 50),
      focalY: fy ? parseFloat(fy.value) || 50 : (base.focalY || 50),
      fontFamily: fontKey,
      dateFormat: dateFormat,
      titleSize: titleKey,
      dateSize: dateKey,
      allCaps: allCapsEl ? allCapsEl.checked : !!base.allCaps,
    };
  }

  function photoHtml(url, focalX, focalY, coverFill) {
    if (!url) return '';
    var style = 'object-position:' + focalX + '% ' + focalY + '%;';
    if (coverFill) style += 'object-fit:cover;';
    return '<img class="gallery-intro-photo" src="' + escapeHtml(url) + '" alt="" decoding="async"'
      + ' style="' + style + '">';
  }

  function introStyle(state, fontMap, groupMap) {
    var font = fontCss(state.fontFamily, fontMap);
    var title = safeStyleVar(titleSizeCss(state.theme, state.layout, state.titleSize));
    var date = safeStyleVar(dateSizeCss(state.theme, state.dateSize));
    var byline = safeStyleVar(bylineSizeCss(state.dateSize));
    return '--hero-accent:' + state.heroAccent + ';--hero-text:' + safeStyleVar(state.introTextColor)
      + ';--intro-text-color:' + safeStyleVar(state.introTextColor) + ';--intro-font:' + font
      + ';--intro-title-size:' + title + ';--intro-date-size:' + date + ';--intro-byline-size:' + byline
      + ';--intro-title-weight:' + titleWeightCss(state.fontFamily, groupMap)
      + ';--intro-title-tracking:' + titleTrackingCss(state.fontFamily, groupMap)
      + ';--intro-title-tracking-caps:' + titleTrackingCapsCss(state.fontFamily, groupMap) + ';';
  }

  function renderSplit(state, layout, fontMap, groupMap) {
    var text = '<div class="gallery-intro-split-text">'
      + '<div class="gallery-intro-split-top">'
      + '<p class="gallery-intro-byline">' + escapeHtml(state.byline) + '</p>';
    if (state.dateFormatted) {
      text += '<p class="gallery-intro-date gallery-intro-date--split">' + escapeHtml(state.dateFormatted) + '</p>';
    }
    text += '</div><h1 class="gallery-intro-title">' + escapeHtml(state.name) + '</h1></div>';
    var media = '<div class="gallery-intro-split-media">' + photoHtml(state.coverUrl, state.focalX, state.focalY, true) + '</div>';
    var inner = layout === 'half-left' ? media + text : text + media;
    return '<section class="gallery-intro gallery-intro--split gallery-intro--layout-' + layout + sectionClass(state) + '" style="' + introStyle(state, fontMap, groupMap) + '">'
      + '<div class="gallery-intro-split">' + inner + '</div></section>';
  }

  function renderMood(state, fontMap, groupMap) {
    var html = '<section class="gallery-intro gallery-intro--mood' + sectionClass(state) + '" style="' + introStyle(state, fontMap, groupMap) + '">';
    html += '<p class="gallery-intro-byline">' + escapeHtml(state.byline) + '</p>';
    html += '<div class="gallery-intro-blob-wrap"><div class="gallery-intro-blob">';
    html += photoHtml(state.coverUrl, state.focalX, state.focalY, true);
    html += '</div></div><div class="gallery-intro-footer">';
    html += '<h1 class="gallery-intro-title">' + escapeHtml(state.name) + '</h1>';
    if (state.dateFormatted) {
      html += '<p class="gallery-intro-date">' + escapeHtml(state.dateFormatted) + '</p>';
    }
    html += '</div></section>';
    return html;
  }

  function renderFull(state, fontMap, groupMap) {
    var html = '<section class="gallery-intro gallery-intro--layout-full' + sectionClass(state) + '" style="' + introStyle(state, fontMap, groupMap) + '">';
    html += '<div class="gallery-intro-full-bg">' + photoHtml(state.coverUrl, state.focalX, state.focalY, true) + '</div>';
    html += '<div class="gallery-intro-full-shade" aria-hidden="true"></div>';
    html += '<div class="gallery-intro-full-content">';
    html += '<p class="gallery-intro-byline">' + escapeHtml(state.byline) + '</p>';
    html += '<div class="gallery-intro-full-footer">';
    if (state.dateFormatted) {
      html += '<p class="gallery-intro-date gallery-intro-date--full">' + escapeHtml(state.dateFormatted) + '</p>';
    }
    html += '<h1 class="gallery-intro-title">' + escapeHtml(state.name) + '</h1>';
    html += '</div></div></section>';
    return html;
  }

  function renderStandard(state, fontMap, groupMap) {
    var layout = state.layout || 'right';
    var html = '<section class="gallery-intro gallery-intro--layout-' + layout + sectionClass(state) + '" style="' + introStyle(state, fontMap, groupMap) + '">';
    html += '<p class="gallery-intro-byline">' + escapeHtml(state.byline) + '</p>';
    html += '<div class="gallery-intro-head"><figure class="gallery-intro-figure">';
    html += photoHtml(state.coverUrl, state.focalX, state.focalY, false);
    if (state.dateFormatted) {
      html += '<figcaption class="gallery-intro-date">' + escapeHtml(state.dateFormatted) + '</figcaption>';
    }
    html += '</figure></div>';
    html += '<h1 class="gallery-intro-title">' + escapeHtml(state.name) + '</h1></section>';
    return html;
  }

  function renderCoverHtml(state, fontMap, groupMap) {
    if (state.theme === 'efpic-mood') {
      return renderMood(state, fontMap, groupMap);
    }
    if (state.layout === 'half-left' || state.layout === 'half-right') {
      return renderSplit(state, state.layout, fontMap, groupMap);
    }
    if (state.layout === 'full') {
      return renderFull(state, fontMap, groupMap);
    }
    return renderStandard(state, fontMap, groupMap);
  }

  function updateDeviceScale(deviceEl) {
    var iframe = deviceEl.querySelector('.admin-cover-live-device__iframe');
    var viewport = deviceEl.querySelector('.admin-cover-live-device__viewport');
    if (!iframe || !viewport) return;
    var designW = parseInt(deviceEl.getAttribute('data-width'), 10) || 1440;
    var designH = parseInt(deviceEl.getAttribute('data-height'), 10) || 900;
    var vw = viewport.clientWidth || 1;
    var scale = Math.min(vw / designW, PREVIEW_DISPLAY_H / designH);
    var scaledW = designW * scale;
    var scaledH = designH * scale;
    iframe.style.position = 'absolute';
    iframe.style.left = Math.max(0, (vw - scaledW) / 2) + 'px';
    iframe.style.top = Math.max(0, (PREVIEW_DISPLAY_H - scaledH) / 2) + 'px';
    iframe.style.width = designW + 'px';
    iframe.style.height = designH + 'px';
    iframe.style.transform = 'scale(' + scale + ')';
    iframe.style.transformOrigin = 'top left';
    viewport.style.height = PREVIEW_DISPLAY_H + 'px';
  }

  function updateAllDeviceScales(root) {
    if (!root) return;
    root.querySelectorAll('.admin-cover-live-device').forEach(updateDeviceScale);
  }

  function withPreservedFocus(fn) {
    lockPreviewFocus(document.activeElement);
    fn();
    restorePreviewFocus();
  }

  function refreshAllPreviews(root, state, fontMap, groupMap, assets) {
    if (!root) return;
    withPreservedFocus(function () {
      var html = renderCoverHtml(state, fontMap, groupMap);
      var doc = buildPreviewDocument(html, assets);
      root.querySelectorAll('.admin-cover-live-device__iframe').forEach(function (iframe) {
        iframe.setAttribute('tabindex', '-1');
        iframe.onload = function () {
          var deviceEl = iframe.closest('.admin-cover-live-device');
          if (deviceEl) {
            updateDeviceScale(deviceEl);
          }
          restorePreviewFocus();
        };
        iframe.srcdoc = doc;
      });
      updateAllDeviceScales(root);
    });
  }

  function syncFontSelectPreview(fontSel, fontMap) {
    if (!fontSel) return;
    Array.prototype.forEach.call(fontSel.options, function (opt) {
      opt.style.fontFamily = fontCss(opt.value, fontMap);
    });
    fontSel.style.fontFamily = fontCss(fontSel.value, fontMap);
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
    var fx = document.getElementById('cover_focal_x');
    var fy = document.getElementById('cover_focal_y');
    var base = parsePreviewData(root);
    var assets = readPreviewAssets(root);
    var fontMap = readFontMap(root);
    var groupMap = readFontGroupMap(root);
    var fontSel = document.getElementById('mood_font_family');
    var layoutInputs = root.querySelectorAll('input[name="cover_layout"]');
    var previewRefreshTimer = 0;

    function schedulePreviewRefresh() {
      if (previewRefreshTimer) {
        clearTimeout(previewRefreshTimer);
      }
      previewRefreshTimer = setTimeout(function () {
        previewRefreshTimer = 0;
        if (previewFocusLock && previewFocusLock.el === document.activeElement) {
          lockPreviewFocus(document.activeElement);
        }
        refreshPreview();
      }, 150);
    }

    function isMood() {
      return themeSelect && themeSelect.value === 'efpic-mood';
    }

    function syncThemePanels() {
      var mood = isMood();
      var url = getCoverUrl(cropImg, base);
      if (layoutBlock) {
        layoutBlock.classList.toggle('is-disabled', mood);
        layoutBlock.querySelectorAll('input[name="cover_layout"]').forEach(function (el) {
          el.disabled = mood;
        });
      }
      if (moodNote) {
        moodNote.hidden = !mood;
      }
      if (cropImg && url && cropImg.getAttribute('src') !== url) {
        cropImg.setAttribute('src', url);
      }
      if (crop) {
        crop.hidden = mood || !hasCoverImage(cropImg, base);
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
      cropImg.style.objectFit = 'cover';
      cropImg.style.objectPosition = fx.value + '% ' + fy.value + '%';
    }

    function refreshPreview() {
      withPreservedFocus(function () {
        var state = collectState(root, base);
        var url = getCoverUrl(cropImg, base);
        state.coverUrl = url;
        if (cropImg && url && cropImg.getAttribute('src') !== url) {
          cropImg.setAttribute('src', url);
        }
        refreshAllPreviews(root, state, fontMap, groupMap, assets);
        syncFontSelectPreview(fontSel, fontMap);
        syncThemePanels();
        updateCropAspect();
      });
    }

    function bindLiveInput(sel) {
      document.querySelectorAll(sel).forEach(function (el) {
        el.addEventListener('input', function () {
          lockPreviewFocus(el);
          schedulePreviewRefresh();
        });
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

    bindLiveInput('input[name="name"], input[name="event_date"], input[name="hero_accent_color"], input[name="intro_text_color"]');
    ['#mood_font_family', '#mood_date_format', '#mood_title_font_size', '#mood_date_font_size', '#intro_all_caps'].forEach(function (sel) {
      document.querySelectorAll(sel).forEach(function (el) {
        el.addEventListener('change', refreshPreview);
        el.addEventListener('input', refreshPreview);
      });
    });

    document.addEventListener('change', function (evt) {
      if (evt.target && evt.target.matches && evt.target.matches('input[name="cover_image_token"]')) {
        refreshPreview();
      }
    });

    syncThemePanels();
    updateCropAspect();
    applyFocal();
    refreshPreview();
    window.addEventListener('resize', function () {
      updateAllDeviceScales(root);
    });
    if (typeof ResizeObserver !== 'undefined') {
      var grid = document.getElementById('admin-cover-live-grid');
      if (grid) {
        new ResizeObserver(function () {
          updateAllDeviceScales(root);
        }).observe(grid);
      }
    }

    if (!frame || !cropImg || !fx || !fy) return;

    var dragging = false;
    var startX = 0;
    var startY = 0;
    var startFx = 50;
    var startFy = 50;

    frame.addEventListener('pointerdown', function (evt) {
      if (isMood() || !hasCoverImage(cropImg, base)) return;
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
