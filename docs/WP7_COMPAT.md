# WP7_COMPAT.md — WordPress 7 ühilduvus + strateegilised võimalused

WordPress 7.0 "Armstrong" vabastati **20. mail 2026** — suurim core-relise alates 5.0-st (2018).
See dokument kaardistab, mida Smaily Connect plugin peab tegema **kohe** (ühilduvus) ja
**strateegiliselt hiljem** (uued AI-API-d, mis sobivad otseselt plugin'i ökosüsteemi).

---

## Mida WP 7 toob — kolm peamist asja

**1. PHP 7.4 miinimum** (oli 7.2/7.3). Plugin juba PHP 8.3 peal, niisiis **mitte mure**.
WP 6.9 jääb branchina alles vanematele PHP-versioonidele.

**2. Real-time collaboration** block editor'is (Yjs-põhine) + esimene päris admin-redesign
10+ aasta jooksul (uued värvid, tüpograafia, ikoonid). Visuaal-mõju plugin'i Tailwind-stiilitud
wizard'ile + Settings'ile — vajab auditit.

**3. AI infrastruktuur core'is** — see on **strateegiliselt huvitav**. Kolm seotud API-d:
- **Connectors API** (Settings → Connectors) — platform-level credential-storage ja
  provider-haldus välistele teenustele. Built around `php-ai-client`.
- **Abilities API** — pluginad registreerivad **tüpiseeritud actions**, mida AI-agendid
  saavad avastada ja kutsuda.
- **MCP Adapter** — exposes the whole stack to Model Context Protocol tools (Claude Code,
  Cursor jms).

Märkus: Connectors + Abilities + MCP on **experimental** WP 7-s. Õige strateegia plugin-tiimidele
on **valikuline omaks-võtt**, mitte kogu toote pärisseismine praeguse UI-lepingu peal.

---

## Mida pluginas teha — kohe (Faas 3 katkesta)

Need on **väikesed**, ei pea segama Faas 3 rec-engine tööd:

1. **wp-env matrix laienda** — `.wp-env.json` kõrvale (või sees) WP 7.0 test-konfiguratsioon.
   Code'i integration-suite jookseb mõlemal: WP 6.9.4 (praegune tugi) + WP 7.0 (ühilduvus-test).
   3.0 integration-baas teeb seda automaatselt — `npm run ci:strict` katab mõlemad.

2. **Visuaal-audit** — Code chromium walk'iga vaatab wizard'i + Settings'i WP 7 admin-chrome'i
   taustal. Tõenäoliselt **mõned väikesed nihked** (focus-ring värvid, link-värvid, font-erinevus).
   Brand-pink + dark navy peaks töötama uue palett peal, aga kontrolli.

3. **Plugin-header** — pärast compat-test rohelist:
   - `Tested up to: 7.0`
   - `Requires at least: 6.6` (või kus praegune miinimum on)
   - `Requires PHP: 7.4`

4. **WC HPOS + WP 7** — kontrolli, et HPOS töötab WP 7-l. WC 10.7 peaks olema OK, aga edge-case'id
   on edge-case'id — chromium-walk WC product-create + order-create katab.

5. **Smaily Landing Pages Gutenberg-block** — kui plugin sellise pakub, kontrolli, et töötab
   real-time collab'iga (kaks kasutajat samaaegselt editor'is). Tõenäoliselt OK (block API ei
   muutunud fundamentaalselt), aga märgi.

**Sub-PR scope**: kõik 5 koos ühte väikesse sub-PR-i (näit. 3.x.x WP7 compat). MITTE Faas 3 sees
omaette sub-PR — sobib Faas 3 lõppu või Faas 4 algusesse.

---

## Strateegiline võimalus — Abilities API + Connectors (Faas 4)

**See on dokumendi tähtsam pool.** WP 7 AI-API-d **sobivad otseselt sinu projekti** — Smaily +
rec-engine on **täpselt see**, mida need API-d on loodud ühildama.

### Võimalus 1 — Smaily + rec-engine kui Connectors

