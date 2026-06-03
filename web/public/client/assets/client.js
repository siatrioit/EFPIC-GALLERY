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
