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
  var INTRO_TEXT_ROLES = ['byline', 'date', 'title'];
  var OVERLAY_CLASS = ' gallery-intro--text-overlay';
  var INTRO_SLOT_COORDS = {
    'top-left': { x: 6, y: 8, align: 'left' },
    'top-center': { x: 50, y: 8, align: 'center' },
    'top-right': { x: 94, y: 8, align: 'right' },
    'center-left': { x: 6, y: 50, align: 'left' },
    center: { x: 50, y: 50, align: 'center' },
    'center-right': { x: 94, y: 50, align: 'right' },
    'bottom-left': { x: 6, y: 88, align: 'left' },
    'bottom-center': { x: 50, y: 88, align: 'center' },
    'bottom-right': { x: 94, y: 88, align: 'right' },
  };

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

  function titleSizeCss(coverStyle, layout, key) {
    if (layout === 'half-left' || layout === 'half-right') {
      return TITLE_SIZES.split[key] || TITLE_SIZES.split.md;
    }
    if (coverStyle === 'mood-blob') {
      return TITLE_SIZES.mood[key] || TITLE_SIZES.mood.md;
    }
    return TITLE_SIZES.standard[key] || TITLE_SIZES.standard.md;
  }

  function dateSizeCss(coverStyle, key) {
    var bucket = coverStyle === 'mood-blob' ? DATE_SIZES.mood : DATE_SIZES.standard;
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
    var anim = state.coverAnimation || 'none';
    if (anim && anim !== 'none') {
      cls += ' gallery-intro-cover-anim--' + anim;
    }
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
    if (readCoverMediaType() === 'video' && readCoverVideoUrl()) {
      return true;
    }
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

  function buildPreviewDocument(html, assets, pageBg) {
    var links = (assets.fontUrls || []).map(function (url) {
      return '<link rel="stylesheet" href="' + escapeHtml(url) + '">';
    }).join('');
    var bg = pageBg || '#ffffff';
    return '<!DOCTYPE html><html lang="lv"><head><meta charset="utf-8">'
      + '<meta name="viewport" content="width=device-width,initial-scale=1">'
      + '<link rel="preconnect" href="https://fonts.googleapis.com">'
      + '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
      + links
      + '<link rel="stylesheet" href="' + escapeHtml(assets.clientCss) + '">'
      + '<style>html,body{margin:0;padding:0;height:100%;overflow:hidden;}'
      + 'body.page-gallery{background:' + escapeHtml(bg) + ';color:var(--page-text,#111);}'
      + '.gallery-intro-text-layer.is-editable .intro-text-free{cursor:grab;touch-action:none;}'
      + '.gallery-intro-text-layer.is-editable .intro-text-free.is-selected{outline:2px solid rgba(255,255,255,.55);outline-offset:4px;}'
      + '.gallery-intro-text-layer.is-editable .intro-text-free.is-dragging{cursor:grabbing;outline:2px dashed rgba(255,255,255,.55);outline-offset:4px;}'
      + '.gallery-intro-text-layer.is-editable .intro-text-free--title.is-resizing{cursor:ew-resize;}'
      + '.intro-text-resize-handle{position:absolute;top:50%;right:-10px;width:10px;height:28px;margin-top:-14px;border-radius:3px;background:rgba(255,255,255,.45);cursor:ew-resize;display:none;}'
      + '.gallery-intro-text-layer.is-editable .intro-text-free--title .intro-text-resize-handle{display:block;}'
      + '</style>'
      + '</head><body class="page-gallery">' + html + '</body></html>';
  }

  function readCoverVideoUrl() {
    var sel = document.getElementById('cover_video_id');
    if (!sel || !sel.value) return '';
    var opt = sel.options[sel.selectedIndex];
    return opt ? opt.getAttribute('data-url') || '' : '';
  }

  function readCoverMediaType() {
    var checked = document.querySelector('input[name="cover_media_type"]:checked');
    return checked && checked.value === 'video' ? 'video' : 'image';
  }

  function setColorInput(name, value) {
    if (!value) return;
    var el = document.querySelector('input[name="' + name + '"]');
    if (!el) return;
    el.value = value;
    el.dispatchEvent(new Event('input', { bubbles: true }));
    var wrap = el.closest('.admin-color-field');
    if (wrap) {
      var swatch = wrap.querySelector('.admin-color-swatch');
      var code = wrap.querySelector('.admin-color-value');
      if (swatch) swatch.style.backgroundColor = value;
      if (code) code.textContent = value;
    }
  }

  function defaultIntroTextPlacements(coverStyle, layout) {
    if (coverStyle === 'mood-blob') {
      return { byline: 'top-center', date: 'bottom-center', title: 'center' };
    }
    if (coverStyle === 'cinematic-full' || layout === 'full') {
      return { byline: 'top-center', date: 'bottom-left', title: 'bottom-center' };
    }
    if (layout === 'half-left' || layout === 'half-right') {
      return { byline: 'top-left', date: 'top-right', title: 'bottom-left' };
    }
    return { byline: 'top-left', date: 'top-right', title: 'bottom-left' };
  }

  function defaultIntroTextLayout(coverStyle, layout) {
    if (coverStyle === 'standard' && layout === 'right') {
      return {
        byline: { x: 6, y: 8, align: 'left' },
        date: { x: 44, y: 8, align: 'right' },
        title: { x: 6, y: 88, align: 'left', width: 42 },
      };
    }
    if (coverStyle === 'standard' && layout === 'left') {
      return {
        byline: { x: 94, y: 8, align: 'right' },
        date: { x: 56, y: 8, align: 'left' },
        title: { x: 94, y: 88, align: 'right', width: 42 },
      };
    }
    if (coverStyle === 'standard' && layout === 'center') {
      return {
        byline: { x: 50, y: 8, align: 'center' },
        date: { x: 94, y: 8, align: 'right' },
        title: { x: 50, y: 88, align: 'center', width: 72 },
      };
    }
    var placements = defaultIntroTextPlacements(coverStyle, layout);
    var out = {};
    INTRO_TEXT_ROLES.forEach(function (role) {
      var slot = placements[role] || 'bottom-center';
      out[role] = cloneIntroLayoutItem(INTRO_SLOT_COORDS[slot] || INTRO_SLOT_COORDS['bottom-center']);
      if (role === 'title') out[role].width = 72;
    });
    return out;
  }

  function layoutStorageKey(coverStyle, layout) {
    if (coverStyle === 'cinematic-full') return 'cinematic-full';
    if (coverStyle === 'mood-blob') return 'mood-blob';
    return layout || 'right';
  }

  function cloneIntroLayoutItem(item) {
    return { x: item.x, y: item.y, align: item.align };
  }

  function cloneIntroTextLayout(layout) {
    var out = {};
    INTRO_TEXT_ROLES.forEach(function (role) {
      if (!layout || !layout[role]) return;
      out[role] = cloneIntroLayoutItem(layout[role]);
      if (role === 'title' && layout[role].width != null) {
        out[role].width = layout[role].width;
      }
    });
    return out;
  }

  function readIntroTextLayoutsMap(base) {
    var map = {};
    var hidden = document.getElementById('intro_text_layouts');
    if (hidden && hidden.value) {
      try { map = JSON.parse(hidden.value) || {}; } catch (e) { map = {}; }
    } else if (base && base.introTextLayouts) {
      map = base.introTextLayouts;
    }
    if (base && base.introTextLayout) {
      var coverStyle = document.getElementById('cover_style');
      var style = coverStyle ? coverStyle.value : (base.coverStyle || 'standard');
      var layoutInput = document.querySelector('input[name="cover_layout"]:checked');
      var layout = layoutInput ? layoutInput.value : (base.layout || 'right');
      var key = layoutStorageKey(style, layout);
      if (!map[key]) map[key] = cloneIntroTextLayout(base.introTextLayout);
    }
    return map;
  }

  function writeIntroTextLayoutsMap(map) {
    var hidden = document.getElementById('intro_text_layouts');
    if (hidden) hidden.value = JSON.stringify(map || {});
  }

  function sanitizeIntroLayoutRole(role, raw, fallback) {
    var out = {
      x: clampPercent(raw && raw.x != null ? raw.x : fallback.x),
      y: clampPercent(raw && raw.y != null ? raw.y : fallback.y),
      align: raw && raw.align ? String(raw.align) : fallback.align,
    };
    if (['left', 'center', 'right'].indexOf(out.align) < 0) out.align = fallback.align;
    if (role === 'title') {
      out.width = clampPercent(raw && raw.width != null ? raw.width : (fallback.width || 72), 20, 100);
    }
    return out;
  }

  function clampPercent(value, min, max) {
    var n = parseFloat(value);
    if (!isFinite(n)) n = 50;
    var lo = min != null ? min : 0;
    var hi = max != null ? max : 100;
    return Math.max(lo, Math.min(hi, n));
  }

  function readIntroTextLayout(base, coverStyle, layout, layoutsMap) {
    var defaults = defaultIntroTextLayout(coverStyle, layout);
    var key = layoutStorageKey(coverStyle, layout);
    var map = layoutsMap || readIntroTextLayoutsMap(base);
    if (map[key]) {
      INTRO_TEXT_ROLES.forEach(function (role) {
        if (map[key][role]) defaults[role] = sanitizeIntroLayoutRole(role, map[key][role], defaults[role]);
      });
    } else {
      var hidden = document.getElementById('intro_text_layout');
      var raw = null;
      if (hidden && hidden.value) {
        try { raw = JSON.parse(hidden.value); } catch (e) { raw = null; }
      }
      if (raw) {
        INTRO_TEXT_ROLES.forEach(function (role) {
          if (raw[role]) defaults[role] = sanitizeIntroLayoutRole(role, raw[role], defaults[role]);
        });
      } else if (!layoutsMap && base && base.introTextPlacements) {
        INTRO_TEXT_ROLES.forEach(function (role) {
          var slot = base.introTextPlacements[role];
          if (slot && INTRO_SLOT_COORDS[slot]) {
            defaults[role] = cloneIntroLayoutItem(INTRO_SLOT_COORDS[slot]);
            if (role === 'title') defaults[role].width = 72;
          }
        });
      }
    }
    var widthInput = document.getElementById('intro_title_layout_width');
    if (widthInput && widthInput.value) {
      defaults.title.width = clampPercent(widthInput.value, 20, 100);
    }
    return defaults;
  }

  function saveIntroTextLayoutForKey(layoutsMap, coverStyle, layout, layoutData) {
    var key = layoutStorageKey(coverStyle, layout);
    layoutsMap[key] = cloneIntroTextLayout(layoutData);
    if (layoutData.title && layoutData.title.width != null) {
      layoutsMap[key].title.width = layoutData.title.width;
    }
    writeIntroTextLayoutsMap(layoutsMap);
    writeIntroTextLayout(layoutData);
    return layoutsMap;
  }

  function writeIntroTextLayout(layout) {
    var hidden = document.getElementById('intro_text_layout');
    if (hidden) hidden.value = JSON.stringify(layout);
    var widthInput = document.getElementById('intro_title_layout_width');
    var widthLabel = document.getElementById('intro_title_layout_width_label');
    if (widthInput && layout.title && layout.title.width != null) {
      widthInput.value = String(Math.round(layout.title.width));
      if (widthLabel) widthLabel.textContent = Math.round(layout.title.width) + '%';
    }
  }

  function introTextAlignClass(role, layout) {
    var align = (layout && layout[role] && layout[role].align) || 'left';
    return ' intro-text-align-' + align.replace(/[^a-z]/g, '');
  }

  function introTextStyleAttr(role, layout) {
    var item = (layout && layout[role]) || { x: 50, y: 50, align: 'center' };
    var style = 'left:' + item.x + '%;top:' + item.y + '%;';
    if (role === 'title' && item.width != null) {
      style += '--intro-title-box-width:' + item.width + '%;';
    }
    return ' style="' + style + '"';
  }

  function introTextLayerHtml(state) {
    var layout = state.introTextLayout || defaultIntroTextLayout(state.coverStyle, state.layout);
    var html = '<div class="gallery-intro-text-layer is-editable">';
    html += '<p class="gallery-intro-byline intro-text-free' + introTextAlignClass('byline', layout) + '" data-intro-role="byline"'
      + introTextStyleAttr('byline', layout) + '>' + escapeHtml(state.byline) + '</p>';
    if (state.dateFormatted) {
      html += '<p class="gallery-intro-date intro-text-free' + introTextAlignClass('date', layout) + '" data-intro-role="date"'
        + introTextStyleAttr('date', layout) + '>' + escapeHtml(state.dateFormatted) + '</p>';
    }
    html += '<h1 class="gallery-intro-title intro-text-free intro-text-free--title' + introTextAlignClass('title', layout)
      + '" data-intro-role="title"' + introTextStyleAttr('title', layout) + '>'
      + escapeHtml(state.name) + '<span class="intro-text-resize-handle" data-resize-title="1" aria-hidden="true"></span></h1>';
    html += '</div>';
    return html;
  }

  function introStageHtml(mediaHtml, state) {
    return '<div class="gallery-intro-stage">' + mediaHtml + introTextLayerHtml(state) + '</div>';
  }

  function setupIntroTextDragInIframe(iframe, getLayout, onLayoutChange) {
    if (!iframe || !iframe.contentDocument) return;
    var doc = iframe.contentDocument;
    var layer = doc.querySelector('.gallery-intro-text-layer.is-editable');
    if (!layer) return;

    layer.querySelectorAll('.intro-text-free').forEach(function (el) {
      if (el.dataset.dragBound === '1') return;
      el.dataset.dragBound = '1';
      var role = el.getAttribute('data-intro-role');
      if (!role) return;

      el.addEventListener('pointerdown', function (evt) {
        if (evt.target && evt.target.getAttribute('data-resize-title') === '1') return;
        evt.preventDefault();
        if (typeof window.efpicIntroTextSelectRole === 'function') {
          window.efpicIntroTextSelectRole(role);
        }
        var rect = layer.getBoundingClientRect();
        if (!rect.width || !rect.height) return;
        el.classList.add('is-dragging');
        var startX = evt.clientX;
        var startY = evt.clientY;
        var layout = getLayout();
        if (!layout[role]) layout[role] = { x: 50, y: 50, align: 'center' };
        var item = layout[role];
        var originX = item.x;
        var originY = item.y;

        function move(ev) {
          var nx = originX + ((ev.clientX - startX) / rect.width) * 100;
          var ny = originY + ((ev.clientY - startY) / rect.height) * 100;
          nx = clampPercent(nx);
          ny = clampPercent(ny);
          el.style.left = nx + '%';
          el.style.top = ny + '%';
        }

        function up(ev) {
          el.classList.remove('is-dragging');
          doc.removeEventListener('pointermove', move);
          doc.removeEventListener('pointerup', up);
          doc.removeEventListener('pointercancel', up);
          var nx = originX + ((ev.clientX - startX) / rect.width) * 100;
          var ny = originY + ((ev.clientY - startY) / rect.height) * 100;
          if (!layout[role]) layout[role] = { x: 50, y: 50, align: 'center' };
          layout[role].x = clampPercent(nx);
          layout[role].y = clampPercent(ny);
          onLayoutChange(layout);
        }

        doc.addEventListener('pointermove', move);
        doc.addEventListener('pointerup', up);
        doc.addEventListener('pointercancel', up);
      });
    });

    var titleEl = layer.querySelector('.intro-text-free--title');
    var handle = titleEl ? titleEl.querySelector('.intro-text-resize-handle') : null;
    if (handle && handle.dataset.resizeBound !== '1') {
      handle.dataset.resizeBound = '1';
      handle.addEventListener('pointerdown', function (evt) {
        evt.preventDefault();
        evt.stopPropagation();
        if (!titleEl) return;
        titleEl.classList.add('is-resizing');
        var rect = layer.getBoundingClientRect();
        var layout = getLayout();
        var startX = evt.clientX;
        var startW = layout.title.width || 72;

        function move(ev) {
          var delta = ((ev.clientX - startX) / rect.width) * 100;
          var nw = clampPercent(startW + delta, 20, 100);
          titleEl.style.setProperty('--intro-title-box-width', nw + '%');
        }

        function up(ev) {
          titleEl.classList.remove('is-resizing');
          doc.removeEventListener('pointermove', move);
          doc.removeEventListener('pointerup', up);
          doc.removeEventListener('pointercancel', up);
          var delta = ((ev.clientX - startX) / rect.width) * 100;
          layout.title.width = clampPercent(startW + delta, 20, 100);
          onLayoutChange(layout);
        }

        doc.addEventListener('pointermove', move);
        doc.addEventListener('pointerup', up);
        doc.addEventListener('pointercancel', up);
      });
    }
  }

  function triggerDesignAutoSave() {
    if (typeof window.efpicRunAdminAutoSave === 'function') {
      window.efpicRunAdminAutoSave();
    }
  }

  function collectState(root, base) {
    var coverStyleSel = document.getElementById('cover_style');
    var coverStyle = coverStyleSel ? coverStyleSel.value : (base.coverStyle || 'standard');
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
    var coverAnimSel = document.getElementById('cover_animation');
    var coverMediaType = readCoverMediaType();
    var coverVideoSel = document.getElementById('cover_video_id');
    var introTextLayout = readIntroTextLayout(base, coverStyle, layout, readIntroTextLayoutsMap(base));
    var pageBg = readColorInput('page_bg_color') || base.pageBg || '#ffffff';

    return {
      coverStyle: coverStyle,
      introTextLayout: introTextLayout,
      introTextLayouts: readIntroTextLayoutsMap(base),
      layout: layout,
      name: nameInput ? nameInput.value : (base.name || 'Galerija'),
      dateRaw: dateRaw,
      dateFormatted: formatDate(dateRaw, dateFormat),
      byline: base.byline || 'GALLERY',
      coverUrl: coverUrl,
      coverMediaType: coverMediaType,
      coverVideoUrl: coverMediaType === 'video' ? readCoverVideoUrl() : '',
      coverVideoId: coverVideoSel ? coverVideoSel.value : (base.coverVideoId || ''),
      coverAnimation: coverAnimSel ? coverAnimSel.value : (base.coverAnimation || 'none'),
      heroAccent: heroAccent,
      introTextColor: introTextColor,
      pageBg: pageBg,
      focalX: fx ? parseFloat(fx.value) || 50 : (base.focalX || 50),
      focalY: fy ? parseFloat(fy.value) || 50 : (base.focalY || 50),
      fontFamily: fontKey,
      dateFormat: dateFormat,
      titleSize: titleKey,
      dateSize: dateKey,
      allCaps: allCapsEl ? allCapsEl.checked : !!base.allCaps,
    };
  }

  function mediaHtml(state, coverFill) {
    var url = '';
    var isVideo = state.coverMediaType === 'video' && state.coverVideoUrl;
    if (isVideo) {
      url = state.coverVideoUrl;
    } else if (state.coverUrl) {
      url = state.coverUrl;
    }
    if (!url) return '';
    var style = 'object-position:' + state.focalX + '% ' + state.focalY + '%;';
    if (coverFill) style += 'object-fit:cover;';
    if (isVideo) {
      return '<video class="gallery-intro-photo gallery-intro-cover-video" src="' + escapeHtml(url) + '"'
        + ' autoplay muted loop playsinline disablePictureInPicture aria-hidden="true" preload="auto"'
        + ' style="' + style + '"></video>';
    }
    return '<img class="gallery-intro-photo" src="' + escapeHtml(url) + '" alt="" decoding="async"'
      + ' style="' + style + '">';
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
    var title = safeStyleVar(titleSizeCss(state.coverStyle, state.layout, state.titleSize));
    var date = safeStyleVar(dateSizeCss(state.coverStyle, state.dateSize));
    var byline = safeStyleVar(bylineSizeCss(state.dateSize));
    return '--hero-accent:' + state.heroAccent + ';--hero-text:' + safeStyleVar(state.introTextColor)
      + ';--intro-text-color:' + safeStyleVar(state.introTextColor) + ';--page-bg:' + safeStyleVar(state.pageBg || '#ffffff')
      + ';--intro-font:' + font
      + ';--intro-title-size:' + title + ';--intro-date-size:' + date + ';--intro-byline-size:' + byline
      + ';--intro-title-weight:' + titleWeightCss(state.fontFamily, groupMap)
      + ';--intro-title-tracking:' + titleTrackingCss(state.fontFamily, groupMap)
      + ';--intro-title-tracking-caps:' + titleTrackingCapsCss(state.fontFamily, groupMap) + ';';
  }

  function renderSplit(state, layout, fontMap, groupMap) {
    var mediaPane = '<div class="gallery-intro-split-pane gallery-intro-split-pane--media gallery-intro-cover-media">' + mediaHtml(state, true) + '</div>';
    var accentPane = '<div class="gallery-intro-split-pane gallery-intro-split-pane--accent" aria-hidden="true"></div>';
    var inner = layout === 'half-left' ? mediaPane + accentPane : accentPane + mediaPane;
    return '<section class="gallery-intro gallery-intro--split gallery-intro--layout-' + layout + OVERLAY_CLASS + sectionClass(state) + '" style="' + introStyle(state, fontMap, groupMap) + '">'
      + introStageHtml('<div class="gallery-intro-split">' + inner + '</div>', state)
      + '</section>';
  }

  function renderMood(state, fontMap, groupMap) {
    var media = '<div class="gallery-intro-media gallery-intro-media--mood"><div class="gallery-intro-blob-wrap"><div class="gallery-intro-blob">'
      + mediaHtml(state, true) + '</div></div></div>';
    return '<section class="gallery-intro gallery-intro--mood' + OVERLAY_CLASS + sectionClass(state) + '" style="' + introStyle(state, fontMap, groupMap) + '">'
      + introStageHtml(media, state) + '</section>';
  }

  function renderFull(state, fontMap, groupMap) {
    var media = '<div class="gallery-intro-full-bg gallery-intro-cover-media">' + mediaHtml(state, true) + '</div>'
      + '<div class="gallery-intro-full-shade" aria-hidden="true"></div>';
    return '<section class="gallery-intro gallery-intro--layout-full' + OVERLAY_CLASS + sectionClass(state) + '" style="' + introStyle(state, fontMap, groupMap) + '">'
      + introStageHtml(media, state) + '</section>';
  }

  function renderStandard(state, fontMap, groupMap) {
    var layout = state.layout || 'right';
    var media = '<div class="gallery-intro-media gallery-intro-media--standard"><div class="gallery-intro-head"><figure class="gallery-intro-figure gallery-intro-cover-media">'
      + mediaHtml(state, false) + '</figure></div></div>';
    return '<section class="gallery-intro gallery-intro--layout-' + layout + OVERLAY_CLASS + sectionClass(state) + '" style="' + introStyle(state, fontMap, groupMap) + '">'
      + introStageHtml(media, state) + '</section>';
  }

  function renderCinematic(state, fontMap, groupMap) {
    var media = '<div class="gallery-intro-cinematic-bg gallery-intro-cover-media">' + mediaHtml(state, true) + '</div>'
      + '<div class="gallery-intro-cinematic-vignette" aria-hidden="true"></div>';
    return '<section class="gallery-intro gallery-intro--cinematic-full' + OVERLAY_CLASS + sectionClass(state) + '" style="' + introStyle(state, fontMap, groupMap) + '">'
      + introStageHtml(media, state) + '</section>';
  }

  function renderCoverHtml(state, fontMap, groupMap) {
    if (state.coverStyle === 'mood-blob') {
      return renderMood(state, fontMap, groupMap);
    }
    if (state.coverStyle === 'cinematic-full') {
      return renderCinematic(state, fontMap, groupMap);
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

  function refreshAllPreviews(root, state, fontMap, groupMap, assets, layoutHandlers) {
    if (!root) return;
    withPreservedFocus(function () {
      var html = renderCoverHtml(state, fontMap, groupMap);
      var doc = buildPreviewDocument(html, assets, state.pageBg);
      root.querySelectorAll('.admin-cover-live-device__iframe').forEach(function (iframe) {
        iframe.setAttribute('tabindex', '-1');
        iframe.onload = function () {
          var deviceEl = iframe.closest('.admin-cover-live-device');
          if (deviceEl) {
            updateDeviceScale(deviceEl);
          }
          if (layoutHandlers) {
            setupIntroTextDragInIframe(iframe, layoutHandlers.getLayout, layoutHandlers.onChange);
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

    var coverStyleSel = document.getElementById('cover_style');
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
    var selectedRoleInput = document.getElementById('intro_text_selected_role');
    var alignPanel = document.getElementById('admin-intro-text-align');
    var selectedRole = (selectedRoleInput && selectedRoleInput.value) ? selectedRoleInput.value : 'title';
    var introTextLayoutsMap = readIntroTextLayoutsMap(base);
    var activeLayout = base.layout || 'right';

    function setSelectedRole(role) {
      if (!role) return;
      selectedRole = role;
      if (selectedRoleInput) selectedRoleInput.value = role;

      // Highlight selected element in all preview iframes.
      root.querySelectorAll('.admin-cover-live-device__iframe').forEach(function (iframe) {
        try {
          var doc = iframe.contentDocument;
          if (!doc) return;
          var layer = doc.querySelector('.gallery-intro-text-layer');
          if (!layer) return;
          layer.querySelectorAll('[data-intro-role]').forEach(function (el) {
            if (el.getAttribute('data-intro-role') === role) {
              el.classList.add('is-selected');
            } else {
              el.classList.remove('is-selected');
            }
          });
        } catch (e) {}
      });
    }

    // Expose for iframe pointer handlers.
    window.efpicIntroTextSelectRole = setSelectedRole;

    function applyAlignPreset(preset) {
      var coverStyle = coverStyleSel ? coverStyleSel.value : (base.coverStyle || 'standard');
      var layoutKey = getSelectedLayout();
      var layout = readIntroTextLayout(base, coverStyle, layoutKey, introTextLayoutsMap);
      var role = selectedRole || 'title';
      if (!layout[role]) layout[role] = { x: 50, y: 50, align: 'center' };
      if (preset === 'left') {
        layout[role].x = 6;
        layout[role].align = 'left';
      } else if (preset === 'center') {
        layout[role].x = 50;
        layout[role].align = 'center';
      } else if (preset === 'right') {
        layout[role].x = 94;
        layout[role].align = 'right';
      }
      saveIntroTextLayoutForKey(introTextLayoutsMap, coverStyle, layoutKey, layout);
      triggerDesignAutoSave();
      refreshPreview();
      setSelectedRole(role);
    }

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

    function isLayoutLocked() {
      if (!coverStyleSel) return false;
      var style = coverStyleSel.value;
      return style === 'mood-blob' || style === 'cinematic-full';
    }

    function isMoodBlob() {
      return coverStyleSel && coverStyleSel.value === 'mood-blob';
    }

    function syncCoverMediaPanels() {
      var videoSelectWrap = document.getElementById('admin-cover-video-select');
      var isVideo = readCoverMediaType() === 'video';
      if (videoSelectWrap) {
        videoSelectWrap.classList.toggle('is-hidden', !isVideo);
      }
    }

    function syncThemePanels() {
      var mood = isMoodBlob();
      var layoutLocked = isLayoutLocked();
      var cinematicNote = document.getElementById('admin-cover-layout-cinematic-note');
      var url = getCoverUrl(cropImg, base);
      if (layoutBlock) {
        layoutBlock.classList.toggle('is-disabled', layoutLocked);
        layoutBlock.querySelectorAll('input[name="cover_layout"]').forEach(function (el) {
          el.disabled = layoutLocked;
        });
      }
      if (moodNote) {
        moodNote.hidden = !mood;
      }
      if (cinematicNote) {
        cinematicNote.hidden = !(coverStyleSel && coverStyleSel.value === 'cinematic-full');
      }
      if (cropImg && url && cropImg.getAttribute('src') !== url) {
        cropImg.setAttribute('src', url);
      }
      if (crop) {
        var showCrop = !mood && hasCoverImage(cropImg, base) && readCoverMediaType() !== 'video';
        crop.hidden = !showCrop;
      }
      syncCoverMediaPanels();
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

    var layoutHandlers = {
      getLayout: function () {
        return readIntroTextLayout(base, coverStyleSel ? coverStyleSel.value : 'standard', getSelectedLayout(), introTextLayoutsMap);
      },
      onChange: function (layout) {
        var coverStyle = coverStyleSel ? coverStyleSel.value : (base.coverStyle || 'standard');
        saveIntroTextLayoutForKey(introTextLayoutsMap, coverStyle, getSelectedLayout(), layout);
        triggerDesignAutoSave();
      },
    };

    function refreshPreview() {
      withPreservedFocus(function () {
        var state = collectState(root, base);
        var url = getCoverUrl(cropImg, base);
        state.coverUrl = url;
        writeIntroTextLayout(state.introTextLayout);
        writeIntroTextLayoutsMap(state.introTextLayouts || introTextLayoutsMap);
        if (cropImg && url && cropImg.getAttribute('src') !== url) {
          cropImg.setAttribute('src', url);
        }
        refreshAllPreviews(root, state, fontMap, groupMap, assets, layoutHandlers);
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

    if (coverStyleSel) {
      coverStyleSel.addEventListener('change', function () {
        var coverStyle = coverStyleSel.value;
        var layoutKey = getSelectedLayout();
        var next = introTextLayoutsMap[layoutStorageKey(coverStyle, layoutKey)]
          ? cloneIntroTextLayout(introTextLayoutsMap[layoutStorageKey(coverStyle, layoutKey)])
          : defaultIntroTextLayout(coverStyle, layoutKey);
        saveIntroTextLayoutForKey(introTextLayoutsMap, coverStyle, layoutKey, next);
        syncThemePanels();
        refreshPreview();
      });
    }

    layoutInputs.forEach(function (input) {
      input.addEventListener('change', function () {
        var coverStyle = coverStyleSel ? coverStyleSel.value : (base.coverStyle || 'standard');
        var prevLayout = activeLayout;
        var prevKey = layoutStorageKey(coverStyle, prevLayout);
        var currentPositions = readIntroTextLayout(base, coverStyle, prevLayout, introTextLayoutsMap);
        introTextLayoutsMap[prevKey] = cloneIntroTextLayout(currentPositions);
        if (currentPositions.title && currentPositions.title.width != null) {
          introTextLayoutsMap[prevKey].title.width = currentPositions.title.width;
        }

        activeLayout = getSelectedLayout();
        var nextKey = layoutStorageKey(coverStyle, activeLayout);
        var nextPositions = introTextLayoutsMap[nextKey]
          ? cloneIntroTextLayout(introTextLayoutsMap[nextKey])
          : defaultIntroTextLayout(coverStyle, activeLayout);
        saveIntroTextLayoutForKey(introTextLayoutsMap, coverStyle, activeLayout, nextPositions);

        base.layout = activeLayout;
        updateCropAspect();
        refreshPreview();
        triggerDesignAutoSave();
      });
    });

    bindLiveInput('input[name="name"], input[name="event_date"], input[name="hero_accent_color"], input[name="page_bg_color"], input[name="intro_text_color"]');
    ['#cover_style', '#mosaic_max_columns', '#mood_font_family', '#mood_date_format', '#mood_title_font_size', '#mood_date_font_size', '#intro_all_caps', '#cover_animation', '#cover_video_id'].forEach(function (sel) {
      document.querySelectorAll(sel).forEach(function (el) {
        el.addEventListener('change', refreshPreview);
        el.addEventListener('input', refreshPreview);
      });
    });
    var titleWidthInput = document.getElementById('intro_title_layout_width');
    if (titleWidthInput) {
      titleWidthInput.addEventListener('input', function () {
        var coverStyle = coverStyleSel ? coverStyleSel.value : (base.coverStyle || 'standard');
        var layoutKey = getSelectedLayout();
        var layout = readIntroTextLayout(base, coverStyle, layoutKey, introTextLayoutsMap);
        layout.title.width = clampPercent(titleWidthInput.value, 20, 100);
        saveIntroTextLayoutForKey(introTextLayoutsMap, coverStyle, layoutKey, layout);
        refreshPreview();
      });
      titleWidthInput.addEventListener('change', function () {
        triggerDesignAutoSave();
      });
    }

    if (alignPanel) {
      alignPanel.querySelectorAll('[data-intro-align]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var preset = btn.getAttribute('data-intro-align');
          applyAlignPreset(preset);
        });
      });
    }

    if (base.introTextLayouts) {
      writeIntroTextLayoutsMap(introTextLayoutsMap);
    }
    if (base.introTextLayout) {
      writeIntroTextLayout(base.introTextLayout);
    } else if (base.introTextPlacements) {
      var initStyle = coverStyleSel ? coverStyleSel.value : (base.coverStyle || 'standard');
      var initLayout = getSelectedLayout();
      saveIntroTextLayoutForKey(
        introTextLayoutsMap,
        initStyle,
        initLayout,
        readIntroTextLayout(base, initStyle, initLayout, introTextLayoutsMap)
      );
    } else {
      var initCoverStyle = coverStyleSel ? coverStyleSel.value : (base.coverStyle || 'standard');
      var initCoverLayout = getSelectedLayout();
      saveIntroTextLayoutForKey(
        introTextLayoutsMap,
        initCoverStyle,
        initCoverLayout,
        defaultIntroTextLayout(initCoverStyle, initCoverLayout)
      );
    }

    setSelectedRole(selectedRole);

    var mosaicSel = document.getElementById('mosaic_max_columns');
    if (mosaicSel && base.mosaicMaxColumns != null) {
      mosaicSel.value = String(base.mosaicMaxColumns);
    }
    document.querySelectorAll('input[name="cover_media_type"]').forEach(function (el) {
      el.addEventListener('change', function () {
        syncThemePanels();
        refreshPreview();
      });
    });

    var paletteRoot = document.getElementById('admin-design-palettes');
    if (paletteRoot) {
      paletteRoot.querySelectorAll('.admin-design-palette').forEach(function (btn) {
        btn.addEventListener('click', function () {
          setColorInput('hero_accent_color', btn.getAttribute('data-hero'));
          setColorInput('page_bg_color', btn.getAttribute('data-page'));
          setColorInput('intro_text_color', btn.getAttribute('data-text'));
          var hidden = document.getElementById('design_palette');
          if (hidden) hidden.value = btn.getAttribute('data-palette') || '';
          paletteRoot.querySelectorAll('.admin-design-palette').forEach(function (el) {
            var on = el === btn;
            el.classList.toggle('is-selected', on);
            el.setAttribute('aria-selected', on ? 'true' : 'false');
          });
          refreshPreview();
        });
      });
    }

    function applyDesignSettings(s) {
      if (!s) return;
      if (coverStyleSel && s.cover_style) coverStyleSel.value = s.cover_style;
      if (s.intro_text_layouts) {
        writeIntroTextLayoutsMap(s.intro_text_layouts);
        introTextLayoutsMap = readIntroTextLayoutsMap(base);
      }
      if (s.intro_text_layout) {
        var applyStyle = coverStyleSel ? coverStyleSel.value : (base.coverStyle || 'standard');
        var applyLayout = s.cover_layout || getSelectedLayout();
        saveIntroTextLayoutForKey(introTextLayoutsMap, applyStyle, applyLayout, s.intro_text_layout);
      } else if (s.intro_text_placements) {
        saveIntroTextLayoutForKey(
          introTextLayoutsMap,
          coverStyleSel ? coverStyleSel.value : 'standard',
          getSelectedLayout(),
          readIntroTextLayout({ introTextPlacements: s.intro_text_placements }, coverStyleSel ? coverStyleSel.value : 'standard', getSelectedLayout(), introTextLayoutsMap)
        );
      }
      var mosaicSel = document.getElementById('mosaic_max_columns');
      if (mosaicSel && s.mosaic_max_columns != null) mosaicSel.value = String(s.mosaic_max_columns);
      if (s.hero_accent_color) setColorInput('hero_accent_color', s.hero_accent_color);
      if (s.page_bg_color) setColorInput('page_bg_color', s.page_bg_color);
      if (s.intro_text_color) setColorInput('intro_text_color', s.intro_text_color);
      if (s.cover_layout) {
        layoutInputs.forEach(function (input) {
          input.checked = input.value === s.cover_layout;
        });
        activeLayout = s.cover_layout;
        base.layout = s.cover_layout;
      }
      if (fx && s.cover_focal_x != null) fx.value = s.cover_focal_x;
      if (fy && s.cover_focal_y != null) fy.value = s.cover_focal_y;
      if (fontSel && s.mood_font_family) fontSel.value = s.mood_font_family;
      ['mood_date_format', 'mood_title_font_size', 'mood_date_font_size'].forEach(function (name) {
        if (s[name] == null) return;
        var el = document.getElementById(name);
        if (el) el.value = s[name];
      });
      var caps = document.getElementById('intro_all_caps');
      if (caps && typeof s.intro_all_caps !== 'undefined') caps.checked = !!s.intro_all_caps;
      var anim = document.getElementById('cover_animation');
      if (anim && s.cover_animation) anim.value = s.cover_animation;
      document.querySelectorAll('input[name="cover_media_type"]').forEach(function (el) {
        if (s.cover_media_type) el.checked = el.value === s.cover_media_type;
      });
      var fav = document.querySelector('input[name="cover_from_favorites"]');
      if (fav && typeof s.cover_from_favorites !== 'undefined') fav.checked = !!s.cover_from_favorites;
      var paletteHidden = document.getElementById('design_palette');
      if (paletteHidden && typeof s.design_palette !== 'undefined') paletteHidden.value = s.design_palette || '';
      if (paletteRoot && s.design_palette) {
        paletteRoot.querySelectorAll('.admin-design-palette').forEach(function (btn) {
          var on = btn.getAttribute('data-palette') === s.design_palette;
          btn.classList.toggle('is-selected', on);
          btn.setAttribute('aria-selected', on ? 'true' : 'false');
        });
      }
      syncThemePanels();
      updateCropAspect();
      applyFocal();
      refreshPreview();
    }

    function applyDesignTemplate(id) {
      var tplRoot = document.getElementById('admin-design-templates');
      if (!tplRoot || !id) return;
      var map = {};
      try {
        map = JSON.parse(tplRoot.getAttribute('data-templates') || '{}');
      } catch (e) {
        return;
      }
      var entry = map[id];
      if (!entry || !entry.settings) return;
      applyDesignSettings(entry.settings);
    }

    var presetRoot = document.getElementById('admin-design-presets');
    if (presetRoot) {
      var presets = {};
      try {
        presets = JSON.parse(presetRoot.getAttribute('data-presets') || '{}');
      } catch (e) {
        presets = {};
      }
      presetRoot.querySelectorAll('.admin-design-preset').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var key = btn.getAttribute('data-preset');
          if (!key || !presets[key] || !presets[key].settings) return;
          applyDesignSettings(presets[key].settings);
        });
      });
    }

    var applyTplBtn = document.getElementById('design_template_apply_btn');
    var applyTplSel = document.getElementById('design_template_apply');
    if (applyTplBtn && applyTplSel) {
      applyTplBtn.addEventListener('click', function () {
        applyDesignTemplate(applyTplSel.value);
      });
    }

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
      if (isMoodBlob() || !hasCoverImage(cropImg, base)) return;
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
