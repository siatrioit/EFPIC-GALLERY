# EFPIC Gallery

Klientu foto galerijas — **`https://klientiem.edgarsfoto.lv`** ar Failiem.lv failu glabātuvi.

**Lokālā mape:** `D:\Dev\projects\EFPIC-GALLERY`

## Git + cPanel (bez roku kopēšanas)

1. Izveidojiet **publisku** GitHub repo (tukšu, bez README).
2. Palaidiet: `.\scripts\connect-github.ps1 -RemoteUrl "https://github.com/.../efpic-gallery.git"`
3. cPanel → **Git Version Control** → clone uz `klientiem.edgarsfoto.lv` mapi.
4. Labojiet `DEPLOYPATH` failā `.cpanel.yml` uz servera ceļu.
5. Pēc katra `git push` → cPanel **Pull**.

Pilna pamācība: **[docs/CPANEL_GIT_LV.md](docs/CPANEL_GIT_LV.md)**

## Struktūra

| Mape | Loma |
|------|------|
| `web/public/` | Document root (`index.php`, `.htaccess`) |
| `web/api/` | PHP loģika, Failiem, admin |
| `web/config/` | `config.php` tikai serverī (nav git) |
| `web/storage/` | Galeriju JSON |

## Uzstādīšana serverī (vienreiz)

1. `web/config/config.example.php` → `config/config.php`
2. `base_url` = `https://klientiem.edgarsfoto.lv`
3. `dashboard_password`, `api_token`, `failiem`

## Dokumentācija

- [CPANEL_GIT_LV.md](docs/CPANEL_GIT_LV.md) — Git deploy
- [DEPLOY_LV.md](docs/DEPLOY_LV.md) — WinSCP alternatīva
- [FAILIEM_LV.md](docs/FAILIEM_LV.md) — Failiem mapes un sync
- [WEB_DELIVERY_GALLERY.md](docs/WEB_DELIVERY_GALLERY.md) — URL un API
