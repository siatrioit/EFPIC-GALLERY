# EFPIC face index worker (Synology)

Periodiski izsauc `POST /api/face/claim`, lejupielādē WEB thumbnails, ievāc seju embeddings ar **InsightFace buffalo_l** un augšupielādē rezultātus atpakaļ uz cPanel.

## Prasības

- Synology ar Container Manager (Docker)
- `api_token` no `config.php` (tas pats, ko render worker)
- Outbound HTTPS uz produkcijas serveri
- **Pirmā build** lejupielādē InsightFace modeli (~500 MB) — var aizņemt 10–20 min

## Uzstādīšana

1. Nokopē `synology/efpic-face-worker` uz NAS.
2. Izveido `.env` no `.env.example`.
3. Container Manager → Project → Create no `docker-compose.yml`.
4. Pirmo reizi **Build** (ne tikai Start).

Pēc koda atjaunināšanas pietiek ar **Recreate** (skripti ir bind-mount).

**Synology Build kļūda `NanoCPUs`:** noņem `cpus:` rindu no `docker-compose.yml` — daudzi NAS kerneli neatbalsta CPU CFS. Izmanto tikai `mem_limit` + env `EFPIC_FACE_THREADS=1`.

### Pārbaude pēc atjaunināšanas

SSH uz NAS (ceļš var atšķirties):

```bash
docker exec efpic-face-worker sh /app/nas-verify.sh
```

Jāredz tikai `OK` rindiņas. Logs startā:

```
EFPIC face worker 1.9.135 start — …
face extractor ready
extracting … (5 images)
index job … done (5 images)
```

Ja logs rāda tikai `index job` un `jq: invalid JSON` — **NAS vēl izmanto veco worker.sh**.

## Pārbaude

```bash
curl -H "Authorization: Bearer YOUR_TOKEN" https://www.klientiem.edgarsfoto.lv/api/face/ping
```

Admin panelī: galerija → **Seju meklēšana** → ieslēdz → **Indeksēt / turpināt**.

Publiskajā galerijā (kad statuss «Gatavs») parādās **Atrodi sevi**.

## NAS lēnums / DSM neatsaucas

InsightFace **patērē CPU** — DSM kļūst lēns, kamēr worker strādā. Tas **nav kļūda**, bet NAS ierobežojums.

**Ekonomijas režīms (v1.9.136):** 0.75 CPU, `buffalo_s`, 1 threads, det 256, partija 3 bildes, 90 s pauze.

1. **Stop** face worker, kad vajag lietot DSM.
2. Indeksē **tikai naktī** (1432 bildes = daudzas stundas).
3. Laikā indeksēšanai **apturi arī** `efpic-render-worker`.
4. Alternatīva: palaid `efpic-face-worker` uz **Windows PC** (Docker Desktop) ar to pašu `.env` — NAS netiek noslogots.

## Plūsma

1. **Indeksēšana** — pēc sync vai pogas; pa **5 bildēm** partijā.
2. **Meklēšana** — viesis augšupielādē selfiju; search job prioritāte pār index.
3. **Selfijs netiek glabāts** pēc meklēšanas (dzēsts no queue mapes).

## Live tests (izlaiduma bildes)

1. Deploy PHP **v1.9.128+** un palaid face worker.
2. Galerijā: ieslēdz seju meklēšanu, sync no Failiem, indeksē.
3. Pagaidi, kamēr admin statuss rāda **Gatavs** un worker «aktīvs».
4. Atver publisko saiti telefonā → **Atrodi sevi** → selfijs → filtrēts režģis.