WP 7 Connectors hub on **tsentraliseeritud credential-haldus** välistele teenustele. Praegune
plugin omab kogu credential-UI-d (subdomain + username + password Smaily-le, setup-token →
api_key rec-engine'ile). WP 7 Connectors võib selle koorma **üle võtta**:

- Kasutaja seadistab `Settings → Connectors` all **üks kord**
- Plugin loeb credentialid Connectors API kaudu, mitte omast wp_options-tabelist
- UX ühtlustub WP-tervikuga, kasutaja ei pea iga plugina jaoks eraldi credential-UI-d
  õppima

**Risk**: Connectors API on **experimental** WP 7-s — UI-leping võib muutuda. Õige samm pole
kohe migrate'da, vaid **valmistuda**: plugin-poolne credential-loogika peab olema **isoleeritud**
(`Credentials.php`, `RecEngineSettings.php` value-object'id), nii et hiljem
`WP_Connector::get('smaily')` asendamine on lihtne. **Sa juba ehitad õigesti** — see on
ettenägelik arhitektuur.

### Võimalus 2 — Abilities API kui rec-engine'i tugiraam ⭐

**See on kõige huvitavam.** Abilities API laseb pluginal registreerida **tüpiseeritud actions**,
mida AI-agendid (Claude, GPT, kohalik WP-AI) saavad **avastada ja kutsuda** standardse liidese
kaudu.

Smaily Connect'il oleksid loomulikud abilities:
- `smaily.subscribe_user` — lisa kontakt nimekirja
- `smaily.send_email` — saada transactional email
- `smaily.list_workflows` — kuva automaatikad
- `recengine.get_recommendations` — hangi soovitused kasutajale
- `recengine.track_event` — logi browse / ostu-event
- `recengine.merge_identity` — ühenda anonymous → known user

**Strateegiline mõju**: kui Smaily Connect pakub neid abilities'eid, siis **iga AI-tööriist**
WordPress-i ökosüsteemis (merchant's AI assistant, Claude doing site automation, kolmandate-osapoolte
plugins) saab **Smaily-d ja rec-engine'i kutsuda standardse liidese kaudu**. Mitte ainult sinu
plugin → mootor, vaid **kogu ökosüsteem → Smaily**.

**Konkurents**: Mailchimp, Klaviyo, Brevo jt suuremad email-platvormid pole WP 7 Abilities-tuge
kiiruga lisanud. **Kui Smaily on esimene Eesti / Põhja-Euroopa email-platvorm Abilities-tugi**,
on see **eelis võtmiseks** (developer-mind-share, "AI-ready" turundus-positsioneerimine,
MCP-tööriistade-ühilduvus). Faas 4 strateegiline prioriteet.

### Võimalus 3 — MCP Adapter

Abilities API laiendus AI-tööriistadele (Claude Code, Cursor jms) Model Context Protocol kaudu.
**Sõltub Abilities olemas** — kui Abilities registreeritud, MCP Adapter eksponeerib need
automaatselt MCP-tööriistadele. Lisa-investeering väike, väärtus suur (Claude/Cursor saavad
Smaily-d otse kutsuda).

**Pärast Abilities (Võimalus 2) automaatne järgmine samm.**

---

## Mida MITTE muuta praegu

- **Wizard'i credential-loogikat** — Connectors API on experimental, oota stabiilset
- **Admin-UI komponente** — admin-redesign võib värvide-konflikti tekitada, aga oota
  tegelikku kasutaja-tagasisidet. Tailwind on flex enough — tõenäoliselt nihked, mitte purunemine.
- **Block editor integratsiooni ümberkirjutust** — block API ei muutunud fundamentaalselt,
  ainult real-time collab kiht peal
- **Faas 3 rec-engine tööd Abilities-suunas** — Abilities tuleb peale rec-engine valmis-saamist,
  mitte selle keskel

---

## Konkreetne ajakava

| Aeg | Tegevus | Mahukus |
|-----|---------|---------|
| Faas 3 kõrval (nüüd) | wp-env matrix WP 7 + visuaal-audit + plugin-header | ~1 päev |
| Pärast Faas 3 lõppu | WC HPOS + WP 7 sanity-check + block-editor collab-test | ~1 päev |
| **Faas 4 algus** | **Abilities API + MCP Adapter strateegiline disain** | ~1-2 nädalat |
| Faas 4 keskel | Connectors API migrate, kui stabiilseks läinud | ~1 nädal |

**Faas 4 strateegiline prioriteet**: Abilities API. Mitte ainult tehniline ühilduvus, vaid
**positsioneerimine AI-driven WordPress ökosüsteemis**. Smaily kui esimene email-platvorm
Abilities-toega võib olla **suurim turundus-võit** Smaily-le aastate jooksul — eriti, kui
WP 7 AI-suund jätkub WP 7.1, 7.2-s (mis tõenäoliselt).

---

## Allikad

- WordPress 7.0 "Armstrong" — 20. mai 2026
- Make WordPress Core dev-note: Connectors API (märts 2026)
- Make WordPress Core dev-note: Client-Side Abilities API (märts 2026)
- WordPress 7.0 Developer Guide (Nandann Creative, märts 2026)

Vaata ka: LESSONS.md (üldised AI-agendiga-ehitamise õppetunnid),
RECENGINE_TODO.md (mootori-poole staatus), RECENGINE_API_CONTRACT.md (plugin↔mootor leping).
