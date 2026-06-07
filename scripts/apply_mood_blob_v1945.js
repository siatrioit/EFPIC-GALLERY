const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');

const moodBlock = `        if ($theme === 'efpic-mood') {
            $html = '<section class="gallery-intro gallery-intro--mood" id="galleryHero">';
            $html .= '<p class="gallery-intro-byline">' . efpic_client_esc($byline) . '</p>';
            $html .= '<div class="gallery-intro-blob-wrap">';
            $html .= '<div class="gallery-intro-blob">';
            if ($imgUrl !== '') {
                $html .= '<img class="gallery-intro-photo" src="' . efpic_client_esc($imgUrl) . '" alt="" decoding="async" fetchpriority="high">';
            }
            $html .= '</div></div>';
            $html .= '<div class="gallery-intro-footer">';
            $html .= '<h1 class="gallery-intro-title">' . efpic_client_esc($name) . '</h1>';
            if ($date !== '') {
                $html .= '<p class="gallery-intro-date">' . efpic_client_esc($date) . '</p>';
            }
            $html .= '</div></section>';

            return $html;
        }

`;

const cssBlock = `
/* Mood: Pic-Time stila kustīgs burbulis galerijas sākumā */
.gallery-intro--mood {
  align-items: center;
  justify-content: center;
  text-align: center;
  gap: clamp(28px, 5vh, 48px);
  padding: clamp(32px, 6vh, 56px) 24px clamp(40px, 8vh, 72px);
  background: var(--page-bg, #111111);
  color: var(--page-text, #f3f3f3);
}

.gallery-intro--mood .gallery-intro-byline {
  margin: 0;
  text-align: center;
  font-size: clamp(0.75rem, 2vw, 0.95rem);
  letter-spacing: 0.32em;
  text-transform: uppercase;
  opacity: 0.55;
  flex: 0 0 auto;
}

.gallery-intro-blob-wrap {
  flex: 0 1 auto;
  display: flex;
  align-items: center;
  justify-content: center;
  width: min(72vw, 420px);
  max-width: 100%;
}

.gallery-intro-blob {
  position: relative;
  width: 100%;
  aspect-ratio: 1 / 1.05;
  overflow: hidden;
  border-radius: 58% 42% 52% 48% / 48% 58% 42% 52%;
  animation:
    moodIntroBlobMorph 14s ease-in-out infinite alternate,
    moodIntroBlobFloat 9s ease-in-out infinite alternate;
  will-change: border-radius, transform;
  box-shadow: 0 24px 60px rgba(0, 0, 0, 0.45);
}

.gallery-intro--mood .gallery-intro-photo {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  object-position: center;
  box-shadow: none;
  transform: scale(1.06);
}

.gallery-intro--mood .gallery-intro-footer {
  flex: 0 0 auto;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  max-width: min(92vw, 28rem);
}

.gallery-intro--mood .gallery-intro-title {
  margin: 0;
  padding: 0;
  align-self: center;
  text-align: center;
  text-transform: none;
  letter-spacing: 0.06em;
  font-size: clamp(1.6rem, 4.5vw, 2.4rem);
  font-weight: 400;
  line-height: 1.2;
  max-width: none;
}

.gallery-intro--mood .gallery-intro-date {
  margin: 0;
  text-align: center;
  font-size: clamp(0.95rem, 2.5vw, 1.1rem);
  letter-spacing: 0.08em;
  opacity: 0.72;
}

@keyframes moodIntroBlobMorph {
  0% {
    border-radius: 58% 42% 52% 48% / 48% 58% 42% 52%;
  }
  25% {
    border-radius: 48% 52% 58% 42% / 58% 42% 52% 48%;
  }
  50% {
    border-radius: 52% 48% 42% 58% / 42% 58% 48% 52%;
  }
  75% {
    border-radius: 42% 58% 48% 52% / 52% 48% 58% 42%;
  }
  100% {
    border-radius: 55% 45% 50% 50% / 50% 55% 45% 50%;
  }
}

@keyframes moodIntroBlobFloat {
  0% {
    transform: translate3d(0, 0, 0) rotate(0deg);
  }
  100% {
    transform: translate3d(0, -8px, 0) rotate(1.2deg);
  }
}

@media (prefers-reduced-motion: reduce) {
  .gallery-intro-blob {
    animation: none;
  }
}

@media (min-width: 768px) {
  .gallery-intro--mood {
    gap: clamp(32px, 5vh, 56px);
  }

  .gallery-intro-blob-wrap {
    width: min(52vw, 480px);
  }
}
`;

function patchHandlers(filePath) {
  let t = fs.readFileSync(filePath, 'utf8').replace(/\r\n/g, '\n');
  if (t.includes('gallery-intro--mood')) {
    console.log('already patched', filePath);
    return;
  }

  const shellMarker = `        $html = '<section class="gallery-intro" id="galleryHero">';`;
  if (!t.includes(shellMarker)) {
    console.error('shell marker not found in', filePath);
    process.exit(1);
  }
  t = t.replace(shellMarker, moodBlock + shellMarker);
  fs.writeFileSync(filePath, t.replace(/\n/g, '\r\n'), 'utf8');
  console.log('patched', filePath);
}

function patchCss() {
  const cssPath = path.join(ROOT, 'web/public/client/assets/client.css');
  let t = fs.readFileSync(cssPath, 'utf8').replace(/\r\n/g, '\n');
  if (t.includes('gallery-intro--mood')) {
    console.log('css already patched');
    return;
  }
  if (!t.includes('.theme-efpic-mood')) {
    console.error('client.css missing efpic theme rules — restore from git before patching');
    process.exit(1);
  }
  const marker = '.topbar-floating {';
  if (!t.includes(marker)) {
    console.error('css marker not found');
    process.exit(1);
  }
  t = t.replace(marker, cssBlock + '\n' + marker);
  fs.writeFileSync(cssPath, t.replace(/\n/g, '\r\n'), 'utf8');
  console.log('css patched');
}

[
  path.join(ROOT, 'web/api/client_handlers.php'),
  path.join(ROOT, 'web/client_handlers.php'),
].forEach(patchHandlers);
patchCss();
fs.writeFileSync(path.join(ROOT, 'web/api/VERSION'), '1.9.45\r\n', 'utf8');
console.log('v1.9.45 mood blob OK');
