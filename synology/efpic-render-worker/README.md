# EFPIC slideshow render worker (Synology)

Šis konteiners periodiski izsauc `POST /api/render/claim`, lejupielādē audio un bildes, ģenerē MP4 ar FFmpeg un augšupielādē rezultātu atpakaļ uz cPanel.

## Prasības

- Synology ar Container Manager
- `api_token` no `config.php` (tas pats, ko izmanto citi API klienti)
- Outbound HTTPS uz `https://klientiem.edgarsfoto.lv`

## Uzstādīšana

1. Nokopē mapi `synology/efpic-render-worker` uz NAS (vai clone repo).
2. Izveido `.env` no `.env.example` un ieraksti `EFPIC_API_TOKEN`.
3. Synology Container Manager → Project → Create no `docker-compose.yml`.
4. Pārbaudi logus — jāredz `EFPIC render worker start` un **`render.sh 114 lines`** (vai ~115). Ja redzi **`123 lines`**, NAS mapē vēl ir vecais `render.sh`.

`worker.sh` un `render.sh` ir bind-mount no projekta mapes — pēc failu nokopēšanas pietiek ar **Recreate** konteineri (nav obligāti Rebuild). Ja mainīts tikai `Dockerfile`, tad **Rebuild**.

Ja logā redzi `Error opening output file ./worker.sh` un `ffmpeg version`, konteiners nav pārbūvēts pēc Dockerfile labojuma — **Rebuild** projektu Container Manager.

Ja logā redzi `set: pipefail: invalid option name`, skripti ir ar Windows rindu beigām — nokopē jaunos `worker.sh`, `render.sh` un **Recreate**.

Ja logā redzi `sed: can't move ... Resource busy`, vecais entrypoint mēģināja `sed -i` uz bind-mount failiem — nokopē jauno `worker.sh` un `docker-compose.yml`, tad **Recreate**.

## Pārbaude

```bash
curl -H "Authorization: Bearer YOUR_TOKEN" https://klientiem.edgarsfoto.lv/api/render/ping
```

Admin panelī: galerija → «Slideshow & video» → aizpildi MP3, intro, favorītus → **Ģenerēt video**.

## Phase A ierobežojumi

- Viena bilde uz kadru (bez multi-image layout)
- Fona režīms `gallery` vēl neizmanto galerijas krāsu — balts canvas
- Nav watermark

Pilna spec (multi-image, galerijas fons, publiskā sadaļa) — nākamajās versijās.
