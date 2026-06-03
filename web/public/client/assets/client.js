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
  var zipIframeTimer = null;

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

  function openZipProgress(hint) {
    if (!zipProgressModal) return;
    var hintEl = document.getElementById('zipProgressHint');
    if (hintEl && hint) hintEl.textContent = hint;
    zipProgressModal.hidden = false;
    document.body.style.overflow = 'hidden';
  }

  function closeZipProgress() {
    if (zipFetchAbort) {
      zipFetchAbort.abort();
      zipFetchAbort = null;
    }
    if (zipIframeTimer) {
      clearTimeout(zipIframeTimer);
      zipIframeTimer = null;
    }
    if (!zipProgressModal) return;
    zipProgressModal.hidden = true;
    if ((!gdlModal || gdlModal.hidden) && (!cdlModal || cdlModal.hidden)) {
      document.body.style.overflow = '';
    }
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

  function downloadServerZip(downloadUrl, filename, hint) {
    if (!downloadUrl) return;
    openZipProgress(hint || 'ZIP tiek gatavots…');
    if (zipFetchAbort) {
      zipFetchAbort.abort();
    }
    zipFetchAbort = new AbortController();
    fetch(downloadUrl, { credentials: 'same-origin', signal: zipFetchAbort.signal })
      .then(function (res) {
        if (!res.ok) {
          return res.text().then(function (text) {
            throw new Error(text || 'Lejupielāde neizdevās (' + res.status + ')');
          });
        }
        var type = (res.headers.get('Content-Type') || '').toLowerCase();
        if (type.indexOf('zip') < 0 && type.indexOf('octet-stream') < 0 && type.indexOf('html') >= 0) {
          return res.text().then(function (text) {
            throw new Error(text || 'Serveris neatgrieza ZIP failu');
          });
        }
        return res.blob();
      })
      .then(function (blob) {
        if (!blob || blob.size < 64) {
          throw new Error('ZIP fails ir tukšs vai bojāts');
        }
        triggerBlobDownload(blob, filename);
        closeZipProgress();
      })
      .catch(function (err) {
        if (err && err.name === 'AbortError') return;
        var hintEl = document.getElementById('zipProgressHint');
        if (hintEl) {
          hintEl.textContent =
            err && err.message ? err.message : 'Neizdevās lejupielādēt ZIP. Mēģini vēlreiz.';
        }
        if (zipProgressModal) zipProgressModal.hidden = false;
      })
      .finally(function () {
        zipFetchAbort = null;
      });
  }

  function downloadFailiemZip(failiemUrl, hint) {
    if (!failiemUrl) return;
    openZipProgress(
      hint || 'Failiem sagatavo ZIP — lielām galerijām tas var aizņemt vairākas minūtes…'
    );
    var iframe = document.createElement('iframe');
    iframe.setAttribute('aria-hidden', 'true');
    iframe.style.cssText = 'position:absolute;width:0;height:0;border:0;visibility:hidden';
    iframe.src = failiemUrl;
    document.body.appendChild(iframe);
    zipIframeTimer = setTimeout(function () {
      iframe.remove();
      closeZipProgress();
    }, 300000);
  }

  function startZipDownload(scope, size) {
    if (!gdlBase) return;
    closeGalleryDlModal();
    closeCollectionDlModal();
    openZipProgress('Sagatavo lejupielādi…');
    var path = scope === 'collection' ? '/collection/zip' : '/download.zip';
    var prepareUrl = gdlBase + path + '?size=' + encodeURIComponent(size) + '&prepare=1';
    fetch(prepareUrl, {
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
        var filename = data.filename || zipFilenameFor(scope, size);
        if (data.mode === 'failiem' && data.url) {
          downloadFailiemZip(data.url, data.hint);
          return;
        }
        downloadServerZip(data.url, filename, data.hint);
      })
      .catch(function (err) {
        var hintEl = document.getElementById('zipProgressHint');
        if (hintEl) {
          hintEl.textContent =
            err && err.message ? err.message : 'Neizdevās sākt lejupielādi.';
        }
        if (zipProgressModal) zipProgressModal.hidden = false;
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

  document.querySelectorAll('[data-zip-progress-cancel]').forEach(function (btn) {
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

  if (zipProgressModal) {
    zipProgressModal.addEventListener('click', function (evt) {
      if (evt.target === zipProgressModal) closeZipProgress();
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

    var containerStyle = window.getComputedStyle(container);
    var gap = parseFloat(containerStyle.gap) || 16;
    var padLeft = parseFloat(containerStyle.paddingLeft) || 0;
    var padTop = parseFloat(containerStyle.paddingTop) || 0;
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

      var left = padLeft + bestCol * (colWidth + gap);
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
      document.querySelectorAll('[data-masonry-gallery], [data-justified-gallery]').forEach(layoutColumnMasonry);
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
    var slideImg = slideshowEl.querySelector('.efpic-slideshow-stage img');
    var slideAudio = slideshowEl.querySelector('.efpic-slideshow-audio');
    var slideClose = slideshowEl.querySelector('.efpic-slideshow-close');
    var dataEl = document.getElementById('efpic-slideshow-data');
    var slides = [];
    var slideIdx = 0;
    var slideTimer = null;
    var intervalSec = parseInt(slideshowEl.getAttribute('data-interval') || '5', 10) * 1000;

    try {
      slides = JSON.parse(dataEl ? dataEl.textContent || '[]' : '[]');
    } catch (e) {
      slides = [];
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
      slideshowEl.hidden = true;
      document.body.style.overflow = '';
    }

    function startSlideshow() {
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
        (count === 1 ? 'bilde izvēlēta' : 'bildes izvēlētas');
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
