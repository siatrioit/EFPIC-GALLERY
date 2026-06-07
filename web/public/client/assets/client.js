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
    if (copiedEl) copiedEl.textContent = 'Saite nokop─ōta';
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
    if (evt.key !== 'Escape') return;
    closeModal();
    closeDlModal();
    closeZipProgress();
    closeGalleryDlModal();
    closeCollectionDlModal();
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

  var gdlModal = document.getElementById('galleryDownloadModal');
  var cdlModal = document.getElementById('collectionDownloadModal');
  var zipProgressModal = document.getElementById('zipProgressModal');
  var ZIP_DONE_HINT =
    'Skaties p─ürl┼½kprogrammas lejupiel─üd─ōs. Lieliem arh─½viem lejupiel─üde var aiz┼åemt ilg─üku laiku.';
  var gdlBase = window.EFPIC_GALLERY_DL_URL || '';
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
      title: title || 'Sagatavo lejupiel─üdiŌĆ”',
      hint: hint || 'L┼½dzu uzgaidietŌĆ”',
    });
  }

  function showZipProgressDone(title, hint) {
    setZipProgressUi({
      loading: false,
      success: true,
      title: title || 'Gatavs',
      hint: hint || '',
    });
  }

  function showZipProgressError(hint) {
    setZipProgressUi({
      loading: false,
      success: false,
      title: 'Lejupiel─üde neizdev─üs',
      hint: hint || 'Neizdev─üs lejupiel─üd─ōt.',
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
    if ((!gdlModal || gdlModal.hidden) && (!cdlModal || cdlModal.hidden)) {
      document.body.style.overflow = '';
    }
  }

  function openGalleryDlModal() {
    if (!gdlModal) return;
    gdlModal.hidden = false;
    document.body.style.overflow = 'hidden';
  }

  function closeGalleryDlModal() {
    if (!gdlModal) return;
    gdlModal.hidden = true;
    if (!cdlModal || cdlModal.hidden) {
      if (!zipProgressModal || zipProgressModal.hidden) {
        document.body.style.overflow = '';
      }
    }
  }

  function openCollectionDlModal() {
    if (!cdlModal) return;
    var countEl = document.getElementById('collectionTrayCount');
    var count = countEl ? parseInt(countEl.textContent, 10) || 0 : 0;
    if (count <= 0) return;
    cdlModal.hidden = false;
    document.body.style.overflow = 'hidden';
  }

  function closeCollectionDlModal() {
    if (!cdlModal) return;
    cdlModal.hidden = true;
    if (!gdlModal || gdlModal.hidden) {
      if (!zipProgressModal || zipProgressModal.hidden) {
        document.body.style.overflow = '';
      }
    }
  }

  function updateCollectionDownloadTitle(count) {
    var titleEl = document.getElementById('collectionDownloadModalTitle');
    if (!titleEl) return;
    titleEl.textContent =
      count === 1 ? 'Atlas─½t─ü (1) bilde' : 'Atlas─½t─üs (' + count + ') bildes';
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

  function humanZipError(text) {
    if (!text) return 'Neizdev─üs lejupiel─üd─ōt.';
    if (text.indexOf('<') >= 0 || text.indexOf('Internal Server Error') >= 0) {
      return 'Servera timeout ŌĆö izmanto tie┼Īo Failiem lejupiel─üdi (WEB/PRINT pogas).';
    }
    if (text.length > 200) {
      return text.slice(0, 200) + 'ŌĆ”';
    }
    return text;
  }

  function triggerBrowserDownload(url) {
    if (!url) return;
    window.location.assign(url);
  }

  function downloadFailiemZip(failiemUrl, hint, doneTitle) {
    if (!failiemUrl) return;
    openZipProgressLoading('Sagatavo lejupiel─üdiŌĆ”', hint || 'Lejupiel─üde s─ükas no Failiem.lvŌĆ”');
    triggerBrowserDownload(failiemUrl);
    showZipProgressDone(doneTitle || 'Lejupiel─üde s─ükta', ZIP_DONE_HINT);
  }

  function downloadServerZip(url, filename, hint) {
    if (!url) return;
    openZipProgressLoading('Sagatavo lejupiel─üdiŌĆ”', hint || 'Veido ZIP arh─½vuŌĆ”');
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
        showZipProgressDone('Lejupiel─üde gatava', 'ZIP fails saglab─üts.');
      })
      .catch(function (err) {
        zipFetchAbort = null;
        if (err && err.name === 'AbortError') return;
        showZipProgressError(humanZipError(err && err.message ? err.message : ''));
      });
  }

  function startZipDownload(scope, size) {
    if (!gdlBase) return;
    closeGalleryDlModal();
    closeCollectionDlModal();
    var path = scope === 'collection' ? '/collection/zip' : '/download.zip';
    var downloadUrl = gdlBase + path + '?size=' + encodeURIComponent(size);
    var loadingTitle = scope === 'collection' ? 'Sagatavo izlasiŌĆ”' : 'Sagatavo lejupiel─üdiŌĆ”';
    var usesFolderZip =
      scope === 'all' &&
      (window.EFPIC_FAILIEM_FOLDER_ZIP === true || window.EFPIC_FAILIEM_FOLDER_ZIP === '1');

    if (usesFolderZip) {
      openZipProgressLoading(loadingTitle, 'Sagatavo Failiem ZIPŌĆ”');
      triggerBrowserDownload(downloadUrl);
      return;
    }

    openZipProgressLoading(
      loadingTitle,
      'Failiem sagatavo ZIP no redzamaj─üm bild─ōm. Lielai galerijai tas var aiz┼åemt l─½dz 1ŌĆō2 min┼½t─ōm ŌĆö neaizveriet ┼Īo logu.'
    );
    fetch(downloadUrl + '&prepare=1', {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
      .then(function (res) {
        return res.json().then(function (data) {
          if (!res.ok || !data || !data.ok) {
            throw new Error((data && data.error) || 'Neizdev─üs sagatavot lejupiel─üdi');
          }
          return data;
        });
      })
      .then(function (data) {
        if (data.mode === 'failiem' && data.url) {
          showZipProgressDone('Lejupiel─üde s─ükta', ZIP_DONE_HINT);
          triggerBrowserDownload(data.url);
          return;
        }
        if (data.mode === 'stream_ready') {
          showZipProgressDone('Lejupiel─üde s─ükta', ZIP_DONE_HINT);
          triggerBrowserDownload(downloadUrl + '&dl=1');
          return;
        }
        throw new Error('Neatbalst─½ts lejupiel─üdes re┼Š─½ms');
      })
      .catch(function (err) {
        showZipProgressError(humanZipError(err && err.message ? err.message : ''));
      });
  }

  function zipFilenameFor(scope, size) {
    var base = 'galerija-' + size;
    if (scope === 'collection') base = 'izlase-' + size;
    return base + '.zip';
  }

  document.querySelectorAll('[data-gallery-dl-open]').forEach(function (btn) {
    btn.addEventListener('click', function (evt) {
      evt.preventDefault();
      openGalleryDlModal();
    });
  });

  document.querySelectorAll('[data-collection-dl-open]').forEach(function (btn) {
    btn.addEventListener('click', function (evt) {
      evt.preventDefault();
      openCollectionDlModal();
    });
  });

  document.querySelectorAll('[data-gdl-close]').forEach(function (btn) {
    btn.addEventListener('click', closeGalleryDlModal);
  });

  document.querySelectorAll('[data-cdl-close]').forEach(function (btn) {
    btn.addEventListener('click', closeCollectionDlModal);
  });

  document.querySelectorAll('[data-zip-progress-ok]').forEach(function (btn) {
    btn.addEventListener('click', closeZipProgress);
  });

  if (gdlModal) {
    gdlModal.addEventListener('click', function (evt) {
      if (evt.target === gdlModal) closeGalleryDlModal();
    });
  }

  if (cdlModal) {
    cdlModal.addEventListener('click', function (evt) {
      if (evt.target === cdlModal) closeCollectionDlModal();
    });
  }

  document.querySelectorAll('[data-gdl-scope="all"][data-gdl-size]').forEach(function (btn) {
    btn.addEventListener('click', function (evt) {
      evt.preventDefault();
      if (!gdlBase) return;
      var size = btn.getAttribute('data-gdl-size') || 'web';
      startZipDownload('all', size);
    });
  });

  document.querySelectorAll('[data-cdl-size]').forEach(function (btn) {
    btn.addEventListener('click', function (evt) {
      evt.preventDefault();
      var size = btn.getAttribute('data-cdl-size') || 'web';
      startZipDownload('collection', size);
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

  function getGalleryThemeSlug() {
    var body = document.body;
    if (!body || !body.className) {
      return 'efpic-modern';
    }
    var match = body.className.match(/\btheme-(efpic-[a-z]+)\b/);
    return match ? match[1] : 'efpic-modern';
  }

  function getMosaicColumnCount(container) {
    if (container && container.hasAttribute('data-mosaic-columns')) {
      var fixed = parseInt(container.getAttribute('data-mosaic-columns'), 10);
      if (fixed >= 1 && fixed <= 6) {
        return fixed;
      }
    }
    var theme = getGalleryThemeSlug();
    if (theme === 'efpic-mood') {
      return 3;
    }
    if (theme === 'efpic-forest') {
      return 4;
    }
    var w = window.innerWidth;
    if (w >= 1200) {
      return 4;
    }
    if (w >= 768) {
      return 3;
    }
    return 2;
  }

  function getContainerInnerWidth(container) {
    var style = window.getComputedStyle(container);
    var pl = parseFloat(style.paddingLeft) || 0;
    var pr = parseFloat(style.paddingRight) || 0;
    return Math.max(0, container.clientWidth - pl - pr);
  }

  var LAYOUT_ASPECT_MIN = 0.62;
  var LAYOUT_ASPECT_MAX = 2.45;
  var LAYOUT_ASPECT_DEFAULT = 1.5;

  function collectFeedItems(container) {
    return Array.prototype.slice.call(
      container.querySelectorAll(':scope > .pic-feed-item:not(.pic-feed-item--broken)')
    );
  }

  function unwrapFeedRows(container) {
    Array.prototype.slice.call(container.querySelectorAll(':scope > .pic-feed-row')).forEach(function (rowEl) {
      while (rowEl.firstChild) {
        container.insertBefore(rowEl.firstChild, rowEl);
      }
      rowEl.remove();
    });
  }

  function clampLayoutAspect(aspect) {
    return Math.min(LAYOUT_ASPECT_MAX, Math.max(LAYOUT_ASPECT_MIN, aspect));
  }

  function isDeferredFeedImage(img) {
    return !!(img && img.hasAttribute('data-src') && !img.getAttribute('src'));
  }

  function isBrokenFeedImage(img) {
    if (isDeferredFeedImage(img)) {
      return false;
    }
    return !!(img && img.complete && img.naturalWidth === 0);
  }

  function markBrokenFeedItem(item) {
    if (!item) {
      return;
    }
    item.classList.add('pic-feed-item--broken');
    item.style.display = 'none';
  }

  function feedItemHasKnownAspect(img) {
    if (!img) {
      return false;
    }
    if (img.getAttribute('data-aspect')) {
      return true;
    }
    var item = img.closest('.pic-feed-item');
    return !!(item && item.getAttribute('data-aspect'));
  }

  function readAspectRatio(img) {
    if (img) {
      var fromAttr = parseFloat(img.getAttribute('data-aspect'));
      if (!(fromAttr > 0) || !isFinite(fromAttr)) {
        var item = img.closest('.pic-feed-item');
        if (item) {
          fromAttr = parseFloat(item.getAttribute('data-aspect'));
        }
      }
      if (fromAttr > 0 && isFinite(fromAttr)) {
        return clampLayoutAspect(fromAttr);
      }
      var w = img.naturalWidth ? img.naturalWidth : 0;
      var h = img.naturalHeight ? img.naturalHeight : 0;
      if (w > 0 && h > 0) {
        return clampLayoutAspect(w / h);
      }
    }
    return LAYOUT_ASPECT_DEFAULT;
  }

  function measureFeedItemHeight(item, img, itemWidth, aspect) {
    var estimated = itemWidth / aspect;
    var hasKnownAspect = !!(img && img.getAttribute('data-aspect'))
      || !!(item && item.getAttribute('data-aspect'));
    if (hasKnownAspect) {
      return estimated;
    }
    if (img && img.naturalWidth > 0 && img.naturalHeight > 0) {
      return itemWidth * (img.naturalHeight / img.naturalWidth);
    }
    return estimated;
  }

  /** Cik kolonnu platuma bilde aiz┼åem (1ŌĆō3), mosaic layout. Span tikai kad zin─ümi patiesie izm─ōri. */
  function pickColumnSpan(aspect, index, columns, img) {
    if (columns <= 1) {
      return 1;
    }
    var hasKnownAspect = !!(img && img.getAttribute('data-aspect'))
      || !!(img && img.closest('.pic-feed-item') && img.closest('.pic-feed-item').getAttribute('data-aspect'));
    if (!hasKnownAspect && (!img || img.naturalWidth <= 0 || isDeferredFeedImage(img))) {
      return 1;
    }
    if (aspect < 1.12) {
      return 1;
    }
    if (columns >= 4 && aspect >= 2.1 && index % 7 === 2) {
      return 3;
    }
    if (aspect >= 1.35 && (index % 4 === 1 || aspect >= 1.75)) {
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

    var containerStyle = window.getComputedStyle(container);
    var gap = parseFloat(containerStyle.gap) || 16;
    var padLeft = parseFloat(containerStyle.paddingLeft) || 0;
    var padTop = parseFloat(containerStyle.paddingTop) || 0;
    var innerWidth = getContainerInnerWidth(container);
    if (innerWidth <= 0) {
      return;
    }

    var columns = getMosaicColumnCount(container);
    var colWidth = (innerWidth - gap * (columns - 1)) / columns;
    var colHeights = [];
    var c;
    for (c = 0; c < columns; c++) {
      colHeights.push(0);
    }

    items.forEach(function (item, index) {
      var img = item.querySelector('img');
      if (isBrokenFeedImage(img)) {
        markBrokenFeedItem(item);
        return;
      }

      var aspect = readAspectRatio(img);
      var span = pickColumnSpan(aspect, index, columns, img);
      var itemWidth = span * colWidth + gap * (span - 1);

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

      var left = padLeft + bestCol * (colWidth + gap);
      item.style.display = '';
      item.style.position = 'absolute';
      item.style.left = Math.round(left) + 'px';
      item.style.top = Math.round(padTop + bestTop) + 'px';
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

      var itemHeight = measureFeedItemHeight(item, img, itemWidth, aspect);
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

  var mosaicContainers = [];
  var mosaicRelayoutRaf = 0;
  var mosaicRelayoutAgain = false;

  function scheduleMosaicRelayout() {
    if (!mosaicContainers.length) {
      return;
    }
    mosaicRelayoutAgain = true;
    if (mosaicRelayoutRaf) {
      return;
    }
    mosaicRelayoutRaf = requestAnimationFrame(function runMosaicRelayout() {
      mosaicRelayoutRaf = 0;
      if (!mosaicRelayoutAgain) {
        return;
      }
      mosaicRelayoutAgain = false;
      mosaicContainers.forEach(layoutColumnMasonry);
      if (mosaicRelayoutAgain) {
        scheduleMosaicRelayout();
      }
    });
  }

  function bindFeedImageLoad(img) {
    var item = img.closest('.pic-feed-item');
    if (isBrokenFeedImage(img)) {
      markBrokenFeedItem(item);
      scheduleMosaicRelayout();
      return;
    }
    function doneImg() {
      if (isBrokenFeedImage(img)) {
        markBrokenFeedItem(item);
      }
      scheduleMosaicRelayout();
    }
    if (img.complete && img.naturalWidth > 0) {
      doneImg();
      return;
    }
    img.addEventListener('load', doneImg, { once: true });
    img.addEventListener('error', doneImg, { once: true });
  }

  function activateDeferredFeedImage(img) {
    var src = img.getAttribute('data-src');
    if (!src) {
      return;
    }
    img.removeAttribute('data-src');
    img.classList.remove('pic-feed-img--deferred');
    img.src = src;
    bindFeedImageLoad(img);
  }

  function initDeferredFeedImages() {
    var imgs = Array.prototype.slice.call(
      document.querySelectorAll('.pic-feed-item img[data-src]')
    );
    if (!imgs.length) {
      return;
    }

    var eagerLimit = 4;
    imgs.forEach(function (img, index) {
      if (index < eagerLimit) {
        activateDeferredFeedImage(img);
      }
    });

    if (!('IntersectionObserver' in window)) {
      imgs.forEach(function (img) {
        if (img.getAttribute('data-src')) {
          activateDeferredFeedImage(img);
        }
      });
      scheduleMosaicRelayout();
      return;
    }

    var observer = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) {
            return;
          }
          var img = entry.target;
          observer.unobserve(img);
          activateDeferredFeedImage(img);
        });
      },
      { rootMargin: '500px 0px' }
    );

    imgs.forEach(function (img, index) {
      if (index < eagerLimit) {
        return;
      }
      observer.observe(img);
    });
  }

  function initMosaicGalleries(done) {
    mosaicContainers = Array.prototype.slice.call(
      document.querySelectorAll('[data-masonry-gallery], [data-justified-gallery]')
    );

    initDeferredFeedImages();

    if (!mosaicContainers.length) {
      if (done) {
        done();
      }
      return;
    }

    mosaicContainers.forEach(function (container) {
      container.querySelectorAll('.pic-feed-item img').forEach(bindFeedImageLoad);
    });

    scheduleMosaicRelayout();
    if (done) {
      done();
    }
  }

  var resizeTimer;
  window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function () {
      scheduleMosaicRelayout();
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

  document.querySelectorAll('.pic-feed-item[data-token]').forEach(function (item) {
    var link = item.querySelector('.pic-feed-link');
    if (!link) return;
    link.addEventListener('click', function () {
      var tok = item.getAttribute('data-token') || '';
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

  var slideshowEl = document.getElementById('efpic-slideshow');
  var slideshowOpenBtn = document.querySelector('[data-slideshow-open]');
  if (slideshowEl && slideshowOpenBtn) {
    var slideMode = slideshowEl.getAttribute('data-mode') || 'interactive';
    var slideImg = slideshowEl.querySelector('.efpic-slideshow-stage img');
    var slideVideo = slideshowEl.querySelector('.efpic-slideshow-video');
    var slideAudio = slideshowEl.querySelector('.efpic-slideshow-audio');
    var slideClose = slideshowEl.querySelector('.efpic-slideshow-close');
    var dataEl = document.getElementById('efpic-slideshow-data');
    var slides = [];
    var slideIdx = 0;
    var slideTimer = null;
    var intervalSec = parseInt(slideshowEl.getAttribute('data-interval') || '5', 10) * 1000;

    if (slideMode === 'interactive') {
      try {
        slides = JSON.parse(dataEl ? dataEl.textContent || '[]' : '[]');
      } catch (e) {
        slides = [];
      }
    }

    function showSlide(i) {
      if (!slideImg || !slides.length) return;
      slideIdx = ((i % slides.length) + slides.length) % slides.length;
      slideImg.src = slides[slideIdx];
    }

    function stopSlideshow() {
      if (slideTimer) clearInterval(slideTimer);
      slideTimer = null;
      if (slideAudio) {
        slideAudio.pause();
        slideAudio.currentTime = 0;
      }
      if (slideVideo) {
        slideVideo.pause();
        slideVideo.currentTime = 0;
      }
      slideshowEl.hidden = true;
      document.body.style.overflow = '';
    }

    function startSlideshow() {
      if (slideMode === 'video' && slideVideo) {
        slideshowEl.hidden = false;
        document.body.style.overflow = 'hidden';
        slideVideo.currentTime = 0;
        slideVideo.play().catch(function () {});
        return;
      }
      if (!slides.length) return;
      slideshowEl.hidden = false;
      document.body.style.overflow = 'hidden';
      showSlide(0);
      if (slideAudio) {
        slideAudio.currentTime = 0;
        slideAudio.play().catch(function () {});
      }
      if (slideTimer) clearInterval(slideTimer);
      slideTimer = setInterval(function () {
        showSlide(slideIdx + 1);
      }, intervalSec);
    }

    slideshowOpenBtn.addEventListener('click', startSlideshow);
    if (slideClose) slideClose.addEventListener('click', stopSlideshow);
    slideshowEl.addEventListener('click', function (evt) {
      if (evt.target === slideshowEl) stopSlideshow();
    });
    document.addEventListener('keydown', function (evt) {
      if (!slideshowEl.hidden && evt.key === 'Escape') stopSlideshow();
    });
  }

  var sceneNav = document.querySelector('.gallery-scene-nav');
  var sceneLinks = sceneNav ? sceneNav.querySelectorAll('.gallery-scene-nav__link') : [];

  function activateSceneNav(hash) {
    if (!sceneLinks.length || !hash) return;
    sceneLinks.forEach(function (a) {
      a.classList.toggle('is-active', (a.getAttribute('href') || '') === hash);
    });
  }

  function scrollToSceneHash(hash) {
    if (!hash || hash.charAt(0) !== '#') return false;
    var target = document.getElementById(hash.slice(1));
    if (!target) return false;
    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    if (history.replaceState) {
      history.replaceState(null, '', hash);
    }
    activateSceneNav(hash);
    return true;
  }

  document.addEventListener('click', function (evt) {
    var nextBtn = evt.target && evt.target.closest ? evt.target.closest('[data-scene-target]') : null;
    if (nextBtn) {
      var targetHash = nextBtn.getAttribute('data-scene-target') || '';
      if (scrollToSceneHash(targetHash)) {
        evt.preventDefault();
      }
      return;
    }
  });

  if (sceneNav) {
    sceneNav.addEventListener('click', function (evt) {
      var link = evt.target && evt.target.closest ? evt.target.closest('.gallery-scene-nav__link') : null;
      if (!link) return;
      var href = link.getAttribute('href') || '';
      if (scrollToSceneHash(href)) {
        evt.preventDefault();
      }
    });

    if ('IntersectionObserver' in window && sceneLinks.length) {
      var sections = [];
      sceneLinks.forEach(function (link) {
        var id = (link.getAttribute('href') || '').slice(1);
        var el = id ? document.getElementById(id) : null;
        if (el) sections.push({ link: link, el: el });
      });
      var observer = new IntersectionObserver(
        function (entries) {
          var visible = entries.filter(function (e) {
            return e.isIntersecting;
          });
          if (!visible.length) return;
          visible.sort(function (a, b) {
            return b.intersectionRatio - a.intersectionRatio;
          });
          var top = visible[0].target;
          sections.forEach(function (row) {
            row.link.classList.toggle('is-active', row.el === top);
          });
        },
        { rootMargin: '-30% 0px -55% 0px', threshold: [0, 0.15, 0.4] }
      );
      sections.forEach(function (row) {
        observer.observe(row.el);
      });
    }
  }

  var likeUrl = window.EFPIC_LIKE_URL || '';
  function setLikeButtonState(btn, liked) {
    if (!btn) return;
    btn.classList.toggle('is-liked', liked);
    btn.setAttribute('aria-pressed', liked ? 'true' : 'false');
    btn.innerHTML = liked
      ? '<svg viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8z"/></svg>'
      : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8z"/></svg>';
  }

  function setCollectionButtonState(btn, selected) {
    if (!btn) return;
    btn.classList.toggle('is-selected', selected);
    btn.setAttribute('aria-pressed', selected ? 'true' : 'false');
    btn.innerHTML = selected
      ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M8 12l2.5 2.5L16 9"/></svg>'
      : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/></svg>';
  }

  function updateCollectionTray(count) {
    var tray = document.getElementById('collectionTray');
    var countEl = document.getElementById('collectionTrayCount');
    if (!tray) return;
    if (countEl) countEl.textContent = String(count);
    var textEl = tray.querySelector('.collection-tray-text');
    if (textEl && countEl) {
      textEl.innerHTML =
        '<strong id="collectionTrayCount">' +
        count +
        '</strong> ' +
        (count === 1 ? 'bilde izv─ōl─ōta' : 'bildes izv─ōl─ōtas');
    }
    tray.hidden = count <= 0;
    tray.classList.toggle('is-visible', count > 0);
    var dlBtn = document.getElementById('collectionDlBtn');
    if (dlBtn) {
      dlBtn.hidden = count <= 0;
    }
    updateCollectionDownloadTitle(count);
  }

  document.querySelectorAll('[data-like-toggle]').forEach(function (btn) {
    if (btn.getAttribute('data-like-url')) return;
    setLikeButtonState(btn, window.EFPIC_IMAGE_LIKED === '1');
  });

  var collectionToggleUrl = window.EFPIC_COLLECTION_TOGGLE_URL || '';
  var collectionClearUrl = window.EFPIC_COLLECTION_CLEAR_URL || '';
  if (typeof window.EFPIC_COLLECTION_COUNT === 'number') {
    updateCollectionTray(window.EFPIC_COLLECTION_COUNT);
  }

  document.addEventListener('click', function (evt) {
    var likeBtn = evt.target && evt.target.closest ? evt.target.closest('[data-like-toggle]') : null;
    if (likeBtn) {
      var activeLikeUrl = likeBtn.getAttribute('data-like-url') || likeUrl;
      if (!activeLikeUrl) return;
      evt.preventDefault();
      evt.stopPropagation();
      if (likeBtn.disabled) return;
      likeBtn.disabled = true;
      fetch(activeLikeUrl, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (res) {
          return res.json();
        })
        .then(function (data) {
          if (data && data.ok) {
            setLikeButtonState(likeBtn, !!data.liked);
          }
        })
        .catch(function () {
          /* ignore */
        })
        .finally(function () {
          likeBtn.disabled = false;
        });
      return;
    }

    var collectionBtn = evt.target && evt.target.closest ? evt.target.closest('[data-collection-toggle]') : null;
    if (collectionBtn && collectionToggleUrl) {
      evt.preventDefault();
      evt.stopPropagation();
      if (collectionBtn.disabled) return;
      var imageToken = collectionBtn.getAttribute('data-image-token') || '';
      if (imageToken === '') return;
      collectionBtn.disabled = true;
      fetch(collectionToggleUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'image_token=' + encodeURIComponent(imageToken),
      })
        .then(function (res) {
          return res.json();
        })
        .then(function (data) {
          if (data && data.ok) {
            setCollectionButtonState(collectionBtn, !!data.in_collection);
            updateCollectionTray(parseInt(data.count, 10) || 0);
          }
        })
        .catch(function () {
          /* ignore */
        })
        .finally(function () {
          collectionBtn.disabled = false;
        });
      return;
    }

    var clearBtn = evt.target && evt.target.closest ? evt.target.closest('[data-collection-clear]') : null;
    if (clearBtn && collectionClearUrl) {
      evt.preventDefault();
      fetch(collectionClearUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      })
        .then(function (res) {
          return res.json();
        })
        .then(function (data) {
          if (data && data.ok) {
            document.querySelectorAll('[data-collection-toggle]').forEach(function (btn) {
              setCollectionButtonState(btn, false);
            });
            updateCollectionTray(0);
          }
        })
        .catch(function () {
          /* ignore */
        });
    }
  });
})();
