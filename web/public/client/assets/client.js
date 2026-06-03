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

  function getTargetRowHeight() {
    var w = window.innerWidth;
    if (w >= 1200) return 260;
    if (w >= 768) return 220;
    if (w >= 480) return 175;
    return 140;
  }

  function getContainerInnerWidth(container) {
    var style = window.getComputedStyle(container);
    var pl = parseFloat(style.paddingLeft) || 0;
    var pr = parseFloat(style.paddingRight) || 0;
    return Math.max(0, container.clientWidth - pl - pr);
  }

  function collectFeedItems(container) {
    var items = [];
    container.querySelectorAll('.pic-feed-item').forEach(function (el) {
      items.push(el);
    });
    return items;
  }

  function unwrapFeedRows(container) {
    Array.prototype.slice.call(container.querySelectorAll(':scope > .pic-feed-row')).forEach(function (rowEl) {
      while (rowEl.firstChild) {
        container.insertBefore(rowEl.firstChild, rowEl);
      }
      rowEl.remove();
    });
  }

  function buildJustifiedRows(items, containerWidth, targetHeight, gap) {
    var rows = [];
    var row = [];
    var aspectSum = 0;

    function flush(current, sum) {
      if (current.length) {
        rows.push({ items: current.slice(), aspectSum: sum });
      }
    }

    items.forEach(function (item) {
      var img = item.querySelector('img');
      var w = img && img.naturalWidth ? img.naturalWidth : 3;
      var h = img && img.naturalHeight ? img.naturalHeight : 2;
      var aspect = w / h;
      if (!isFinite(aspect) || aspect <= 0) {
        aspect = 1.5;
      }

      row.push({ el: item, aspect: aspect });
      aspectSum += aspect;

      var gaps = gap * (row.length - 1);
      var rowWidth = aspectSum * targetHeight + gaps;
      if (rowWidth >= containerWidth) {
        if (row.length === 1) {
          flush(row, aspectSum);
          row = [];
          aspectSum = 0;
        } else {
          var last = row.pop();
          aspectSum -= last.aspect;
          flush(row, aspectSum);
          row = [last];
          aspectSum = last.aspect;
        }
      }
    });

    if (row.length) {
      rows.push({ items: row, aspectSum: aspectSum });
    }

    return rows;
  }

  function layoutJustifiedGallery(container) {
    unwrapFeedRows(container);
    var items = collectFeedItems(container);
    if (!items.length) {
      return;
    }

    items.forEach(function (item) {
      item.style.width = '';
      item.style.height = '';
    });

    var gap = parseFloat(window.getComputedStyle(container).gap) || 6;
    var containerWidth = getContainerInnerWidth(container);
    if (containerWidth <= 0) {
      return;
    }

    var targetHeight = getTargetRowHeight();
    var rows = buildJustifiedRows(items, containerWidth, targetHeight, gap);

    rows.forEach(function (rowData, idx) {
      var rowEl = document.createElement('div');
      rowEl.className = 'pic-feed-row';
      var isLast = idx === rows.length - 1;
      var count = rowData.items.length;
      var gaps = gap * Math.max(0, count - 1);
      var rowHeight;

      if (isLast && count < 4) {
        rowHeight = targetHeight;
      } else {
        rowHeight = (containerWidth - gaps) / rowData.aspectSum;
      }

      rowData.items.forEach(function (entry) {
        var width = entry.aspect * rowHeight;
        entry.el.style.width = Math.max(1, Math.floor(width)) + 'px';
        entry.el.style.height = Math.max(1, Math.floor(rowHeight)) + 'px';
        rowEl.appendChild(entry.el);
      });

      container.appendChild(rowEl);
    });
  }

  function initJustifiedGalleries(done) {
    var containers = document.querySelectorAll('[data-justified-gallery]');
    if (!containers.length) {
      if (done) done();
      return;
    }

    var pending = 0;
    var finished = false;

    function finish() {
      if (finished) return;
      finished = true;
      containers.forEach(layoutJustifiedGallery);
      if (done) done();
    }

    containers.forEach(function (container) {
      container.querySelectorAll('.pic-feed-item img').forEach(function (img) {
        if (img.complete && img.naturalWidth > 0) {
          return;
        }
        pending++;
        function doneImg() {
          pending--;
          if (pending <= 0) {
            finish();
          }
        }
        img.addEventListener('load', doneImg, { once: true });
        img.addEventListener('error', doneImg, { once: true });
      });
    });

    if (pending === 0) {
      finish();
    } else {
      setTimeout(finish, 3000);
    }
  }

  var resizeTimer;
  window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function () {
      document.querySelectorAll('[data-justified-gallery]').forEach(layoutJustifiedGallery);
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
  }

  initJustifiedGalleries(restoreGalleryFocus);

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
