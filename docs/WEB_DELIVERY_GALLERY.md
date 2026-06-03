# Klientu piegādes galerija (Failiem.lv)

## URL

| Tips | Piemērs |
|------|---------|
| Publiska galerija | `https://www.edgarsfoto.lv/v/g/{gallery_token}` |
| Viesis | `...?g={guest_token}` |
| Bilde | `/v/i/{image_token}` |
| Medijs | `/v/media/{token}?size=web` vai `full` |
| Lejupielāde | `/v/i/{token}/download?size=web` vai `full` |
| ZIP | `/v/g/{token}/download.zip?size=web` vai `full` |
| Admin | `/admin/` |
| Klienta panelis (Fāze B) | `/c/p/{portal_token}` |

## Darba plūsma

1. Augšupielādē bildes Failiem.lv **divās mapēs** (pilns + web).
2. Admin → Jauna piegāde → ielīmē abu mapju saites.
3. **Sinhronizēt no Failiem** — pāri pēc faila numura (`_PRINT` / `_WEB`).
4. Kārtot bildes adminā (velciet rindas).
5. Nosūti klientam publisko saiti.

## Konfigurācija

`web/config/config.php` — `failiem.api_key` (vai `user`/`pass`), `dashboard_password`, `base_url`.

## API

```
POST /api/delivery-galleries/{slug}/sync
Authorization: Bearer {api_token}
```
