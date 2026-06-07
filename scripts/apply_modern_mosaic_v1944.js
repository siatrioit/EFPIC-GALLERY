const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const jsPath = path.join(ROOT, 'web/public/client/assets/client.js');
let t = fs.readFileSync(jsPath, 'utf8').replace(/\r\n/g, '\n');

const oldPick = `  /** Mood / Forest: konservatīvs span (gandrīz viss 1 kol.). */
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

  function findMosaicPlacement(span, colHeights, columns) {
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
    return { col: bestCol, top: bestTop };
  }

  /** Modern: reti lielas “hero” bildes; nekad divas blakus vienā rindā. */
  function pickColumnSpanModern(aspect, index, columns, img, layout) {
    layout = layout || {};
    if (columns <= 1 || layout.wideBlocked || (layout.cooldown > 0)) {
      return 1;
    }
    var hasKnownAspect = feedItemHasKnownAspect(img);
    if (!hasKnownAspect && (!img || img.naturalWidth <= 0 || isDeferredFeedImage(img))) {
      return 1;
    }
    if (aspect < 1.3) {
      return 1;
    }

    var slot = index % 10;
    if (slot !== 4 && slot !== 7) {
      return 1;
    }
    if (columns >= 4 && aspect >= 2.5 && slot === 7) {
      return 3;
    }
    if (aspect >= 1.5) {
      return Math.min(2, columns);
    }
    return 1;
  }

  function pickColumnSpan(aspect, index, columns, img, layout) {
    if (getGalleryThemeSlug() === 'efpic-modern') {
      return pickColumnSpanModern(aspect, index, columns, img, layout);
    }
    return pickColumnSpanStandard(aspect, index, columns, img);
  }`;

if (!t.includes(oldPick)) {
  console.error('pickColumnSpan block not found');
  process.exit(1);
}
t = t.replace(oldPick, newPick);

const oldLayout = `    items.forEach(function (item, index) {
      var img = item.querySelector('img');
      if (isBrokenFeedImage(img)) {
        markBrokenFeedItem(item);
        return;
      }

      var aspect = readAspectRatio(img);
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
      item.style.height = Math.max(1, Math.round(itemHeight)) + 'px';
      var newBottom = bestTop + itemHeight + gap;
      for (s = 0; s < span; s++) {
        colHeights[bestCol + s] = newBottom;
      }
    });`;

const newLayout = `    var modernLayout = { cooldown: 0, bandTop: null, wideInBand: 0 };
    var modernHeroMaxH = Math.round((window.innerHeight || 800) * 0.44);

    items.forEach(function (item, index) {
      var img = item.querySelector('img');
      if (isBrokenFeedImage(img)) {
        markBrokenFeedItem(item);
        return;
      }

      var aspect = readAspectRatio(img);
      var isModernTheme = getGalleryThemeSlug() === 'efpic-modern';
      if (isModernTheme && modernLayout.cooldown > 0) {
        modernLayout.cooldown--;
      }

      var layoutHints = isModernTheme
        ? {
            cooldown: modernLayout.cooldown,
            wideBlocked: modernLayout.wideInBand > 0
          }
        : null;
      var span = pickColumnSpan(aspect, index, columns, img, layoutHints);
      var placement = findMosaicPlacement(span, colHeights, columns);
      var bestCol = placement.col;
      var bestTop = placement.top;
      var itemWidth = span * colWidth + gap * (span - 1);

      if (isModernTheme && span >= 2) {
        if (
          modernLayout.bandTop !== null &&
          Math.abs(bestTop - modernLayout.bandTop) <= 1 &&
          modernLayout.wideInBand > 0
        ) {
          span = 1;
          itemWidth = colWidth;
          placement = findMosaicPlacement(span, colHeights, columns);
          bestCol = placement.col;
          bestTop = placement.top;
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
      if (isModernTheme) {
        item.setAttribute('data-size', span >= 2 ? 'lg' : 'md');
      } else {
        item.removeAttribute('data-size');
      }

      var itemHeight = measureFeedItemHeight(item, img, itemWidth, aspect);
      var heroCover = false;
      if (isModernTheme && span >= 2 && itemHeight > modernHeroMaxH) {
        itemHeight = modernHeroMaxH;
        heroCover = true;
      }

      if (img) {
        img.style.width = '100%';
        img.style.display = 'block';
        if (heroCover) {
          img.style.height = '100%';
          img.style.objectFit = 'cover';
          item.style.height = Math.round(itemHeight) + 'px';
        } else {
          img.style.height = 'auto';
          img.style.objectFit = '';
        }
      }

      if (img) {
        if (isImageLoaded(img)) {
          item.classList.remove('pic-feed-item--loading');
        } else {
          item.classList.add('pic-feed-item--loading');
        }
      }

      if (!heroCover) {
        itemHeight = measureFeedItemHeight(item, img, itemWidth, aspect);
      }

      var newBottom = bestTop + itemHeight + gap;
      for (s = 0; s < span; s++) {
        colHeights[bestCol + s] = newBottom;
      }

      if (isModernTheme && span >= 2) {
        if (modernLayout.bandTop === null || Math.abs(bestTop - modernLayout.bandTop) > gap) {
          modernLayout.bandTop = bestTop;
          modernLayout.wideInBand = 0;
        }
        modernLayout.wideInBand++;
        modernLayout.cooldown = 9;
      } else if (isModernTheme && modernLayout.bandTop !== null && bestTop > modernLayout.bandTop + gap) {
        modernLayout.bandTop = null;
        modernLayout.wideInBand = 0;
      }
    });`;

if (!t.includes(oldLayout)) {
  console.error('layout block not found');
  process.exit(1);
}
t = t.replace(oldLayout, newLayout);

// Remove duplicate loading-class block if present after our insertion
t = t.replace(
  /\s+if \(img\) \{\s+if \(isImageLoaded\(img\)\) \{\s+item\.classList\.remove\('pic-feed-item--loading'\);\s+\} else \{\s+item\.classList\.add\('pic-feed-item--loading'\);\s+\}\s+\}\s+var newBottom = bestTop \+ itemHeight \+ gap;/,
  '\n      var newBottom = bestTop + itemHeight + gap;'
);

fs.writeFileSync(jsPath, t.replace(/\n/g, '\r\n'), 'utf8');
fs.writeFileSync(path.join(ROOT, 'web/api/VERSION'), '1.9.44\r\n', 'utf8');
console.log('client.js modern mosaic v1.9.44 OK');
