const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const jsPath = path.join(ROOT, 'web/public/client/assets/client.js');
let t = fs.readFileSync(jsPath, 'utf8').replace(/\r\n/g, '\n');

const oldPick = `  function pickColumnSpan(aspect, index, columns, img) {
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
  }`;

const newPick = `  /** Mood / Forest: konservatīvs span (gandrīz viss 1 kol.). */
  function pickColumnSpanStandard(aspect, index, columns, img) {
    if (columns <= 1) {
      return 1;
    }
    var hasKnownAspect = feedItemHasKnownAspect(img);
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

  /** Modern: Pic-Time stila dažādi izmēri — gan horizontālām, gan vertikālām. */
  function pickColumnSpanModern(aspect, index, columns, img) {
    if (columns <= 1) {
      return 1;
    }
    var hasKnownAspect = feedItemHasKnownAspect(img);
    if (!hasKnownAspect && (!img || img.naturalWidth <= 0 || isDeferredFeedImage(img))) {
      return 1;
    }

    var portrait = aspect < 0.95;
    var landscape = aspect >= 1.15;
    var phase = index % 6;

    if (landscape && columns >= 4 && aspect >= 2.25 && phase === 4) {
      return 3;
    }
    if (landscape && aspect >= 1.9) {
      return Math.min(2, columns);
    }
    if (landscape && aspect >= 1.35) {
      if (phase === 0 || phase === 2 || phase === 4) {
        return Math.min(2, columns);
      }
      return 1;
    }
    if (landscape) {
      if (phase === 1 || phase === 5) {
        return Math.min(2, columns);
      }
      return 1;
    }
    if (!portrait) {
      if (phase === 0 || phase === 3) {
        return Math.min(2, columns);
      }
      return 1;
    }
    if (columns >= 3 && aspect <= 0.72 && phase === 5) {
      return Math.min(2, columns);
    }
    return 1;
  }

  function modernTilePresentation(aspect, index, span) {
    if (span > 1) {
      return { heightMul: 1, cover: false, size: 'lg' };
    }
    var tick = index % 9;
    if (tick === 2 || tick === 6) {
      return { heightMul: 0.84, cover: true, size: 'sm' };
    }
    if (tick === 5) {
      return { heightMul: 1.14, cover: true, size: 'md' };
    }
    if (aspect >= 1.15 && tick === 1) {
      return { heightMul: 0.9, cover: true, size: 'sm' };
    }
    if (aspect < 0.95 && tick === 7) {
      return { heightMul: 1.08, cover: true, size: 'md' };
    }
    return { heightMul: 1, cover: false, size: 'md' };
  }

  function pickColumnSpan(aspect, index, columns, img) {
    if (getGalleryThemeSlug() === 'efpic-modern') {
      return pickColumnSpanModern(aspect, index, columns, img);
    }
    return pickColumnSpanStandard(aspect, index, columns, img);
  }`;

if (!t.includes(oldPick)) {
  console.error('pickColumnSpan block not found');
  process.exit(1);
}
t = t.replace(oldPick, newPick);

const oldLayout = `      var aspect = readAspectRatio(img);
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
      item.style.height = Math.max(1, Math.round(itemHeight)) + 'px';`;

const newLayout = `      var aspect = readAspectRatio(img);
      var isModernTheme = getGalleryThemeSlug() === 'efpic-modern';
      var span = pickColumnSpan(aspect, index, columns, img);
      var itemWidth = span * colWidth + gap * (span - 1);
      var presentation = isModernTheme
        ? modernTilePresentation(aspect, index, span)
        : { heightMul: 1, cover: false, size: 'md' };

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
      if (isModernTheme) {
        item.setAttribute('data-size', presentation.size);
      } else {
        item.removeAttribute('data-size');
      }

      if (img) {
        img.style.width = '100%';
        img.style.display = 'block';
        if (presentation.cover) {
          img.style.height = '100%';
          img.style.objectFit = 'cover';
        } else {
          img.style.height = 'auto';
          img.style.objectFit = '';
        }
      }

      var itemHeight = measureFeedItemHeight(item, img, itemWidth, aspect) * presentation.heightMul;
      item.style.height = Math.max(1, Math.round(itemHeight)) + 'px';`;

if (!t.includes(oldLayout)) {
  console.error('layout block not found');
  process.exit(1);
}
t = t.replace(oldLayout, newLayout);

fs.writeFileSync(jsPath, t.replace(/\n/g, '\r\n'), 'utf8');
fs.writeFileSync(path.join(ROOT, 'web/api/VERSION'), '1.9.43\r\n', 'utf8');
console.log('client.js modern mosaic OK, v1.9.43');
