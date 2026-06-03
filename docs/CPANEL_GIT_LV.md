# GitHub + cPanel Git Version Control

Projekts: `D:\Dev\projects\EFPIC-GALLERY`

## 1. GitHub (publisks repo — kā WP spraudnim)

1. [github.com/new](https://github.com/new) → nosaukums piem. `efpic-gallery` vai `EFPIC-GALLERY`
2. **Public**
3. **Neatzīmējiet** «Add a README» (lokāli jau būs commits)
4. Nokopējiet clone URL, piem. `https://github.com/JŪSU_LIETOTĀJS/efpic-gallery.git`

## 2. Datorā — pieslēgt remote un augšupielādēt

PowerShell:

```powershell
cd D:\Dev\projects\EFPIC-GALLERY
.\scripts\connect-github.ps1 -RemoteUrl "https://github.com/JŪSU_LIETOTĀJS/efpic-gallery.git"
```

Skripts izveido `main` un palaiž `git push` (jāpiesakās GitHub — browser vai token).

## 3. cPanel — Git Version Control

1. **Git Version Control** → **Create**
2. **Clone URL:** jūsu `https://github.com/.../efpic-gallery.git`
3. **Repository Path:** ja sakne nav tukša (jau ir `api/`, `public/`), clone uz apakšmapi:
   ```text
   /home2/trioitlv/klientiem.edgarsfoto.lv/repo
   ```
4. **Clone** / **Create**

Pēc clone (variants ar `/repo` — ieteicams):

```
klientiem.edgarsfoto.lv/
  repo/           ← Git (Pull šeit)
    .git/
    web/
    .cpanel.yml
  api/            ← dzīvais kods (deploy no web/api)
  public/         ← Document Root
  config/         ← config.php (NE no git)
  storage/
```

## 4. Automātiska kopēšana pēc Pull (.cpanel.yml)

Repozitorijā ir `.cpanel.yml`. Pēc **Pull** cPanel kopē:

- `web/api/*` → `api/`
- `web/public/*` → `public/`

**Vienreiz** atveriet `.cpanel.yml` cPanel File Manager (vai pēc pirmā pull) un labojiet rindu:

```yaml
export DEPLOYPATH=/home2/trioitlv/klientiem.edgarsfoto.lv
```

uz **vecāko mapi** (bez `/repo`), piem. `/home2/trioitlv/klientiem.edgarsfoto.lv`.

`config.php` un `storage/galleries/*` **netiek** pārrakstīti.

cPanel **Pull** veiciet repozitorijam `.../klientiem.edgarsfoto.lv/repo`, ne galvenajai mapei.

## 5. Document Root

Atstājiet kā jau ir:

```text
klientiem.edgarsfoto.lv/public
```

**Ne** `web/public` — pēc deploy kods nonāk tieši `public/`.

## 6. Ikdienas darbs

```powershell
cd D:\Dev\projects\EFPIC-GALLERY
# labojat kodu
git add -A
git commit -m "Apraksts"
git push
```

cPanel → **Git Version Control** → **Pull** (vai **Deploy HEAD**).

Pārbauda: `https://klientiem.edgarsfoto.lv/api/health`

## 7. Ko nekad necommitot

Jau `.gitignore`: `web/config/config.php`, `web/storage/galleries/*`

## Problēmas

| Simptoms | Risinājums |
|----------|------------|
| Pull neko nemaina | Pārbaudiet `.cpanel.yml` DEPLOYPATH; skatiet cPanel **Deployment** log |
| Privāts repo clone neizdodas | Izmantojiet **publisku** repo vai Deploy Key cPanel |
| Pēc pull admin/login pazūd | Nepārrakstījāt `config.php` — tas ārpus git |
