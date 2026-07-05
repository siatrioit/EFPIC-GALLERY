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
    if (evt.key !== 'Escape') return;
    closeModal();
    closeDlModal();
    closeZipProgress();
    closeGalleryDlModal();
    closeCollectionDlModal();
    closeSceneDlModal();
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
  var gdlBase = window.EFPIC_GALLERY_DL_URL || '';
  var zipFetchAbort = null;
  var zipEmailFollowupTimer = null;

  function clearZipEmailFollowupTimer() {
    if (zipEmailFollowupTimer) {
      clearTimeout(zipEmailFollowupTimer);
      zipEmailFollowupTimer = null;
    }
  }

  function queuedZipEmailErrorMessage(data) {
    var code = data && data.error ? String(data.error) : '';
    if (code === 'download_disabled') return 'Lejupielāde šim izmēram nav atļauta.';
    if (code === 'empty_collection') return 'Izlase ir tukša — pievieno vismaz vienu bildi.';
    if (code === 'not_authenticated') return 'Lūdzu ievadi vārdu un e-pastu, lai saņemtu ZIP.';
    return 'Neizdevās pieprasīt lejupielādi. Mēģini vēlreiz.';
  }

  function showQueuedZipEmailProgress() {
    clearZipEmailFollowupTimer();
    if (!zipProgressModal) return;
    zipProgressModal.hidden = false;
    document.body.style.overflow = 'hidden';
    setZipProgressUi({
      loading: true,
      success: false,
      title: 'ZIP tiek veidots',
      hint: 'Sagatavojam ZIP arhīvu fonā…',
    });
    zipEmailFollowupTimer = setTimeout(function () {
      zipEmailFollowupTimer = null;
      setZipProgressUi({
        loading: false,
        success: true,
        title: 'ZIP tiek veidots',
        hint: 'Tiklīdz ZIP būs gatavs, saņemsi e-pastu ar lejupielādes saiti.',
      });
    }, 2500);
  }

  function submitQueuedZipEmailRequest(url, body) {
    showQueuedZipEmailProgress();
    fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: csrfFetchHeaders({
        Accept: 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded',
      }),
      body: body,
    })
      .then(function (res) {
        return res.json().then(function (data) {
          return { res: res, data: data };
        });
      })
      .then(function (pack) {
        if (pack.res.ok && pack.data && pack.data.ok) {
          return;
        }
        clearZipEmailFollowupTimer();
        showZipProgressError(queuedZipEmailErrorMessage(pack.data));
      })
      .catch(function () {
        clearZipEmailFollowupTimer();
        showZipProgressError('Neizdevās sazināties ar serveri. Mēģini vēlreiz.');
      });
  }

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
    clearZipEmailFollowupTimer();
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
      count === 1 ? 'Atlasītā (1) bilde' : 'Atlasītās (' + count + ') bildes';
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
    if (!text) return 'Neizdevās lejupielādēt.';
    if (text.indexOf('<') >= 0 || text.indexOf('Internal Server Error') >= 0) {
      return 'Servera timeout — izmanto tiešo Failiem lejupielādi (WEB/PRINT pogas).';
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

  function downloadFailiemZip(failiemUrl, hint) {
    if (!failiemUrl) return;
    openZipProgressLoading('Sagatavo lejupielādi…', hint || 'Lejupielāde sākas no Failiem.lv…');
    triggerBrowserDownload(failiemUrl);
    closeZipProgress();
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

  function startZipDownload(scope, size) {
    if (!gdlBase) return;
    closeGalleryDlModal();
    closeCollectionDlModal();
    var path = scope === 'collection' ? '/collection/zip' : '/download.zip';
    var downloadUrl = gdlBase + path + '?size=' + encodeURIComponent(size);
    var loadingTitle = scope === 'collection' ? 'Sagatavo izlasi…' : 'Sagatavo lejupielādi…';
    var usesFolderZip =
      scope === 'all' &&
      (window.EFPIC_FAILIEM_FOLDER_ZIP === true || window.EFPIC_FAILIEM_FOLDER_ZIP === '1');

    if (usesFolderZip) {
      openZipProgressLoading(loadingTitle, 'Sagatavo Failiem ZIP…');
      triggerBrowserDownload(downloadUrl);
      closeZipProgress();
      return;
    }

    openZipProgressLoading(
      loadingTitle,
      'Failiem sagatavo ZIP no redzamajām bildēm. Lielai galerijai tas var aizņemt līdz 1–2 minūtēm — neaizveriet šo logu.'
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
            data.filename || zipFilenameFor(scope, size),
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
      var size = btn.getAttribute('data-gdl-size') || 'web';
      if (window.EFPIC_IS_SHARE_LINK && window.EFPIC_SHARE_DOWNLOAD_URL) {
        requestShareCollectionEmail(size);
        closeGalleryDlModal();
        return;
      }
      if (!gdlBase) return;
      startZipDownload('all', size);
    });
  });

  document.querySelectorAll('[data-cdl-size]').forEach(function (btn) {
    btn.addEventListener('click', function (evt) {
      evt.preventDefault();
      var size = btn.getAttribute('data-cdl-size') || 'web';
      if (collectionEnabled && visitorBaseUrl) {
        requestVisitorCollectionEmail(size);
      } else {
        startZipDownload('collection', size);
      }
    });
  });

  var hero = document.getElementById('galleryHero');
  var floatingTopbar = document.querySelector('.topbar-floating');
  var floatBar = document.querySelector('.gallery-float-bar');
  var scrollTopBtn = document.getElementById('galleryScrollTop');

  function scrollPastHero() {
    if (!hero) return false;
    return window.scrollY > hero.offsetHeight - 72;
  }

  function updateFloatingUi() {
    var past = scrollPastHero();
    if (floatingTopbar) {
      if (floatingTopbar.classList.contains('gallery-toolbar')) {
        floatingTopbar.classList.toggle('is-scrolled', past);
      } else {
        floatingTopbar.classList.toggle('is-visible', past);
      }
    }
    if (floatBar) {
      floatBar.classList.toggle('is-visible', past);
    }
    if (scrollTopBtn) {
      var scrolled = window.scrollY > 320;
      scrollTopBtn.classList.toggle('is-visible', scrolled);
      scrollTopBtn.hidden = !scrolled;
    }
  }

  if (scrollTopBtn) {
    scrollTopBtn.addEventListener('click', function () {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  }

  document.querySelectorAll('.gallery-inline-video-player').forEach(function (wrap) {
    var video = wrap.querySelector('video');
    var playBtn = wrap.querySelector('.gallery-inline-video-play');
    if (!video || !playBtn) {
      return;
    }
    function syncPlayOverlay() {
      var playing = !video.paused && !video.ended;
      wrap.classList.toggle('is-playing', playing);
      // Viena play poga: pauzē paslēpj pārlūka kontroles, rāda tikai overlay.
      video.controls = playing;
    }
    playBtn.addEventListener('click', function () {
      video.play().catch(function () {});
    });
    video.addEventListener('play', syncPlayOverlay);
    video.addEventListener('pause', syncPlayOverlay);
    video.addEventListener('ended', syncPlayOverlay);
    syncPlayOverlay();
  });

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
    var maxCols = 4;
    if (container && container.hasAttribute('data-mosaic-max-columns')) {
      var maxAttr = parseInt(container.getAttribute('data-mosaic-max-columns'), 10);
      if (maxAttr >= 2 && maxAttr <= 6) {
        maxCols = maxAttr;
      }
    } else {
      var theme = getGalleryThemeSlug();
      if (theme === 'efpic-forest') {
        maxCols = 3;
      }
    }
    var w = window.innerWidth;
    if (maxCols >= 4 && w >= 1200) {
      return 4;
    }
    if (maxCols >= 3 && w >= 768) {
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
      container.querySelectorAll(':scope > .pic-feed-item:not(.pic-feed-item--broken):not(.face-search-hidden)')
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

  function aspectRatioMismatch(stored, natural) {
    if (!(stored > 0) || !(natural > 0)) {
      return false;
    }
    return Math.abs(natural - stored) / stored > 0.1;
  }

  function readAspectRatio(img) {
    if (!img) {
      return LAYOUT_ASPECT_DEFAULT;
    }
    var item = img.closest('.pic-feed-item');
    var stored = parseFloat(img.getAttribute('data-aspect'));
    if (!(stored > 0) || !isFinite(stored)) {
      stored = item ? parseFloat(item.getAttribute('data-aspect')) : NaN;
    }
    var natural = 0;
    if (img.naturalWidth > 0 && img.naturalHeight > 0) {
      natural = clampLayoutAspect(img.naturalWidth / img.naturalHeight);
    }
    if (natural > 0 && (!(stored > 0) || !isFinite(stored) || aspectRatioMismatch(stored, natural))) {
      return natural;
    }
    if (stored > 0 && isFinite(stored)) {
      return clampLayoutAspect(stored);
    }
    var dw = parseInt(img.getAttribute('width'), 10);
    var dh = parseInt(img.getAttribute('height'), 10);
    if (dw > 0 && dh > 0) {
      return clampLayoutAspect(dw / dh);
    }
    if (natural > 0) {
      return natural;
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

  /** Cik kolonnu platuma bilde aizņem (1–3), mosaic layout. Span tikai kad zināmi patiesie izmēri. */
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
    if (aspect >= 1.7 && (index % 5 === 2 || aspect >= 1.95)) {
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
      item.style.height = Math.max(1, Math.round(itemHeight)) + 'px';
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

  if (prevUrl || nextUrl) {
    var viewerSwipeTarget =
      document.querySelector('[data-viewer-stage]') ||
      document.querySelector('.viewer-stage') ||
      document.querySelector('.viewer-wrap');
    if (viewerSwipeTarget) {
      var swipeStartX = 0;
      var swipeStartY = 0;
      var swipeTracking = false;
      var swipeMin = 48;

      viewerSwipeTarget.addEventListener(
        'touchstart',
        function (evt) {
          if (!evt.touches || evt.touches.length !== 1) return;
          swipeStartX = evt.touches[0].clientX;
          swipeStartY = evt.touches[0].clientY;
          swipeTracking = true;
        },
        { passive: true }
      );

      viewerSwipeTarget.addEventListener(
        'touchend',
        function (evt) {
          if (!swipeTracking || !evt.changedTouches || evt.changedTouches.length !== 1) return;
          swipeTracking = false;
          var dx = evt.changedTouches[0].clientX - swipeStartX;
          var dy = evt.changedTouches[0].clientY - swipeStartY;
          if (Math.abs(dx) < swipeMin || Math.abs(dx) <= Math.abs(dy)) return;
          if (dx < 0 && nextUrl) {
            window.location.href = nextUrl;
          } else if (dx > 0 && prevUrl) {
            window.location.href = prevUrl;
          }
        },
        { passive: true }
      );
    }
  }

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
  var csrfToken = window.EFPIC_CSRF_TOKEN || '';

  function csrfFetchHeaders(extra) {
    var headers = extra || {};
    if (csrfToken) {
      headers['X-CSRF-Token'] = csrfToken;
    }
    return headers;
  }
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

  var collectionEnabled =
    window.EFPIC_COLLECTION_ENABLED === true || window.EFPIC_COLLECTION_ENABLED === '1';
  var canCollectionZip =
    collectionEnabled &&
    window.EFPIC_CAN_COLLECTION_ZIP !== false &&
    window.EFPIC_CAN_COLLECTION_ZIP !== '0';
  var visitorBaseUrl = window.EFPIC_VISITOR_BASE_URL || '';
  var visitorModal = document.getElementById('visitorCollectionModal');
  var visitorManageModal = document.getElementById('visitorManageModal');
  var pendingCollectionImageToken = '';
  var visitorRenameEditingId = '';

  var visitorState = {
    authenticated:
      window.EFPIC_VISITOR_AUTHENTICATED === true || window.EFPIC_VISITOR_AUTHENTICATED === '1',
    name: window.EFPIC_VISITOR_NAME || '',
    email: window.EFPIC_VISITOR_EMAIL || '',
    activeCollection: window.EFPIC_VISITOR_ACTIVE_COLLECTION || { id: '', name: '', count: 0 },
    collections: window.EFPIC_VISITOR_COLLECTIONS || [],
    activeTokens: {},
  };

  function visitorUrl(path) {
    if (!visitorBaseUrl) return '';
    return visitorBaseUrl + path;
  }

  function applyActiveTokensFromMap(tokenMap) {
    var tokens = tokenMap || {};
    document.querySelectorAll('[data-collection-toggle]').forEach(function (btn) {
      var tok = btn.getAttribute('data-image-token') || '';
      setCollectionButtonState(btn, !!tokens[tok]);
    });
  }

  function updateCollectionTrayLabel(name) {
    var labelEl = document.getElementById('collectionTrayLabel');
    if (!labelEl) return;
    if (name) {
      labelEl.textContent = name + ' · ';
      labelEl.hidden = false;
    } else {
      labelEl.textContent = '';
      labelEl.hidden = true;
    }
  }

  var collectionFilterActive = false;
  var collectionFilterRestore = [];

  function getCollectionFilterCount() {
    var count = 0;
    var tokens = visitorState.activeTokens || {};
    Object.keys(tokens).forEach(function (tok) {
      if (tokens[tok]) count += 1;
    });
    if (count > 0) return count;
    if (visitorState.activeCollection && visitorState.activeCollection.count) {
      return parseInt(visitorState.activeCollection.count, 10) || 0;
    }
    return 0;
  }

  function getCollectionGalleryItems() {
    return Array.prototype.slice.call(
      document.querySelectorAll('.pic-feed-item[data-token], .grid-card[data-token], .feed-card[data-token]')
    );
  }

  function restoreCollectionFilterDom() {
    collectionFilterRestore.slice().reverse().forEach(function (entry) {
      if (!entry.el || !entry.parent) return;
      if (entry.next && entry.next.parentNode === entry.parent) {
        entry.parent.insertBefore(entry.el, entry.next);
      } else {
        entry.parent.appendChild(entry.el);
      }
    });
    collectionFilterRestore = [];
    document.querySelectorAll('.collection-filter-feed-inactive').forEach(function (feed) {
      feed.classList.remove('collection-filter-feed-inactive');
    });
  }

  function consolidateVisibleCollectionItems(visibleItems) {
    var mosaicFeeds = document.querySelectorAll('[data-masonry-gallery], [data-justified-gallery]');
    if (!mosaicFeeds.length) return;
    var target = mosaicFeeds[0];
    var feedItems = visibleItems.filter(function (el) {
      return el.classList.contains('pic-feed-item');
    });
    feedItems.forEach(function (item) {
      collectionFilterRestore.push({
        el: item,
        parent: item.parentNode,
        next: item.nextSibling,
      });
      target.appendChild(item);
    });
    mosaicFeeds.forEach(function (feed, idx) {
      feed.classList.toggle('collection-filter-feed-inactive', idx > 0);
    });
  }

  function updateCollectionFilterScenes() {
    document.querySelectorAll('.scene-block').forEach(function (scene) {
      var hasVisible = scene.querySelector(
        '.pic-feed-item[data-token]:not(.face-search-hidden):not(.collection-filter-hidden), .grid-card[data-token]:not(.face-search-hidden):not(.collection-filter-hidden)'
      );
      scene.classList.toggle('collection-filter-scene-empty', collectionFilterActive && !hasVisible);
    });
  }

  function applyCollectionFilter() {
    if (!collectionFilterActive) return;
    restoreCollectionFilterDom();
    var tokens = visitorState.activeTokens || {};
    var visible = [];
    getCollectionGalleryItems().forEach(function (el) {
      var tok = el.getAttribute('data-token') || '';
      if (tok === '') return;
      var show = !!tokens[tok];
      el.classList.toggle('collection-filter-hidden', !show);
      if (show && !el.classList.contains('face-search-hidden')) {
        visible.push(el);
      }
    });
    document.body.classList.add('collection-filter-active');
    consolidateVisibleCollectionItems(visible);
    updateCollectionFilterScenes();
    updateCollectionFilterToolbar();
    scheduleMosaicRelayout();
  }

  function clearCollectionFilter() {
    collectionFilterActive = false;
    document.body.classList.remove('collection-filter-active');
    restoreCollectionFilterDom();
    document.querySelectorAll('.collection-filter-hidden').forEach(function (el) {
      el.classList.remove('collection-filter-hidden');
    });
    document.querySelectorAll('.collection-filter-scene-empty').forEach(function (el) {
      el.classList.remove('collection-filter-scene-empty');
    });
    updateCollectionFilterToolbar();
    scheduleMosaicRelayout();
  }

  function setCollectionFilterActive(active) {
    collectionFilterActive = !!active;
    if (collectionFilterActive) {
      applyCollectionFilter();
    } else {
      clearCollectionFilter();
    }
  }

  function updateCollectionFilterToolbar() {
    var toolbar = document.getElementById('collectionFilterToolbar');
    var toggleBtn = document.getElementById('collectionFilterToggle');
    var textEl = document.getElementById('collectionFilterToolbarText');
    if (!toolbar || !toggleBtn) return;
    var count = getCollectionFilterCount();
    var show = collectionEnabled && count > 0;
    toolbar.hidden = !show;
    if (!show) {
      if (collectionFilterActive) clearCollectionFilter();
      return;
    }
    var collName =
      visitorState.activeCollection && visitorState.activeCollection.name
        ? visitorState.activeCollection.name
        : '';
    if (collectionFilterActive) {
      toggleBtn.textContent = 'Rādīt visas';
      if (textEl) {
        textEl.textContent =
          'Rāda ' +
          count +
          (count === 1 ? ' izlases bildi' : ' izlases bildes') +
          (collName ? ' («' + collName + '»)' : '');
      }
    } else {
      toggleBtn.textContent = 'Rādīt izlasi';
      if (textEl) {
        textEl.textContent = collName
          ? collName + ' · ' + count + (count === 1 ? ' bilde' : ' bildes')
          : count + (count === 1 ? ' bilde izlases' : ' bildes izlases');
      }
    }
  }

  function syncCollectionFilterAfterCollectionChange() {
    if (!collectionFilterActive) {
      updateCollectionFilterToolbar();
      return;
    }
    var count = getCollectionFilterCount();
    if (count <= 0) {
      clearCollectionFilter();
      return;
    }
    applyCollectionFilter();
  }

  function initCollectionFilter() {
    if (!collectionEnabled) return;
    var toggleBtn = document.getElementById('collectionFilterToggle');
    if (!toggleBtn) return;
    toggleBtn.addEventListener('click', function () {
      if (getCollectionFilterCount() <= 0) return;
      setCollectionFilterActive(!collectionFilterActive);
    });
    updateCollectionFilterToolbar();
  }

  function updateCollectionTray(count) {
    var tray = document.getElementById('collectionTray');
    var countEl = document.getElementById('collectionTrayCount');
    if (!tray) return;
    if (countEl) countEl.textContent = String(count);
    var textEl = tray.querySelector('.collection-tray-text');
    var collName = visitorState.activeCollection && visitorState.activeCollection.name
      ? visitorState.activeCollection.name
      : '';
    if (textEl && countEl) {
      var labelHtml = collName
        ? '<span class="collection-tray-label" id="collectionTrayLabel">' +
          collName.replace(/</g, '&lt;') +
          ' · </span>'
        : '<span class="collection-tray-label" id="collectionTrayLabel" hidden></span>';
      textEl.innerHTML =
        labelHtml +
        '<strong id="collectionTrayCount">' +
        count +
        '</strong> ' +
        (count === 1 ? 'bilde izvēlēta' : 'bildes izvēlētas');
    } else {
      updateCollectionTrayLabel(collName);
    }
    var showTray = count > 0 || visitorState.authenticated;
    tray.hidden = !showTray;
    tray.classList.toggle('is-visible', showTray);
    if (floatBar) {
      floatBar.classList.toggle('is-suppressed', count > 0);
    }
    var dlBtn = document.getElementById('collectionDlBtn');
    if (dlBtn) {
      dlBtn.hidden = count <= 0 || !canCollectionZip;
    }
    var manageBtn = document.getElementById('visitorManageBtn');
    if (manageBtn) {
      manageBtn.hidden = !visitorState.authenticated;
    }
    updateCollectionDownloadTitle(count);
    updateCollectionFilterToolbar();
  }

  function applyVisitorState(data) {
    if (!data) return;
    visitorState.authenticated = !!data.authenticated;
    if (data.visitor) {
      visitorState.name = data.visitor.name || '';
      visitorState.email = data.visitor.email || '';
    }
    if (data.active_collection) {
      visitorState.activeCollection = data.active_collection;
    }
    if (data.collections) {
      visitorState.collections = data.collections;
    }
    if (data.active_tokens) {
      visitorState.activeTokens = data.active_tokens;
      applyActiveTokensFromMap(data.active_tokens);
    }
    var count =
      (visitorState.activeCollection && visitorState.activeCollection.count) ||
      (data.active_collection && data.active_collection.count) ||
      0;
    updateCollectionTray(count);
    syncCollectionFilterAfterCollectionChange();
  }

  function openVisitorCollectionEntry() {
    if (!collectionEnabled) return;
    pendingCollectionImageToken = '';
    if (visitorState.authenticated) {
      openVisitorManageModal();
    } else {
      openVisitorModal();
    }
  }

  function openVisitorModal() {
    if (!visitorModal) return;
    var nameInput = document.getElementById('visitorNameInput');
    var emailInput = document.getElementById('visitorEmailInput');
    if (nameInput && visitorState.name) nameInput.value = visitorState.name;
    if (emailInput && visitorState.email) emailInput.value = visitorState.email;
    visitorModal.hidden = false;
    document.body.style.overflow = 'hidden';
  }

  function closeVisitorModal() {
    if (!visitorModal) return;
    visitorModal.hidden = true;
    var errEl = document.getElementById('visitorFormError');
    if (errEl) errEl.hidden = true;
    if (
      (!cdlModal || cdlModal.hidden) &&
      (!gdlModal || gdlModal.hidden) &&
      (!visitorManageModal || visitorManageModal.hidden) &&
      (!zipProgressModal || zipProgressModal.hidden)
    ) {
      document.body.style.overflow = '';
    }
  }

  function openVisitorManageModal() {
    if (!visitorManageModal) return;
    visitorRenameEditingId = '';
    var greeting = document.getElementById('visitorManageGreeting');
    if (greeting) {
      greeting.textContent = visitorState.name
        ? 'Sveiki, ' + visitorState.name + '!'
        : '';
    }
    renderVisitorCollectionList();
    visitorManageModal.hidden = false;
    document.body.style.overflow = 'hidden';
  }

  function closeVisitorManageModal() {
    if (!visitorManageModal) return;
    visitorManageModal.hidden = true;
    if (
      (!cdlModal || cdlModal.hidden) &&
      (!gdlModal || gdlModal.hidden) &&
      (!visitorModal || visitorModal.hidden) &&
      (!zipProgressModal || zipProgressModal.hidden)
    ) {
      document.body.style.overflow = '';
    }
  }

  function renderVisitorCollectionList() {
    var list = document.getElementById('visitorCollectionList');
    if (!list) return;
    list.innerHTML = '';
    var activeId = visitorState.activeCollection ? visitorState.activeCollection.id : '';
    (visitorState.collections || []).forEach(function (coll) {
      var li = document.createElement('li');
      li.className =
        'visitor-collection-item' +
        (coll.id === activeId ? ' is-active' : '') +
        (visitorRenameEditingId === coll.id ? ' is-editing' : '');
      var main = document.createElement('div');
      main.className = 'visitor-collection-item-main';

      if (visitorRenameEditingId === coll.id) {
        var row = document.createElement('div');
        row.className = 'visitor-collection-rename-row';
        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'visitor-collection-rename-input';
        input.value = coll.name || '';
        input.setAttribute('aria-label', 'Izlases nosaukums');
        row.appendChild(input);
        main.appendChild(row);
      } else {
        var label = document.createElement('button');
        label.type = 'button';
        label.className = 'visitor-collection-item-label is-editable';
        label.textContent = (coll.name || 'Izlase') + ' (' + (coll.count || 0) + ')';
        label.setAttribute('data-visitor-rename', coll.id);
        label.setAttribute('aria-label', 'Pārsaukt izlasi');
        main.appendChild(label);
      }

      li.appendChild(main);

      var actions = document.createElement('div');
      actions.className = 'visitor-collection-item-actions';
      if (coll.id !== activeId) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn visitor-collection-activate-btn';
        btn.textContent = 'Aktivizēt';
        btn.setAttribute('data-visitor-activate', coll.id);
        actions.appendChild(btn);
      } else {
        var tag = document.createElement('span');
        tag.className = 'visitor-collection-active-tag';
        tag.textContent = 'Aktīva';
        actions.appendChild(tag);
      }
      li.appendChild(actions);
      list.appendChild(li);
    });
  }

  function renameVisitorCollection(collectionId, newName) {
    if (!visitorBaseUrl || !collectionId || !newName) return Promise.resolve(null);
    var body =
      'name=' +
      encodeURIComponent(newName) +
      (csrfToken ? '&csrf_token=' + encodeURIComponent(csrfToken) : '');
    return fetch(visitorUrl('/collections/' + encodeURIComponent(collectionId) + '/rename'), {
      method: 'POST',
      credentials: 'same-origin',
      headers: csrfFetchHeaders({
        Accept: 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded',
      }),
      body: body,
    })
      .then(function (res) {
        return res.json();
      })
      .then(function (data) {
        if (data && data.ok) {
          visitorRenameEditingId = '';
          applyVisitorState({
            authenticated: true,
            visitor: { name: visitorState.name, email: visitorState.email },
            active_collection: data.active_collection,
            collections: data.collections,
            active_tokens: visitorState.activeTokens,
          });
          renderVisitorCollectionList();
        }
        return data;
      });
  }

  function submitVisitorIdentify() {
    if (!visitorBaseUrl) return Promise.resolve(null);
    var nameInput = document.getElementById('visitorNameInput');
    var emailInput = document.getElementById('visitorEmailInput');
    var errEl = document.getElementById('visitorFormError');
    var submitBtn = document.getElementById('visitorCollectionSubmit');
    var name = nameInput ? nameInput.value.trim() : '';
    var email = emailInput ? emailInput.value.trim() : '';
    if (submitBtn) submitBtn.disabled = true;
    if (errEl) errEl.hidden = true;
    var body =
      'name=' +
      encodeURIComponent(name) +
      '&email=' +
      encodeURIComponent(email);
    if (csrfToken) {
      body += '&csrf_token=' + encodeURIComponent(csrfToken);
    }
    return fetch(visitorUrl('/identify'), {
      method: 'POST',
      credentials: 'same-origin',
      headers: csrfFetchHeaders({
        Accept: 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded',
      }),
      body: body,
    })
      .then(function (res) {
        return res.json();
      })
      .then(function (data) {
        if (!data || !data.ok) {
          if (errEl) {
            errEl.textContent = (data && data.error) || 'Neizdevās reģistrēties.';
            errEl.hidden = false;
          }
          return null;
        }
        applyVisitorState({
          authenticated: true,
          visitor: data.visitor,
          active_collection: data.active_collection,
          collections: data.collections,
          active_tokens: data.active_tokens,
        });
        closeVisitorModal();
        return data;
      })
      .catch(function () {
        if (errEl) {
          errEl.textContent = 'Neizdevās sazināties ar serveri.';
          errEl.hidden = false;
        }
        return null;
      })
      .finally(function () {
        if (submitBtn) submitBtn.disabled = false;
      });
  }

  function createFaceVisitorCollection(imageTokens) {
    if (!visitorBaseUrl || !visitorState.authenticated) return Promise.resolve(null);
    if (!imageTokens || !imageTokens.length) return Promise.resolve(null);
    var body =
      'image_tokens=' +
      encodeURIComponent(imageTokens.join(',')) +
      (csrfToken ? '&csrf_token=' + encodeURIComponent(csrfToken) : '');
    return fetch(visitorUrl('/collections/face'), {
      method: 'POST',
      credentials: 'same-origin',
      headers: csrfFetchHeaders({
        Accept: 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded',
      }),
      body: body,
    })
      .then(function (res) {
        return res.json();
      })
      .then(function (data) {
        if (data && data.ok) {
          applyVisitorState({
            authenticated: true,
            visitor: { name: visitorState.name, email: visitorState.email },
            active_collection: data.active_collection,
            collections: data.collections,
            active_tokens: data.active_tokens || {},
          });
          renderVisitorCollectionList();
        }
        return data;
      });
  }

  function addVisitorCollectionTokens(imageTokens) {
    if (!visitorBaseUrl || !visitorState.authenticated) return Promise.resolve(null);
    var collId = visitorState.activeCollection ? visitorState.activeCollection.id : '';
    if (!collId || !imageTokens || !imageTokens.length) return Promise.resolve(null);
    var body =
      'image_tokens=' +
      encodeURIComponent(imageTokens.join(',')) +
      (csrfToken ? '&csrf_token=' + encodeURIComponent(csrfToken) : '');
    return fetch(visitorUrl('/collections/' + encodeURIComponent(collId) + '/add-tokens'), {
      method: 'POST',
      credentials: 'same-origin',
      headers: csrfFetchHeaders({
        Accept: 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded',
      }),
      body: body,
    })
      .then(function (res) {
        return res.json();
      })
      .then(function (data) {
        if (data && data.ok) {
          if (data.active_collection) {
            visitorState.activeCollection = data.active_collection;
            visitorState.collections = (visitorState.collections || []).map(function (c) {
              return c.id === data.active_collection.id ? data.active_collection : c;
            });
          }
          if (data.active_tokens) {
            visitorState.activeTokens = data.active_tokens;
            applyActiveTokensFromMap(data.active_tokens);
          }
          updateCollectionTray(parseInt(data.count, 10) || 0);
          syncCollectionFilterAfterCollectionChange();
        }
        return data;
      });
  }

  function toggleVisitorCollectionImage(imageToken) {
    if (!visitorBaseUrl || !visitorState.authenticated) return Promise.resolve(null);
    var collId = visitorState.activeCollection ? visitorState.activeCollection.id : '';
    if (!collId) return Promise.resolve(null);
    var body =
      'image_token=' +
      encodeURIComponent(imageToken) +
      (csrfToken ? '&csrf_token=' + encodeURIComponent(csrfToken) : '');
    return fetch(visitorUrl('/collections/' + encodeURIComponent(collId) + '/toggle'), {
      method: 'POST',
      credentials: 'same-origin',
      headers: csrfFetchHeaders({
        Accept: 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded',
      }),
      body: body,
    })
      .then(function (res) {
        return res.json();
      })
      .then(function (data) {
        if (data && data.ok) {
          if (data.active_collection) {
            visitorState.activeCollection = data.active_collection;
            visitorState.collections = (visitorState.collections || []).map(function (c) {
              return c.id === data.active_collection.id ? data.active_collection : c;
            });
          }
          if (data.in_collection) {
            visitorState.activeTokens[imageToken] = true;
          } else {
            delete visitorState.activeTokens[imageToken];
          }
          applyActiveTokensFromMap(visitorState.activeTokens);
          updateCollectionTray(parseInt(data.count, 10) || 0);
          syncCollectionFilterAfterCollectionChange();
        }
        return data;
      });
  }

  function activateVisitorCollection(collectionId) {
    if (!visitorBaseUrl || !collectionId) return;
    fetch(visitorUrl('/collections/' + encodeURIComponent(collectionId) + '/activate'), {
      method: 'POST',
      credentials: 'same-origin',
      headers: csrfFetchHeaders({ Accept: 'application/json' }),
    })
      .then(function (res) {
        return res.json();
      })
      .then(function (data) {
        if (data && data.ok) {
          applyVisitorState({
            authenticated: true,
            visitor: { name: visitorState.name, email: visitorState.email },
            active_collection: data.active_collection,
            collections: visitorState.collections,
            active_tokens: data.active_tokens,
          });
          renderVisitorCollectionList();
        }
      });
  }

  function createVisitorCollection(name) {
    if (!visitorBaseUrl || !name) return Promise.resolve(null);
    var body =
      'name=' +
      encodeURIComponent(name) +
      (csrfToken ? '&csrf_token=' + encodeURIComponent(csrfToken) : '');
    return fetch(visitorUrl('/collections'), {
      method: 'POST',
      credentials: 'same-origin',
      headers: csrfFetchHeaders({
        Accept: 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded',
      }),
      body: body,
    })
      .then(function (res) {
        return res.json();
      })
      .then(function (data) {
        if (data && data.ok) {
          applyVisitorState({
            authenticated: true,
            visitor: { name: visitorState.name, email: visitorState.email },
            active_collection: data.active_collection,
            collections: data.collections,
            active_tokens: data.active_tokens || {},
          });
          renderVisitorCollectionList();
        }
        return data;
      });
  }

  function requestVisitorCollectionEmail(size) {
    if (!visitorBaseUrl || !visitorState.authenticated) {
      openVisitorModal();
      return;
    }
    closeCollectionDlModal();
    var body =
      'size=' +
      encodeURIComponent(size) +
      (csrfToken ? '&csrf_token=' + encodeURIComponent(csrfToken) : '');
    submitQueuedZipEmailRequest(visitorUrl('/download-all'), body);
  }

  function requestShareCollectionEmail(size) {
    var shareUrl = window.EFPIC_SHARE_DOWNLOAD_URL || '';
    if (!shareUrl) return;
    if (!visitorState.authenticated) {
      openVisitorModal();
      return;
    }
    closeGalleryDlModal();
    var body =
      'size=' +
      encodeURIComponent(size) +
      (csrfToken ? '&csrf_token=' + encodeURIComponent(csrfToken) : '');
    submitQueuedZipEmailRequest(shareUrl, body);
  }

  function visitorLogout() {
    if (!visitorBaseUrl) return;
    fetch(visitorUrl('/logout'), {
      method: 'POST',
      credentials: 'same-origin',
      headers: csrfFetchHeaders({ Accept: 'application/json' }),
    })
      .then(function () {
        visitorState = {
          authenticated: false,
          name: '',
          email: '',
          activeCollection: { id: '', name: '', count: 0 },
          collections: [],
          activeTokens: {},
        };
        applyActiveTokensFromMap({});
        updateCollectionTray(0);
        closeVisitorManageModal();
      });
  }

  if (typeof window.EFPIC_COLLECTION_COUNT === 'number') {
    updateCollectionTray(window.EFPIC_COLLECTION_COUNT);
  }
  if (visitorState.authenticated && visitorState.activeCollection) {
    updateCollectionTray(visitorState.activeCollection.count || window.EFPIC_COLLECTION_COUNT || 0);
    document.querySelectorAll('[data-collection-toggle].is-selected').forEach(function (btn) {
      var tok = btn.getAttribute('data-image-token') || '';
      if (tok) visitorState.activeTokens[tok] = true;
    });
  }

  document.querySelectorAll('[data-like-toggle]').forEach(function (btn) {
    if (btn.getAttribute('data-like-url')) return;
    setLikeButtonState(btn, window.EFPIC_IMAGE_LIKED === '1');
  });

  if (visitorModal) {
    visitorModal.addEventListener('click', function (evt) {
      if (evt.target === visitorModal) closeVisitorModal();
    });
  }
  if (visitorManageModal) {
    visitorManageModal.addEventListener('click', function (evt) {
      if (evt.target === visitorManageModal) closeVisitorManageModal();
    });
  }
  document.querySelectorAll('[data-visitor-modal-close]').forEach(function (btn) {
    btn.addEventListener('click', closeVisitorModal);
  });
  document.querySelectorAll('[data-visitor-manage-close]').forEach(function (btn) {
    btn.addEventListener('click', closeVisitorManageModal);
  });
  document.querySelectorAll('[data-visitor-manage-save]').forEach(function (btn) {
    btn.addEventListener('click', closeVisitorManageModal);
  });
  var visitorForm = document.getElementById('visitorCollectionForm');
  if (visitorForm) {
    visitorForm.addEventListener('submit', function (evt) {
      evt.preventDefault();
      submitVisitorIdentify().then(function (data) {
        if (data && pendingCollectionImageToken) {
          var tok = pendingCollectionImageToken;
          pendingCollectionImageToken = '';
          toggleVisitorCollectionImage(tok);
        }
      });
    });
  }
  var newCollForm = document.getElementById('visitorNewCollectionForm');
  if (newCollForm) {
    newCollForm.addEventListener('submit', function (evt) {
      evt.preventDefault();
      var input = document.getElementById('visitorNewCollectionInput');
      var name = input ? input.value.trim() : '';
      if (!name) return;
      createVisitorCollection(name).then(function () {
        if (input) input.value = '';
      });
    });
  }
  var logoutBtn = document.getElementById('visitorLogoutBtn');
  if (logoutBtn) {
    logoutBtn.addEventListener('click', visitorLogout);
  }
  document.addEventListener('keydown', function (evt) {
    if (evt.key === 'Escape') {
      var renameInput =
        evt.target && evt.target.closest ? evt.target.closest('.visitor-collection-rename-input') : null;
      if (renameInput) {
        evt.preventDefault();
        visitorRenameEditingId = '';
        renderVisitorCollectionList();
      }
      return;
    }
    if (evt.key !== 'Enter') return;
    var renameInputEnter =
      evt.target && evt.target.closest ? evt.target.closest('.visitor-collection-rename-input') : null;
    if (!renameInputEnter) return;
    evt.preventDefault();
    renameInputEnter.blur();
  });

  document.addEventListener(
    'blur',
    function (evt) {
      var renameInput =
        evt.target && evt.target.classList && evt.target.classList.contains('visitor-collection-rename-input')
          ? evt.target
          : null;
      if (!renameInput) return;
      var row = renameInput.closest('.visitor-collection-rename-row');
      var li = renameInput.closest('.visitor-collection-item');
      if (!li) return;
      var collId = visitorRenameEditingId;
      var newName = renameInput.value.trim();
      var collections = visitorState.collections || [];
      var prevName = '';
      collections.forEach(function (c) {
        if (c.id === collId) prevName = c.name || '';
      });
      visitorRenameEditingId = '';
      if (!newName || newName === prevName) {
        renderVisitorCollectionList();
        return;
      }
      renameVisitorCollection(collId, newName);
    },
    true
  );

  document.addEventListener('click', function (evt) {
    var activateBtn =
      evt.target && evt.target.closest ? evt.target.closest('[data-visitor-activate]') : null;
    if (activateBtn) {
      evt.preventDefault();
      activateVisitorCollection(activateBtn.getAttribute('data-visitor-activate') || '');
      return;
    }
    var renameBtn =
      evt.target && evt.target.closest ? evt.target.closest('[data-visitor-rename]') : null;
    if (renameBtn) {
      evt.preventDefault();
      visitorRenameEditingId = renameBtn.getAttribute('data-visitor-rename') || '';
      renderVisitorCollectionList();
      var editInput = document.querySelector('.visitor-collection-rename-input');
      if (editInput) {
        editInput.focus();
        editInput.select();
      }
      return;
    }
    var manageOpen =
      evt.target && evt.target.closest ? evt.target.closest('[data-visitor-manage-open]') : null;
    if (manageOpen) {
      evt.preventDefault();
      openVisitorManageModal();
    }
    var collectionOpen =
      evt.target && evt.target.closest ? evt.target.closest('[data-visitor-collection-open]') : null;
    if (collectionOpen) {
      evt.preventDefault();
      openVisitorCollectionEntry();
    }
  });

  document.addEventListener('click', function (evt) {
    var likeBtn = evt.target && evt.target.closest ? evt.target.closest('[data-like-toggle]') : null;
    if (likeBtn) {
      var activeLikeUrl = likeBtn.getAttribute('data-like-url') || likeUrl;
      if (!activeLikeUrl) return;
      evt.preventDefault();
      evt.stopPropagation();
      if (likeBtn.disabled) return;
      likeBtn.disabled = true;
      fetch(activeLikeUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: csrfFetchHeaders({ Accept: 'application/json' }),
      })
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
    if (collectionBtn && collectionEnabled) {
      evt.preventDefault();
      evt.stopPropagation();
      if (collectionBtn.disabled) return;
      var imageToken = collectionBtn.getAttribute('data-image-token') || '';
      if (imageToken === '') return;
      if (!visitorState.authenticated) {
        pendingCollectionImageToken = imageToken;
        openVisitorModal();
        return;
      }
      collectionBtn.disabled = true;
      toggleVisitorCollectionImage(imageToken)
        .catch(function () {
          /* ignore */
        })
        .finally(function () {
          collectionBtn.disabled = false;
        });
      return;
    }
  });

  function initFaceSearch() {
    if (!window.EFPIC_FACE_SEARCH_ENABLED) return;
    initFacePersonSearch();
  }

  function initFacePersonSearch() {
    var personsUrl = window.EFPIC_FACE_PERSONS_URL || '';
    var tokensUrl = window.EFPIC_FACE_PERSON_TOKENS_URL || '';
    var noFaceTokensUrl = window.EFPIC_FACE_NO_FACE_TOKENS_URL || '';
    if (!personsUrl || !tokensUrl) return;
    var modal = document.getElementById('facePersonModal');
    var openBtns = document.querySelectorAll('[data-face-search-open]');
    var closeBtns = document.querySelectorAll('[data-face-person-close]');
    var grid = document.getElementById('facePersonGrid');
    var statusEl = document.getElementById('facePersonStatus');
    var applyBtn = document.getElementById('facePersonApply');
    var deselectBtn = document.getElementById('facePersonDeselect');
    var faceFilterToolbar = document.getElementById('faceSearchToolbar');
    var faceFilterToolbarFaces = document.getElementById('faceSearchToolbarFaces');
    var faceFilterToolbarText = document.getElementById('faceSearchToolbarText');
    var sceneNavSections = document.querySelector('.gallery-scene-nav__sections');
    var clearBtn = document.getElementById('faceSearchClear');
    var persons = [];
    var selected = {};
    var activeFilterPersonIds = [];
    var activeFaceFilterTokens = [];
    var loaded = false;
    var faceFilterRestore = [];

    function getFaceFilterGalleryItems() {
      return Array.prototype.slice.call(
        document.querySelectorAll('.pic-feed-item[data-token], .grid-card[data-token]')
      );
    }

    function getDomGalleryTokenSet() {
      var set = {};
      getFaceFilterGalleryItems().forEach(function (el) {
        var tok = el.getAttribute('data-token') || '';
        if (tok) {
          set[tok] = true;
        }
      });
      return set;
    }

    function getPersonById(id) {
      for (var i = 0; i < persons.length; i++) {
        if (persons[i].id === id) {
          return persons[i];
        }
      }
      return null;
    }

    function updateFaceFilterToolbar(personIds, imageCount) {
      activeFilterPersonIds = personIds.slice();
      if (!faceFilterToolbar) {
        return;
      }
      var active = personIds.length > 0 && imageCount > 0;
      faceFilterToolbar.hidden = !active;
      if (sceneNavSections) {
        sceneNavSections.hidden = active;
      }
      if (!active) {
        if (faceFilterToolbarFaces) {
          faceFilterToolbarFaces.innerHTML = '';
        }
        return;
      }
      if (faceFilterToolbarFaces) {
        faceFilterToolbarFaces.innerHTML = '';
        personIds.forEach(function (id) {
          var person = getPersonById(id);
          if (!person) {
            return;
          }
          var thumb = document.createElement('img');
          thumb.className = 'gallery-face-filter-face';
          thumb.src = person.thumb_url || '';
          thumb.alt = '';
          thumb.loading = 'lazy';
          faceFilterToolbarFaces.appendChild(thumb);
        });
        if (personIds.length === 1 && personIds[0] === '__no_faces__') {
          faceFilterToolbarFaces.innerHTML = '';
          var noFaceDot = document.createElement('span');
          noFaceDot.className = 'gallery-face-filter-face gallery-face-filter-face--empty';
          noFaceDot.setAttribute('aria-hidden', 'true');
          faceFilterToolbarFaces.appendChild(noFaceDot);
        }
        faceFilterToolbarFaces.classList.toggle(
          'is-actionable',
          collectionEnabled && imageCount > 0
        );
      }
      if (faceFilterToolbarText) {
        if (personIds.length === 1 && personIds[0] === '__no_faces__') {
          faceFilterToolbarText.textContent =
            'Rāda ' + imageCount + ' bildes bez sejām';
        } else {
          faceFilterToolbarText.textContent =
            'Rāda ' + imageCount + ' bildes no izvēlētajām sejām';
        }
      }
    }

    function getVisibleFaceFilterTokens() {
      var tokens = [];
      getFaceFilterGalleryItems().forEach(function (el) {
        if (el.classList.contains('face-search-hidden')) {
          return;
        }
        var tok = el.getAttribute('data-token') || '';
        if (tok) {
          tokens.push(tok);
        }
      });
      return tokens;
    }

    function addVisibleFaceFilterToCollection() {
      if (!collectionEnabled) {
        return;
      }
      var tokens = activeFaceFilterTokens.length
        ? activeFaceFilterTokens.slice()
        : getVisibleFaceFilterTokens();
      if (!tokens.length) {
        return;
      }
      if (!visitorState.authenticated) {
        openVisitorModal();
        return;
      }
      createFaceVisitorCollection(tokens).then(function (data) {
        if (data && data.ok && faceFilterToolbar) {
          faceFilterToolbar.classList.add('is-added-to-collection');
          window.setTimeout(function () {
            faceFilterToolbar.classList.remove('is-added-to-collection');
          }, 1200);
        }
      });
    }

    function updateSceneVisibility() {
      document.querySelectorAll('.scene-block').forEach(function (scene) {
        var hasVisible = scene.querySelector(
          '.pic-feed-item[data-token]:not(.face-search-hidden), .grid-card[data-token]:not(.face-search-hidden)'
        );
        scene.classList.toggle('face-search-scene-empty', !hasVisible);
      });
    }

    function consolidateVisibleMosaicItems(visibleItems) {
      var mosaicFeeds = document.querySelectorAll('[data-masonry-gallery], [data-justified-gallery]');
      if (!mosaicFeeds.length) {
        return;
      }
      var target = mosaicFeeds[0];
      var feedItems = visibleItems.filter(function (el) {
        return el.classList.contains('pic-feed-item');
      });
      feedItems.forEach(function (item) {
        faceFilterRestore.push({
          el: item,
          parent: item.parentNode,
          next: item.nextSibling,
        });
        target.appendChild(item);
      });
      mosaicFeeds.forEach(function (feed, idx) {
        feed.classList.toggle('face-search-feed-inactive', idx > 0);
      });
    }

    function restoreFaceFilterDom() {
      faceFilterRestore.slice().reverse().forEach(function (entry) {
        if (!entry.el || !entry.parent) {
          return;
        }
        if (entry.next && entry.next.parentNode === entry.parent) {
          entry.parent.insertBefore(entry.el, entry.next);
        } else {
          entry.parent.appendChild(entry.el);
        }
      });
      faceFilterRestore = [];
      document.querySelectorAll('.face-search-feed-inactive').forEach(function (feed) {
        feed.classList.remove('face-search-feed-inactive');
      });
    }

    function applyFaceFilter(tokens, personIds) {
      restoreFaceFilterDom();
      activeFaceFilterTokens = tokens.slice();

      var domTokens = getDomGalleryTokenSet();
      var allowed = tokens.filter(function (t) {
        return !!domTokens[t];
      });
      var set = {};
      allowed.forEach(function (t) {
        set[t] = true;
      });

      var visible = [];
      getFaceFilterGalleryItems().forEach(function (el) {
        var tok = el.getAttribute('data-token') || '';
        if (tok === '') {
          return;
        }
        var show = !!set[tok];
        el.classList.toggle('face-search-hidden', !show);
        if (show) {
          visible.push(el);
        }
      });

      document.body.classList.add('face-search-filter-active');
      consolidateVisibleMosaicItems(visible);
      updateSceneVisibility();
      updateFaceFilterToolbar(personIds || [], allowed.length);
      closeModal();
      scheduleMosaicRelayout();
      window.requestAnimationFrame(function () {
        scheduleMosaicRelayout();
      });
    }

    function openModal() {
      if (!modal) return;
      modal.hidden = false;
      document.body.classList.add('face-search-open');
      if (!loaded) loadPersons();
    }
    function closeModal() {
      if (!modal) return;
      modal.hidden = true;
      document.body.classList.remove('face-search-open');
    }
    openBtns.forEach(function (btn) {
      btn.addEventListener('click', openModal);
    });
    closeBtns.forEach(function (btn) {
      btn.addEventListener('click', closeModal);
    });
    if (modal) {
      modal.addEventListener('click', function (evt) {
        if (evt.target === modal) closeModal();
      });
    }

    function updateSelectionUi() {
      var count = Object.keys(selected).length;
      if (deselectBtn) deselectBtn.disabled = count === 0;
      if (!grid) return;
      grid.querySelectorAll('[data-person-id]').forEach(function (el) {
        var id = el.getAttribute('data-person-id') || '';
        el.classList.toggle('selected', !!selected[id]);
      });
    }

    function renderPersons() {
      if (!grid) return;
      grid.innerHTML = '';
      if (noFaceTokensUrl) {
        var noFaceBtn = document.createElement('button');
        noFaceBtn.type = 'button';
        noFaceBtn.className = 'face-person-item face-person-item--no-face';
        noFaceBtn.setAttribute('data-person-id', '__no_faces__');
        noFaceBtn.title = 'Bildes bez sejām';
        var noFaceDot = document.createElement('span');
        noFaceDot.className = 'face-person-no-face-dot';
        noFaceBtn.appendChild(noFaceDot);
        var noFaceLabel = document.createElement('span');
        noFaceLabel.className = 'face-person-count face-person-no-face-label';
        noFaceLabel.textContent = '0';
        noFaceBtn.appendChild(noFaceLabel);
        noFaceBtn.addEventListener('click', function () {
          if (selected.__no_faces__) {
            delete selected.__no_faces__;
          } else {
            selected = { __no_faces__: true };
          }
          updateSelectionUi();
        });
        grid.appendChild(noFaceBtn);
      }
      persons.forEach(function (person) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'face-person-item';
        btn.setAttribute('data-person-id', person.id);
        btn.title = (person.photo_count || 0) + ' bildes';
        var img = document.createElement('img');
        img.src = person.thumb_url || '';
        img.alt = '';
        img.loading = 'lazy';
        var count = document.createElement('span');
        count.className = 'face-person-count';
        count.textContent = String(person.photo_count || 0);
        btn.appendChild(img);
        btn.appendChild(count);
        btn.addEventListener('click', function () {
          if (selected.__no_faces__) {
            delete selected.__no_faces__;
          }
          if (selected[person.id]) delete selected[person.id];
          else selected[person.id] = true;
          updateSelectionUi();
        });
        grid.appendChild(btn);
      });
      updateSelectionUi();
    }

    function loadPersons() {
      if (statusEl) {
        statusEl.hidden = false;
        statusEl.textContent = 'Ielādēju sejas…';
      }
      fetch(personsUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (res) {
          return res.json();
        })
        .then(function (data) {
          if (!data || !data.ok) throw new Error((data && data.error) || 'Kļūda');
          loaded = true;
          if (!data.processing_done) {
            if (statusEl) statusEl.textContent = 'Failiem vēl indeksē sejas — mēģini vēlāk.';
            return;
          }
          persons = data.persons || [];
          if (persons.length === 0) {
            if (statusEl) statusEl.textContent = 'Nav atrastu personu šajā galerijā.';
            return;
          }
          if (statusEl) statusEl.hidden = true;
          renderPersons();
          if (noFaceTokensUrl) {
            fetch(noFaceTokensUrl, {
              credentials: 'same-origin',
              headers: { Accept: 'application/json' },
            })
              .then(function (res) {
                return res.json();
              })
              .then(function (nfData) {
                if (!nfData || !nfData.ok || !grid) return;
                var label = grid.querySelector(
                  '[data-person-id="__no_faces__"] .face-person-no-face-label'
                );
                if (label) {
                  label.textContent = String((nfData.tokens || []).length);
                }
              })
              .catch(function () {
                /* ignore */
              });
          }
        })
        .catch(function (err) {
          if (statusEl) statusEl.textContent = (err && err.message) || 'Neizdevās ielādēt sejas';
        });
    }

    function clearFaceFilter() {
      document.body.classList.remove('face-search-filter-active');
      restoreFaceFilterDom();
      document.querySelectorAll('.face-search-hidden').forEach(function (el) {
        el.classList.remove('face-search-hidden');
      });
      document.querySelectorAll('.face-search-scene-empty').forEach(function (el) {
        el.classList.remove('face-search-scene-empty');
      });
      updateFaceFilterToolbar([], 0);
      selected = {};
      activeFilterPersonIds = [];
      activeFaceFilterTokens = [];
      updateSelectionUi();
      scheduleMosaicRelayout();
    }

    if (faceFilterToolbarFaces) {
      faceFilterToolbarFaces.addEventListener('click', function () {
        if (!faceFilterToolbarFaces.classList.contains('is-actionable')) {
          return;
        }
        addVisibleFaceFilterToCollection();
      });
    }
    if (faceFilterToolbarText) {
      faceFilterToolbarText.addEventListener('click', function () {
        if (!faceFilterToolbar || faceFilterToolbar.hidden) {
          return;
        }
        addVisibleFaceFilterToCollection();
      });
    }

    if (clearBtn) clearBtn.addEventListener('click', clearFaceFilter);
    if (deselectBtn) {
      deselectBtn.addEventListener('click', function () {
        selected = {};
        updateSelectionUi();
      });
    }
    if (applyBtn) {
      applyBtn.addEventListener('click', function () {
        var ids = Object.keys(selected);
        if (ids.length === 0) {
          clearFaceFilter();
          closeModal();
          return;
        }
        applyBtn.disabled = true;
        var appliedPersonIds = ids.slice();
        if (statusEl) {
          statusEl.hidden = false;
          statusEl.textContent = 'Atlasu bildes…';
        }
        if (ids.length === 1 && ids[0] === '__no_faces__') {
          fetch(noFaceTokensUrl, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
          })
            .then(function (res) {
              return res.json();
            })
            .then(function (data) {
              if (!data || !data.ok) throw new Error((data && data.error) || 'Kļūda');
              var tokens = data.tokens || [];
              if (tokens.length === 0) {
                if (statusEl) statusEl.textContent = 'Nav atrastu bildes bez sejām.';
                return;
              }
              if (statusEl) statusEl.hidden = true;
              applyFaceFilter(tokens, appliedPersonIds);
            })
            .catch(function (err) {
              if (statusEl) statusEl.textContent = (err && err.message) || 'Kļūda';
            })
            .finally(function () {
              applyBtn.disabled = false;
            });
          return;
        }
        fetch(tokensUrl + '?ids=' + encodeURIComponent(ids.join(',')), {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
        })
          .then(function (res) {
            return res.json();
          })
          .then(function (data) {
            if (!data || !data.ok) throw new Error((data && data.error) || 'Kļūda');
            var tokens = data.tokens || [];
            if (tokens.length === 0) {
              if (statusEl) statusEl.textContent = 'Nav atrastu bildes (hash nesakrīt ar Failiem?).';
              return;
            }
            if (statusEl) statusEl.hidden = true;
            applyFaceFilter(tokens, appliedPersonIds);
          })
          .catch(function (err) {
            if (statusEl) statusEl.textContent = (err && err.message) || 'Kļūda';
          })
          .finally(function () {
            applyBtn.disabled = false;
          });
      });
    }
  }
  initCollectionFilter();
  initFaceSearch();
})();
