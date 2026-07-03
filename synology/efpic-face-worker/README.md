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

## Pārbaude

```bash
curl -H "Authorization: Bearer YOUR_TOKEN" https://www.klientiem.edgarsfoto.lv/api/face/ping
```

Admin panelī: galerija → **Seju meklēšana** → ieslēdz → **Indeksēt / turpināt**.

Publiskajā galerijā (kad statuss «Gatavs») parādās **Atrodi sevi**.

## NAS lēnums / DSM neatsaucas

InsightFace **pilnā slodzē** var aizņemt visu CPU. Ja DSM ir ļoti lēns:

1. **Stop** (ne Restart) `efpic-face-worker` Container Manager.
2. Vai SSH: `sudo docker stop efpic-face-worker`
3. Pagaidi 1–2 min, kamēr CPU atbrīvojas.

**Lite režīms** (v1.9.133+): CPU limits 1.5, 2 threads, det 320px, partija 5 bildes, 45 s pauze starp partijām, modelis ielādēts **vienreiz** (daemon).

Indeksēšanu **1432 bildēm** labāk likt **naktī** — uz NAS CPU tas ir stundas.

## Plūsma

1. **Indeksēšana** — pēc sync vai pogas; pa **5 bildēm** partijā.
2. **Meklēšana** — viesis augšupielādē selfiju; search job prioritāte pār index.
3. **Selfijs netiek glabāts** pēc meklēšanas (dzēsts no queue mapes).

## Live tests (izlaiduma bildes)

1. Deploy PHP **v1.9.128+** un palaid face worker.
2. Galerijā: ieslēdz seju meklēšanu, sync no Failiem, indeksē.
3. Pagaidi, kamēr admin statuss rāda **Gatavs** un worker «aktīvs».
4. Atver publisko saiti telefonā → **Atrodi sevi** → selfijs → filtrēts režģis.
