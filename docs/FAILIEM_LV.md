# Failiem.lv pieslēgšana galerijai

Bildes **fiziski glabājas** Failiem.lv; jūsu lapa rāda sīktēlus un dod lejupielādi caur `/v/media/...` (pāradresācija uz Failiem CDN).

## 1. Servera `config.php`

Pievienojiet (vai pārbaudiet) bloku:

```php
'failiem' => [
    'enabled' => true,
    'api_base' => 'https://api.files.fm',
    'cdn_base' => 'https://failiem.lv',
    'api_key' => '',   // bieži tukšs, ja mapes ir publiskas «LINK» mapes
    'user' => '',
    'pass' => '',
    'pair_suffix_strip' => ['_WEB', '_PRINT', '_web', '_print', '-web', '-small'],
],
```

- **Publiskas mapes** (ikviens ar saiti var skatīt): API atslēga parasti **nav** vajadzīga.
- **Privātas mapes**: Failiem kontā iegūstiet API atslēgu un ierakstiet `api_key`, vai `user` + `pass`.

PHP uz servera jābūt ar **curl** (cPanel → Select PHP Version → Extensions → curl).

## 2. Failu nosaukumi (pāri)

Divās mapēs — **pilns** (PRINT) un **web** (mazāks) — failiem jābūt **vienādam numuram** nosaukumā, piemēram:

| Pilns | Web |
|-------|-----|
| `IMG_0001_PRINT.jpg` | `IMG_0001_WEB.jpg` |
| `foto42_PRINT.jpg` | `foto42_WEB.jpg` |

Sistēma noņem sufiksus `_PRINT`, `_WEB` u.c. un pāra pēc skaitļa (`0001`, `42`).

Ja pāri nesakrīt, adminā pēc sync redzēsiet brīdinājumus «bez pāra».

## 3. Admin — jauna piegādes galerija

1. Atveriet `https://klientiem.edgarsfoto.lv/admin/` → ielogojieties.
2. **Jauna piegāde** (delivery).
3. Ielīmējiet **divas mapes** (ne tikai vienu):

| Lauks | Piemērs |
|-------|---------|
| Galvenā mape (opcija, AI) | `https://failiem.lv/u/3989fkmbt7` |
| Mapes **pilns** | `https://failiem.lv/u/q3v7u5vysz` |
| Mapes **web** | `https://failiem.lv/u/nbhn7ymedk` |

Hash var arī būt tikai `q3v7u5vysz` — sistēma saprot `/u/...` saites.

4. **Saglabāt** → pēc tam **Sinhronizēt no Failiem**.
5. Pārbaudiet, ka parādās bildes skaits; atveriet **Publisko saiti**.

## 4. Ja galerijā tukšs / bildes nerāda

| Pārbaude | Ko darīt |
|----------|----------|
| Vai nospiedāt **Sinhronizēt**? | Bez sync `meta.json` nav `failiem_full` / `failiem_web` hash |
| Vai abas mapes aizpildītas? | Obligāti **pilns + web** |
| Vai mapes ir **publiskas**? | Atveriet mapes saiti inkognito — ja prasa login, vajag `api_key` configā |
| Vai PHP **curl** ieslēgts? | Sync met kļūdu — admin parādīs kļūdu pēc sync |
| Vai serverī ir `failiem_client.php`? | Ja augšupielādēta tikai daļa projekta — augšupielādējiet visu `web/api/` no **EFPIC-GALLERY** |
| Pārīgošana | Pārbaudiet failu nosaukumus; skatiet sync brīdinājumus adminā |
| `/v/media/...` | Atveriet vienu sīktēlu URL — jābūt pāradresācijai uz `failiem.lv/thumb/...`, ne 404 |

### Ātra diagnostika pārlūkā

1. Publiskā galerija → labā poga uz tukšu sīktēlu → **Open image in new tab**.
2. URL būs līdzīgs: `https://klientiem.edgarsfoto.lv/v/media/TOKEN?size=web`
3. Ja **404** — sync nav ielādējis Failiem hash (sync vēlreiz).
4. Ja **302** uz failiem.lv, bet bilde nelādējas — mapes fails dzēsts vai privāts.

### API sync (opcija, tehniski)

```
POST https://klientiem.edgarsfoto.lv/api/delivery-galleries/{slug}/sync
Authorization: Bearer {api_token no config.php}
```

Atbilde ar `stats.paired`, `warnings`.

## 5. Darba plūsma pēc jaunas fotosesijas

1. Augšupielādēt uz Failiem → divas mapes (PRINT + WEB).
2. Admin → galerija → ielīmēt / atjaunināt mapju saites → **Sinhronizēt**.
3. Kārtot bildes, nosūtīt klientam publisko saiti (`klientiem.edgarsfoto.lv`, ne www).

## 6. Mapju hash no saites

No `https://failiem.lv/u/q3v7u5vysz` hash ir `q3v7u5vysz`.

Vecās saites ar `?hash=` arī darbojas.

## 7. Izlases lejupielāde (atlasītās bildes)

**2+ atlasītās bildes** — tāpat kā daļējai galerijai:

1. `POST …/download_selected_zip.php` ar `upload_hash` (mapes hash) un `selected_items[files][]` (katra atlasītās bildes Failiem file hash).
2. Atbilde JSON: `selected_download_key`, `file_host`.
3. Lejupielāde: pārlūks tiek novirzīts tieši uz Failiem `upload_zip_streamer.php?uhash=…&selected_download_key=…&PHPSESSID=…` (+ `img_as_websize` web izmēram).

Mūsu serveris **neveido ZIP** un **nestraumē caur PHP** — tikai reģistrē atlasītos hash un atdod Failiem straumes saiti (kā visa mapes lejupielāde).

**Viena bilde** — tieša saite `down.php?i=…`.

**Fallback:** ja Failiem neatgriež ZIP (vai nav delivery galerijas), maziem apjomiem (≤25 bildes) serveris var salikt ZIP pats.

**Kārtība ZIP iekšā:** Failiem parasti kārto pēc failu nosaukuma mapē, ne pēc atlasīšanas secības. Lielām izlasēm tas ir apzināts kompromiss, lai izvairītos no servera timeout.
