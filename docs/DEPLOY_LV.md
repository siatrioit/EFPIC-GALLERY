# Automātiska izvietošana (bez roku kopēšanas)

Galerija dzīvo **tikai** uz apakšdomēna `klientiem.edgarsfoto.lv`. WordPress (`www`) neaiztikt.

## Kas paliek uz servera (vienreiz ar roku)

| Fails / mape | Kāpēc |
|--------------|--------|
| `web/config/config.php` | Paroles, `base_url`, `api_token`, Failiem |
| `web/storage/galleries/*` | Klientu galeriju dati |
| `web/storage/access_index.json` | Piekļuves indekss |

Visu pārējo (`web/api/`, `web/public/`, `web/public/admin/`) var atjaunināt automātiski.

---

## Variants A — cPanel Git (ieteicams, ja hostingā ir «Git Version Control»)

### 1. Lokāli — Git repozitorijs

Projekts: `D:\Dev\projects\EFPIC-GALLERY`

```powershell
cd D:\Dev\projects\EFPIC-GALLERY
git init
git add .
git commit -m "EFPIC gallery initial"
```

Izveidojiet **privātu** repozitoriju GitHub vai GitLab (bez `config.php` — tas jau ir `.gitignore`).

```powershell
git remote add origin https://github.com/JŪSU_LIETOTĀJS/efpic-gallery.git
git push -u origin main
```

### 2. cPanel — clone uz apakšdomēnu

1. **Domains** → apakšdomēns `klientiem.edgarsfoto.lv` → **Document Root** = `.../klientiem.edgarsfoto.lv/public` (mapē `web/public` saturs).
2. **Git Version Control** → **Create**:
   - Clone URL: jūsu GitHub repo
   - Ceļš: piemēram `/home2/trioitlv/klientiem.edgarsfoto.lv/repo` (ne WordPress `public_html`!)
3. Pēc clone **pārvietojiet** vai iestatiet, lai uz diska būtu šāda struktūra:

```
klientiem.edgarsfoto.lv/
  api/          ← no repo web/api/
  config/       ← config.php jūs izveidojāt (ne no git)
  storage/      ← dati paliek
  public/       ← no repo web/public/  ← Document Root
```

Ja cPanel clone liek visu repo saknē, pēc pull pārkopējiet `web/*` vienu līmeni augšāk (vienreiz), vai izmantojiet deploy skriptu zemāk.

### 3. Katru reizi pēc koda izmaiņām

Lokāli:

```powershell
git add -A
git commit -m "Apraksts par izmaiņām"
git push
```

cPanel → **Git Version Control** → jūsu repo → **Pull** vai **Deploy HEAD Commit**.

Pārbaudiet: `https://klientiem.edgarsfoto.lv/api/health`

---

## Variants B — WinSCP (bez GitHub)

1. Instalējiet [WinSCP](https://winscp.net/).
2. Savienojums: SFTP, hosts `klientiem.edgarsfoto.lv` vai hostinga SFTP serveris, lietotājs/parole no cPanel.
3. **Remote** mape: `.../klientiem.edgarsfoto.lv/` (kur ir `api`, `public`, `config`).
4. **Local** mape: `D:\Dev\projects\EFPIC-GALLERY\web`
5. **Synchronize** → Direction: Local → Remote.
6. **Exclude** (svarīgi):

```
config/config.php
storage/galleries/*
storage/booth_events/*
```

7. Pēc koda labojumiem: viens klikšķis **Synchronize** — ātrāk nekā File Manager.

---

## Variants C — GitHub Actions → SFTP (pilnīgi automātiski pēc `git push`)

1. cPanel → **FTP Accounts** vai SFTP lietotājs ar tiesībām tikai uz `klientiem...` mapi.
2. GitHub repo → **Settings → Secrets**:
   - `FTP_HOST`, `FTP_USER`, `FTP_PASS`, `FTP_REMOTE_PATH` (piem. `/home2/trioitlv/klientiem.edgarsfoto.lv`)
3. Repozitorijā pievienojiet workflow (piemērs: `.github/workflows/deploy.yml` — skatīt komentārus repozitorijā, ja pievienots).

Katrs `git push` uz `main` augšupielādē `web/api` un `web/public`, bet **ne** `config.php`.

---

## Biežākās kļūdas

| Problēma | Risinājums |
|----------|------------|
| Pēc pull pazūd admin | Augšupielādēts ne visa `web/api/` — izmantojiet pilnu **EFPIC-GALLERY**, ne daļēju LIVE kopiju |
| Pārrakstīts `config.php` | Deploy exclude; atjaunojiet no backup |
| `/api/health` 404 | Trūkst `public/.htaccess` |
| Saites uz www | `config.php` → `base_url` = `https://klientiem.edgarsfoto.lv` |

---

## Īsā ikdienas rutīna (Git variants)

1. Labojat kodu lokāli `EFPIC-GALLERY`.
2. `git commit` + `git push`.
3. cPanel → **Pull**.
4. Pārbaudāt `/api/health` un vienu testa galeriju.

Nekad nekopējiet galeriju datus no lokālā PC uz produkciju, ja tur jau ir īstas klientu galerijas.
