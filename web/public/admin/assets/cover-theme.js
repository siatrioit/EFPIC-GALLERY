(function () {
  'use strict';

  function initCoverTheme() {
    var root = document.getElementById('admin-cover-theme');
    if (!root) return;

    var themeSelect = document.getElementById('admin-gallery-theme-select');
    var layoutBlock = document.getElementById('admin-cover-layout-block');
    var moodBlock = document.getElementById('admin-mood-theme-block');
    var crop = document.getElementById('admin-cover-crop');
    var frame = document.getElementById('admin-cover-crop-frame');
    var img = document.getElementById('admin-cover-crop-img');
    var fx = document.getElementById('cover_focal_x');
    var fy = document.getElementById('cover_focal_y');
    var layoutInputs = root.querySelectorAll('input[name="cover_layout"]');

    function isMood() {
      return themeSelect && themeSelect.value === 'efpic-mood';
    }

    function hasCoverImage() {
      return !!(img && img.getAttribute('src'));
    }

    function syncThemePanels() {
      var mood = isMood();
      if (layoutBlock) {
        layoutBlock.classList.toggle('is-disabled', mood);
        layoutBlock.querySelectorAll('input, select, button').forEach(function (el) {
          if (el.name === 'cover_layout') {
            el.disabled = mood;
          }
        });
      }
      if (moodBlock) {
        moodBlock.hidden = !mood;
        moodBlock.querySelectorAll('input, select, button').forEach(function (el) {
          el.disabled = !mood;
        });
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
      if (layout === 'half-left' || layout === 'half-right') {
        return '3 / 4';
      }
      if (layout === 'full') {
        return '2 / 1';
      }
      return '16 / 10';
    }

    function updateCropAspect() {
      if (!frame) return;
      var layout = getSelectedLayout();
      frame.style.aspectRatio = layoutAspect(layout);
      if (crop) {
        crop.dataset.layout = layout;
      }
    }

    function applyFocal() {
      if (!img || !fx || !fy) return;
      img.style.objectPosition = fx.value + '% ' + fy.value + '%';
    }

    if (themeSelect) {
      themeSelect.addEventListener('change', function () {
        syncThemePanels();
      });
    }

    layoutInputs.forEach(function (input) {
      input.addEventListener('change', updateCropAspect);
    });

    syncThemePanels();
    updateCropAspect();
    applyFocal();

    if (!frame || !img || !fx || !fy) return;

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
