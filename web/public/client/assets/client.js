(function () {
  var shareUrl = window.EFPIC_SHARE_URL || window.location.href;
  var shareTitle = window.EFPIC_SHARE_TITLE || document.title;

  var modal = document.getElementById('shareModal');
  var copiedEl = document.getElementById('shareCopied');

  document.querySelectorAll('[data-share-mail]').forEach(function (el) {
    el.href =
      'mailto:?subject=' +
      encodeURIComponent(shareTitle) +
      '&body=' +
      encodeURIComponent(shareUrl);
  });

  document.querySelectorAll('[data-share-whatsapp]').forEach(function (el) {
    el.href =
      'https://wa.me/?text=' +
      encodeURIComponent(shareTitle + ' ' + shareUrl);
  });

  document.querySelectorAll('[data-share-sms]').forEach(function (el) {
    var text = shareTitle + ' ' + shareUrl;
    var isIos = /iPad|iPhone|iPod/.test(navigator.userAgent);
    el.href = isIos
      ? 'sms:&body=' + encodeURIComponent(text)
      : 'sms:?body=' + encodeURIComponent(text);
  });

  function openModal() {
    if (!modal) return;
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
  }

  function closeModal() {
    if (!modal) return;
    modal.hidden = true;
    document.body.style.overflow = '';
    if (copiedEl) copiedEl.textContent = '';
  }

  function copyLink() {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(shareUrl).then(showCopied).catch(fallbackCopy);
    } else {
      fallbackCopy();
    }
  }

  function fallbackCopy() {
    var ta = document.createElement('textarea');
    ta.value = shareUrl;
    ta.setAttribute('readonly', '');
    ta.style.position = 'absolute';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    try {
      document.execCommand('copy');
      showCopied();
    } catch (e) {
      /* ignore */
    }
    document.body.removeChild(ta);
  }

  function showCopied() {
    if (copiedEl) copiedEl.textContent = 'Saite nokopēta';
  }

  function tryNativeShare(evt) {
    if (!navigator.share) return;
    evt.preventDefault();
    navigator.share({ title: shareTitle, url: shareUrl }).catch(function () {
      openModal();
    });
  }

  document.querySelectorAll('[data-share-open]').forEach(function (btn) {
    btn.addEventListener('click', function (evt) {
      if (navigator.share) {
        tryNativeShare(evt);
      } else {
        evt.preventDefault();
        openModal();
      }
    });
  });

  document.querySelectorAll('[data-share-close]').forEach(function (btn) {
    btn.addEventListener('click', closeModal);
  });

  if (modal) {
    modal.addEventListener('click', function (evt) {
      if (evt.target === modal) closeModal();
    });
  }

  document.querySelectorAll('[data-share-copy]').forEach(function (btn) {
    btn.addEventListener('click', copyLink);
  });

  document.addEventListener('keydown', function (evt) {
    if (evt.key === 'Escape') closeModal();
  });

  var dlModal = document.getElementById('downloadModal');
  var dlBase = window.EFPIC_DOWNLOAD_BASE || '';

  function openDlModal() {
    if (!dlModal) return;
    dlModal.hidden = false;
    document.body.style.overflow = 'hidden';
  }

  function closeDlModal() {
    if (!dlModal) return;
    dlModal.hidden = true;
    document.body.style.overflow = '';
  }

  document.querySelectorAll('[data-dl-open]').forEach(function (btn) {
    btn.addEventListener('click', function (evt) {
      evt.preventDefault();
      openDlModal();
    });
  });

  document.querySelectorAll('[data-dl-close]').forEach(function (btn) {
    btn.addEventListener('click', closeDlModal);
  });

  if (dlModal) {
    dlModal.addEventListener('click', function (evt) {
      if (evt.target === dlModal) closeDlModal();
    });
  }

  document.querySelectorAll('[data-dl-size]').forEach(function (btn) {
    btn.addEventListener('click', function (evt) {
      evt.preventDefault();
      var size = btn.getAttribute('data-dl-size') || 'web';
      if (dlBase) {
        window.location.href = dlBase + (dlBase.indexOf('?') >= 0 ? '&' : '?') + 'size=' + encodeURIComponent(size);
      }
      closeDlModal();
    });
  });

  var hero = document.getElementById('galleryHero');
  var floatingTopbar = document.querySelector('.topbar-floating');
  var floatBar = document.querySelector('.gallery-float-bar');

  function scrollPastHero() {
    if (!hero) return false;
    return window.scrollY > hero.offsetHeight - 72;
  }

  function updateFloatingUi() {
    var past = scrollPastHero();
    if (floatingTopbar) {
      floatingTopbar.classList.toggle('is-visible', past);
    }
    if (floatBar) {
      floatBar.classList.toggle('is-visible', past);
    }
  }

  if (hero) {
    window.addEventListener('scroll', updateFloatingUi, { passive: true });
    updateFloatingUi();
  }

  function getMosaicColumnCount() {
    var w = window.innerWidth;
    if (w >= 1200) return 4;
    if (w >= 768) return 3;
    return 2;
  }

  function getContainerInnerWidth(container) {
    var style = window.getComputedStyle(container);
    var pl = parseFloat(style.paddingLeft) || 0;
    var pr = parseFloat(style.paddingRight) || 0;
    return Math.max(0, container.clientWidth - pl - pr);
  }

  function collectFeedItems(container) {
    return Array.prototype.slice.call(container.querySelectorAll(':scope > .pic-feed-item'));
  }

  function unwrapFeedRows(container) {
    Array.prototype.slice.call(container.querySelectorAll(':scope > .pic-feed-row')).forEach(function (rowEl) {
      while (rowEl.firstChild) {
        container.insertBefore(rowEl.firstChild, rowEl);
      }
      rowEl.remove();
    });
  }

  function readAspectRatio(img) {
    var w = img && img.naturalWidth ? img.naturalWidth : 0;
    var h = img && img.naturalHeight ? img.naturalHeight : 0;
    if (w > 0 && h > 0) {
      return w / h;
    }
    return 1.5;
  }

  /** Cik kolonnu platuma bilde aizņem (1–3), kā Pic-Time “enlarge”. */
  function pickColumnSpan(aspect, index, columns) {
    if (columns <= 1) {
      return 1;
    }
    if (aspect < 1.12) {
      return 1;
    }
    if (columns >= 4 && aspect >= 2.1 && index % 7 === 2) {
      return 3;
    }
    if (aspect >= 1.18 && (index % 3 === 1 || aspect >= 1.55)) {
      return Math.min(2, columns);
    }
    return 1;
  }

  function resetMasonryItem(item) {
    item.style.position = '';
    item.style.left = '';
    item.style.top = '';
    item.style.width = '';
    item.style.height = '';
  }

  function layoutColumnMasonry(container) {
    unwrapFeedRows(container);
    var items = collectFeedItems(container);
    if (!items.length) {
      container.style.height = '';
      return;
    }

    items.forEach(resetMasonryItem);

    var gap = parseFloat(window.getComputedStyle(container).gap) || 6;
    var innerWidth = getContainerInnerWidth(container);
    if (innerWidth <= 0) {
      return;
    }

    var columns = getMosaicColumnCount();
    var colWidth = (innerWidth - gap * (columns - 1)) / columns;
    var colHeights = [];
    var c;
    for (c = 0; c < columns; c++) {
      colHeights.push(0);
    }

    items.forEach(function (item, index) {
      var img = item.querySelector('img');
      var aspect = readAspectRatio(img);
      var span = pickColumnSpan(aspect, index, columns);
      var itemWidth = span * colWidth + gap * (span - 1);
      var itemHeight = itemWidth / aspect;

      var bestCol = 0;
      var bestTop = Infinity;
      var startCol;
      for (startCol = 0; startCol <= columns - span; startCol++) {
        var top = 0;
        var s;
        for (s = 0; s < span; s++) {
          if (colHeights[startCol + s] > top) {
            top = colHeights[startCol + s];
          }
        }
        if (top < bestTop) {
          bestTop = top;
          bestCol = startCol;
        }
      }

      var left = bestCol * (colWidth + gap);
      item.style.position = 'absolute';
      item.style.left = Math.round(left) + 'px';
      item.style.top = Math.round(bestTop) + 'px';
      item.style.width = Math.round(itemWidth) + 'px';
      item.style.height = 'auto';

      item.setAttribute(
        'data-orient',
        aspect >= 1.12 ? 'landscape' : aspect <= 0.88 ? 'portrait' : 'square'
      );
      item.setAttribute('data-span', String(span));

      if (img) {
        img.style.width = '100%';
        img.style.height = 'auto';
        img.style.objectFit = '';
        img.style.display = 'block';
      }

      var newBottom = bestTop + itemHeight + gap;
      for (s = 0; s < span; s++) {
        colHeights[bestCol + s] = newBottom;
      }
    });

    var maxH = 0;
    for (c = 0; c < colHeights.length; c++) {
      if (colHeights[c] > maxH) {
        maxH = colHeights[c];
      }
    }
    container.style.height = Math.max(0, Math.ceil(maxH - gap)) + 'px';
  }

  function initMosaicGalleries(done) {
    var containers = document.querySelectorAll('[data-masonry-gallery], [data-justified-gallery]');
    if (!containers.length) {
      if (done) done();
      return;
    }

    var pending = 0;
    var doneCalled = false;

    function relayout() {
      containers.forEach(layoutColumnMasonry);
    }

    function maybeDone() {
      relayout();
      if (!doneCalled) {
        doneCalled = true;
        if (done) done();
      }
    }

    containers.forEach(function (container) {
      container.querySelectorAll('.pic-feed-item img').forEach(function (img) {
        if (img.complete && img.naturalWidth > 0) {
          return;
        }
        pending++;
        function doneImg() {
          pending--;
          relayout();
          if (pending <= 0) {
            maybeDone();
          }
        }
        img.addEventListener('load', doneImg, { once: true });
        img.addEventListener('error', doneImg, { once: true });
      });
    });

    relayout();
    if (pending === 0) {
      maybeDone();
    } else {
      setTimeout(function () {
        relayout();
        maybeDone();
      }, 4000);
    }
  }

  var resizeTimer;
  window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function () {
      document.querySelectorAll('[data-justified-gallery]').forEach(layoutColumnMasonry);
    }, 150);
  });

  function restoreGalleryFocus() {
    var hash = window.location.hash || '';
    if (hash.indexOf('#pic-') !== 0) {
      return;
    }
    var tok = hash.slice(5);
    function scrollToThumb() {
      var el = document.getElementById('pic-' + tok);
      if (!el) {
        return;
      }
      el.scrollIntoView({ block: 'center', behavior: 'auto' });
    }
    scrollToThumb();
    window.addEventListener('load', scrollToThumb);
    setTimeout(scrollToThumb, 120);
    setTimeout(scrollToThumb, 400);
    setTimeout(scrollToThumb, 1200);
  }

  initMosaicGalleries(restoreGalleryFocus);

  document.querySelectorAll('.pic-feed-item[data-token]').forEach(function (link) {
    link.addEventListener('click', function () {
      var tok = link.getAttribute('data-token') || '';
      if (tok !== '') {
        try {
          sessionStorage.setItem('efpic_gallery_scroll', String(window.scrollY));
          sessionStorage.setItem('efpic_gallery_focus', tok);
        } catch (e) {
          /* ignore */
        }
      }
    });
  });

  var prevUrl = window.EFPIC_VIEWER_PREV || '';
  var nextUrl = window.EFPIC_VIEWER_NEXT || '';

  document.addEventListener('keydown', function (evt) {
    if (modal && !modal.hidden) return;
    if (dlModal && !dlModal.hidden) return;
    if (evt.key === 'ArrowLeft' && prevUrl) {
      window.location.href = prevUrl;
    } else if (evt.key === 'ArrowRight' && nextUrl) {
      window.location.href = nextUrl;
    }
  });
})();
