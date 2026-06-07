const fs = require('fs');
const path = require('path');

const jsPath = path.join(path.resolve(__dirname, '..'), 'web/public/client/assets/client.js');
let t = fs.readFileSync(jsPath, 'utf8').replace(/\r\n/g, '\n');

const oldBlock = `      item.style.width = Math.round(itemWidth) + 'px';
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

      var newBottom = bestTop + itemHeight + gap;`;

const newBlock = `      item.style.width = Math.round(itemWidth) + 'px';

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
      item.style.height = Math.max(1, Math.round(itemHeight)) + 'px';

      var newBottom = bestTop + itemHeight + gap;`;

if (!t.includes(oldBlock)) {
  if (t.includes("item.style.height = Math.max(1, Math.round(itemHeight)) + 'px';")) {
    console.log('mosaic height fix already applied');
    process.exit(0);
  }
  console.error('layout block not found');
  process.exit(1);
}

t = t.replace(oldBlock, newBlock);
fs.writeFileSync(jsPath, t.replace(/\n/g, '\r\n'), 'utf8');
fs.writeFileSync(path.join(path.resolve(__dirname, '..'), 'web/api/VERSION'), '1.9.47\r\n', 'utf8');
console.log('mosaic height fix v1.9.47 OK');
