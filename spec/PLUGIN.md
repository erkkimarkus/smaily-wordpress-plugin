# Smaily Connect — WordPress Plugin Spec

**Version**: 0.6 (mootori-poolse `PLUGIN_IMPLEMENTATION_WP.md` v1.0 ülevaatusest 3 täpsustust)
**Base repo**: `sendsmaily/smaily-wordpress-plugin` (fork → `erkki/smaily-wordpress-plugin`)
**Working title**: "Smaily Connect (BETA)" arenduse-faasis; "Smaily Connect" upstream-merge'i järel
**Plugin slug**: `smaily-connect` (säilib upstream-iga)
**Plugin versioon**: `2.0.0-beta.1` BETA-faasis → `2.0.0` upstream-merge'il (major-version-bump tähistab arhitektuurilist nihet)
**Pilot scope**: 1 Pet-sektori klient, ~2000–5000 tellimust ajaloos, ~100–500 pv/päev
**Owner**: Erkki
**Seotud dokumendid**:
- `RECENGINE_API_CONTRACT.md` v1.0 — autoritatiivne API contract (mootori-poolelt)
- `PROJECT_PLAN.md` — Code-le suunatud faaside-plaan
- `STYLE_MAPPING.md` — Smaily disainisüsteemi → Tailwind config (hinnangulisi Variant 3 valikuid)
- `SUGGESTION.md` — prototüübi üleandmise-juhend
- Rec-engine `CLAUDE.md` v1.2 — multi-tenant, kontekstid welcome/cart_abandoned/cross_sell/win_back/newsletter/anniversary

---

## 1. Eesmärk ja scope

Laiendada Smaily Connect plugin rec-engine'i andmeallikaks ilma, et olemasolevad funktsioonid katki läheksid. Plugin kogub e-poodist kõik signaalid (ostud, tooted, kontaktid, browsing, korv-eventid), saadab need samaaegselt **kahte** kohta:

1. **Smaily API** (olemasolev): kontaktide sünk, automation-trigger'id welcome / first_order / abandoned_cart sündmustele
2. **Rec-engine API** (uus): kõik bisnessi-eventid + browsing telemeetria, mille pealt mootor õpib ja teeb personaalseid soovitusi

Klient saab wizard'iga valida, mis featuurid aktiveerida. Browsing on opt-in (vaikimisi väljas, GDPR-tundlik), kõik muu on opt-out.

**MVP-s sees:**

- 6-step onboarding wizard (Connect → Subscribers → WooCommerce → Recommendations → Integrations → Done)
- Multilingual mode-selection (eraldi kontod / eraldi automatsioonid / üks automatsioon harudega)
- Initial backfill: kontaktid Smaily-sse + tellimused/tooted/kliendid rec-engine'isse, progress-näit, re-runable settings'ist
- Real-time event push rec-engine'isse (orders, products, customers, cart events) — Action Scheduler queue + retry
- Client-side beacon browsing-eventidele, batched-mode 30s aknaga vaikimisi, server-side proxy turvalisuseks
- Identity-merge **kolme** triggeriga: WP login/register, checkout, email-link click (`smaily_vt` + `smaily_rec` + `smaily_ctx` URL-parameetrid rec-engine'i kampaania linkidest)
- Cookie-consent integratsioon (WP Consent API + Cookiebot/Complianz/CookieYes detect)
- Welcome + first_order + abandoned_cart automation triggers (multilingual)
- Settings tabid wizardi mirror'iks — **sama React-komponent renderdub mõlemas kontekstis** (`inSettings` prop)
- Mode-vahetus mid-life (B→A puhul vanad mappingud "default" alla)
- HPOS-compatible (`declare_compatibility`, `wc_get_order()` ainult, mitte SQL `wp_posts` vastu)
- GDPR endpointid: opt-out, delete, export + WP Privacy hooks integration
- Engine version compatibility check (`X-Engine-Version` header)
- Tõlked: ET + EN minimaalselt
- WordPress.org Plugin Check (PCP) läbib roheliselt

**Selgelt väljas (v1.x backlog):**

- CF7 / Elementori vormide rec-engine event'id (praegu ainult Smaily-sse)
- Redis-queue, dedicated workerid (Action Scheduler piisab pilootmahul)
- Self-service A/B testimine rec-block performance'i mõõtmiseks (rec-engine'is endas)
- Smaily-poolne client-side embed (rec-block iframe poesse) — see on hilisem
- Migration tooling teiste ESP-de pealt
- Per-product opt-out browsing'st (nt sensitiivsed kategooriad)
- WP Network (multisite) network-wide aktivatsioon
- Webhook back-channel (rec-engine → plugin push) — reserveeritud, v2 prioriteet

---

## 2. Suhe upstream-pluginale ja fork-strateegia

**Fork-otsuse põhjendus:** Smaily Connect on juba läbinud WordPress.org marketplace KK (security review, escape/sanitize, i18n setup). Olemasolev kood on **väärtuslik baas**, mitte ballast. CF7 ja Elementori integratsioonid töötavad ja on stabiilsed — pole mõtet neid nullist kirjutada. Pikemas plaanis on upstream-merge **diff** olemasoleva plugin'i otsa, mitte täielik asendamine.

**Faili-tasandil**: fork säilitab kogu olemasoleva failistruktuuri (`includes/`, `admin/`, `integrations/`, `blocks/`, `public/`) ja olemasoleva integratsiooni Contact Form 7-ga, Elementoriga, WooCommerce'iga, WordPress core'iga. Uue koodi paigutamine:

- `includes/RecEngine/` — kõik rec-engine'i klassid (Client, EventQueue, Backfill, Beacon, Identity, GDPR)
- `includes/Wizard/` — wizard controller, step-detection, env-detection
- `includes/Settings/` — settings page controller
- `includes/Multilingual/` — WPML/Polylang/TranslatePress/site_locale adapterid
- `includes/Smaily/` — laiendab olemasolevat Smaily-API klassi: AutomationRouter (multilingual-aware), BackfillJob
- `admin/src/` — React-bundle (Settings + Wizard, sama komponent inSettings-propiga)
- `public/js/beacon.js` — klientpoolne tracker (eraldi entry, ei sõltu admin-bundle'ist)
- `migrations/` — uued DB-migration failid (vt §8)

**Plugin header BETA-faasis**:
```
Plugin Name: Smaily Connect (BETA)
Description: ... (BETA: extended e-commerce sync and recommendations engine integration)
Version: 2.0.0-beta.1
```

**Versioon**: BETA-faasis `2.0.0-beta.1`, `2.0.0-beta.2`, jne. Upstream-merge'il `2.0.0` stable. Praegune ametlik plugin on `1.6.1`.

**Distributsioon BETA-faasis**: GitHub Release tarball'ina (`smaily-connect-2.0.0-beta.1.zip`), manual install kliendile. **Mitte** WordPress.org-i ülespanek enne upstream-merge'i.

**Konflikt-detection eemaldatud**: kuna plugin-slug säilib (`smaily-connect/smaily-connect.php`), ei saa BETA ja stable korraga aktiivsed olla — uue paigaldamine asendab vana. Pole vaja `is_plugin_active()` check'i, mis algses spec'is oli.

**Upstream-merge plaan** (post-BETA):
1. BETA töötab pilootkliendi juures 1-2 kuud
2. Stabiilseks tunnistatud featuurid → PR `sendsmaily/smaily-wordpress-plugin` peale
3. Smaily tiim review'b, requestib muudatusi
4. Merge → release `2.0.0` WordPress.org-i kataloogi

---

## 3. Arhitektuur ülevaates

```
┌────────────────────────────────────────────────────────────────┐
│  WordPress + WooCommerce (HPOS)                                │
│                                                                │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐      │
│  │ Wizard /     │    │ Settings     │    │ Beacon JS    │      │
│  │ Admin UI     │    │ Tabs         │    │ (public)     │      │
│  │ (React)      │    │ (React,      │    │ sendBeacon   │      │
│  │              │    │ same comps)  │    │              │      │
│  └──────┬───────┘    └──────┬───────┘    └──────┬───────┘      │
│         │                   │                   │              │
│         ▼                   ▼                   │              │
│  ┌────────────────────────────────┐             │              │
│  │ Plugin Core                    │             │              │
│  │  - Connection Manager          │             │              │
│  │  - Multilingual Router         │             │              │
│  │  - Event Producer (hooks)      │             │              │
│  │  - Identity Merger             │             │              │
│  └─────┬──────────────┬───────────┘             │              │
│        │              │                         │              │
│        ▼              ▼                         ▼              │
│  ┌──────────┐    ┌──────────────┐         ┌──────────────┐     │
│  │ Smaily   │    │ Event Queue  │         │ Beacon       │     │
│  │ Client   │    │ (DB table)   │         │ Buffer       │     │
│  │          │    │              │         │ (transient)  │     │
│  └────┬─────┘    └──────┬───────┘         └──────┬───────┘     │
│       │                 │                        │             │
│       │          ┌──────▼────────────────────────▼─────┐       │
│       │          │ Action Scheduler                    │       │
│       │          │ (durable queue + cron)              │       │
│       │          └──────┬──────────────────────────────┘       │
│       │                 │                                      │
└───────┼─────────────────┼──────────────────────────────────────┘
        │                 │
        ▼                 ▼
┌─────────────────┐   ┌─────────────────────────────────────────┐
│ Smaily API      │   │ Rec-Engine API                          │
│ (subdomain.     │   │ (recengine.smaily.com or similar)       │
│  sendsmaily.net)│   │ Bearer-token auth, multi-platform       │
└─────────────────┘   └─────────────────────────────────────────┘
```

**Andmevood:**

- **Smaily-poole**: kontaktide sünk (cron + real-time), automation-triggerite kutsumine (welcome / first_order / abandoned_cart, multilingual-routed). Olemasolev kood + täiendused first_order tarbeks.
- **Rec-engine'i poole — durable**: catalog, customers, orders eventid läbi Action Scheduler queue. Retry kuni õnnestumiseni, exponentsi-backoff.
- **Rec-engine'i poole — best-effort**: browse-eventid läbi server-side proxy + 30s batched buffer + Action Scheduler flush. Failed batchid drop'itakse pärast 1-2 retry'd (mootori ML on 5-10% kaotuse-tolerantne).
- **Identity merge**: anon-visitor saab `smaily_anon_sid` cookie (UUID v4). Kolm trigger'it (login/checkout/email-link) saadavad `identity.merge` eventi, rec-engine seob ajaloo emailiga.
- **Beacon turvalisus**: JS `sendBeacon` saadab ainult WP REST endpoint'isse (`/wp-json/smaily-connect/v1/beacon`). Server-side PHP lisab Authorization header'i ja proxy'b rec-engine'isse. **API-key MITTE KUNAGI client-side koodis.**

---

## 4. Multilingual mudel

Plugin detekteerib paigaldatud keeled (eelistuses **WPML → Polylang → TranslatePress → site_locale fallback**). Kui detekteeritakse rohkem kui üks keel, näidatakse wizard'i Step 1-s lisaküsimust:

> **How is your Smaily setup organized for languages?**
> ○ Separate Smaily accounts per language *(Mode A)*
> ○ One account, separate automations per language *(Mode B)*
> ○ One account, one automation with language branching *(Mode C)*

Vaikimisi: **Mode B** (kõige tüüpilisem).

**Mode A** — Step 1 laieneb: iga detekteeritud keele jaoks oma "Smaily subdomain + API user/pass" sektsioon + "Test connection" nupp. Lisaks "Default account for contacts without language" valik. Iga keel saab oma `SmailyClient` instance'i, mille `MultilingualRouter` valib kontakti `language` põhjal.

**Mode B** — üks credential-set Step 1-s. Step 3 iga trigger-event'i juures näidatakse tabel keelte kaupa. **Default-fallback** valitakse radio-button'iga ühe keele-rea peal (mitte eraldi "Default" rida) — prototüübi Variant 1 disainivalik.

**Mode C** — üks credential, single dropdown per trigger event. Plugin saadab `language` välja kontaktis kaasa, Smaily-poolne workflow teeb conditional branchingu ise. Plugin selle haru-loogikas ei osale.

**Üks-keelne pood** — küsimust ei kuvata, jäetakse vaikimisi single-dropdown mudelisse (mehaaniliselt Mode C nullvariant).

**Multilingual catalog rec-engine'i poole**: `name`, `description`, `product_url` saadetakse objektidena per-language (`{"et": "...", "en": "..."}`) WPML/Polylang/TranslatePress API-de kaudu. Tõlgimata sisuga keelt ei saadeta — rec-engine fallback'ub default'ile. Single-keelne pood saadab string-formaadis, backward-compatible.

**Mode-vahetus** mid-life: Settings → Connection → "Change multilingual mode" nupp. Vahetuse puhul:
- **B → A**: olemasolev credential-set saab "Default account" rolli, klient lisab uued kontod uute keelte jaoks. Automation-mappingud säilivad "Default" rea all, kuni klient need üle kirjutab.
- **A → B**: küsib, milline credential-set saab uueks "ainsaks". Teised arhiveeritakse (mitte kustutatakse — kui klient tagasi vahetab, on credentialid alles). Automation-mappingud "Default" rea all säilivad.
- **B ↔ C**: lihtne. C → B puhul kõik keele-read saavad sama workflow_id, mis oli C-s ainus. B → C puhul küsitakse, milline keele-rida saab uueks "ainsaks".

---

## 5. Wizard step-by-step

### Step 1 — Connect

**Sisu:**
- Smaily subdomain + API user + API password (olemasolev väljaõpe upstream'ist)
- "Test connection" nupp → kutsub Smaily `/api/account/` validation endpoint'i
- Vahepealne lisaküsimus (kuvatakse pärast esimese credential-set'i validation'it): "How is your Smaily setup organized for languages?" (vt §4) — ainult kui mitu keelt
- Mode A puhul: täiendavad credential-set'id per keel, igaüks oma test connection'iga
- **Optional sektsioon "Recommendations engine"**: setup-token URL paste'imine (**one-time URL flow**, vt §8). Plugin POST'ib URL-i, saab tagasi `tenant_id`, `api_key`, `engine_base_url`, `engine_version`, `config` (cookie nimed jne). Kuvatakse: "Connected to tenant: My Pet Shop ✓"
- Skip-able — kui klient rec-engine'i ei lisa, näeb Step 4-s turundus-sisu (4b variant)

**Andmevälja salvestus:** `wp_options`-tabelis krüpteeritud (Smaily Connectiga sama lähenemine, vt olemasolev `Smaily\Options` klass)

---

### Step 2 — Subscribers

**Sisu:**
- Linnuke "Sync contacts to Smaily" (vaikimisi sees)
- Sünkroniseeritavate väljade valik (checkboxide grupp): `first_name`, `last_name`, `phone`, `birthday`, `gender`, `customer_group`, `customer_id`, `first_registered`, `nickname`, `site_title`
- Linnuke "Show subscription checkbox during WordPress registration"
- Linnuke "Show subscription checkbox during WooCommerce checkout"
- Info-blokk "Subscription form" — kuidas kasutada Gutenbergi blocki ja shortcode'i `[smaily_subscription_form]`
- **Initial backfill panel**: "Import existing users (X users) to Smaily" + "Start backfill" nupp, progress-bar, status (idle / running / completed / failed), "Last run: [timestamp]"

**Mode A puhul:** backfill kuvab keele-spliti — kui WP kasutaja `language` meta on `et`, läheb Estonian credential-set'i kontosse jne. Default account neile, kel keel puudub.

**Backfill mehhaanika:** Action Scheduler job, batch 100 kasutajat / batch, 30s interval. Rakendus märgib iga kasutaja `_smaily_synced_at` meta'sse. Re-run on idempotentne — uuendab ainult need, mille meta on vanem kui X päeva või puudub.

---

### Step 3 — WooCommerce

**Sisu kolm sektsiooni:**

**3a. Welcome email** (uue kontakti loomisel)
- Linnuke "Send welcome email to new subscribers" (vaikimisi sees)
- Trigger: `user_register`, `woocommerce_created_customer`, või subscription-form submit
- Workflow valik per multilingual mode (vt §4)

**3b. First order email**
- Linnuke "Send first-order email to first-time buyers" (vaikimisi sees)
- Trigger: `woocommerce_order_status_completed` puhul **kui** kliendi ostuajalugu enne seda tellimust on 0 (kontroll: `wc_get_customer_order_count($customer_id) === 1`)
- Workflow valik per multilingual mode

**3c. Abandoned cart**
- Linnuke "Send abandoned cart reminders" (vaikimisi sees)
- Cutoff time (minutid, default 30, min 10 — olemasolev) + workflow valik per multilingual mode
- Olemasolev cron iga 15 min migreeritakse Action Scheduler'isse järjepidevuse mõttes

**Implementeerimisnoot:** kõik kolm kasutavad sama internal API-d `MultilingualRouter::triggerAutomation($trigger, $contact_data, $additional_fields)`, mis valib õige workflow_id ja õige API-credential-set'i (Mode A puhul).

---

### Step 4 — Recommendations

**Kaks varianti, sõltuvalt Step 1 valikust:**

**4a. Kui rec-engine ühendatud:** featuuride valikud
- Linnuke "Sync orders to recommendations engine" (vaikimisi sees) + initial backfill panel
- Linnuke "Sync customers to recommendations engine" (vaikimisi sees)
- Linnuke "Sync products to recommendations engine" (vaikimisi sees)
- Linnuke "Track cart events in real-time" (vaikimisi sees) — `cart.item_added`, `cart.item_removed`, `cart.viewed`
- **Linnuke "Track browsing behavior" (vaikimisi VÄLJAS)** + märkus consent-vajaduse kohta
- Combined backfill panel orders/customers/products jaoks — kolm progress-bari, üks "Start" nupp (mootor õpib joined-andmetest)
- Browse-event batching toggle (vaikimisi 30s batched-mode, optional single-event-mode disabled)

**4b. Kui rec-engine ühendamata:** turundus-sisu
- Hero: rec-block screenshot Smaily e-mailist (statiline asset pluginas)
- Pealkiri: "Personalised product recommendations in every email"
- Lühi-selgitus 6 konteksti kohta: welcome / cart-abandoned / cross-sell / win-back / newsletter / anniversary
- Eraldi karp baseline'iga: "Pilot clients see 2-8× revenue from targeted product emails compared to generic newsletters"
- CTA: "Activate recommendations engine →" (link Smaily lehele või contact-vormile)
- "Already have an endpoint?" link → tagasi Step 1-le rec-engine'i sektsiooni

**Backfill mehhaanika rec-engine'i poole:** Action Scheduler durable queue, batch 100 entity'd korraga, rec-engine tagastab `processed: N`. Plugin liigutab cursor'it edasi. SKU primaarne identifier, tühja SKU-ga tooted skipitakse + admin notice'iga loend.

**Variable products**: iga variant saadetakse eraldi tootena oma SKU-ga (mitte parent/children hierarhias) — rec-engine'i Bayes shrinkage `lift_local` arvestab variante eraldi.

---

### Step 5 — Integrations

**Sisu** — ainult informatiivne, mitte konfiguratsioon:
- "Elementor: Smaily subscription form widget is available in Elementor editor. [Open Elementor →]"
- "Contact Form 7: Configure individual forms in Forms → [select form] → Smaily tab. [Open Forms →]"
- "Smaily Landing Pages: Embed via Gutenberg block. [Add new page →]"

Iga rida lingiga vastavasse WP-administraatorinvaiku (`admin_url()`, staying in-window — mitte `target="_blank"`). Disainis kuvatakse kui "card"e, mitte tihedat listi.

---

### Step 6 — Done

**Sisu:**
- Pealkiri "You're all set"
- Kokkuvõte aktiveeritust (live state-reflection ✓/○ indikaatoritega)
- "View Settings →" nupp
- "Open Smaily dashboard →" link (dünaamiline URL subdomain'ist)
- "Open Recommendations dashboard →" link (kui rec-engine ühendatud, dünaamiline URL tenant info'st)
- Viide Event Log-le, kus näha kõik sünk-eventid

---

## 6. Settings tabid

**Sama React-komponent renderdub mõlemas vaates** — wizard step ja settings tab on **üks komponent** `inSettings` propi erinevusega. `Step1Connect` muutub Settings'is "Connection" tab'iks ilma duplikaadita. See on prototüübi-vestluse kriitiline arhitektuurne otsus, mis tagab UI-loogika ühe-allika-tõe ja state'i automaatse sünkroonsuse.

Tab'id wizard'i järel:
1. **Connection** — Smaily credentials + rec-engine credentials + multilingual mode + "Change mode" nupp + "Re-run setup wizard" nupp
2. **Subscribers** — sünkroniseeritavate väljade valik, subscription-checkbox'id, backfill re-run
3. **WooCommerce** — welcome / first_order / abandoned_cart workflow mappingud + cutoff time
4. **Recommendations** — kõik sünk-linnukesed + browsing toggle + backfill re-run per data-type
5. **Integrations** — informatiivne, sama mis wizard Step 5
6. **Event Log** — eraldi tab (vt §13)

Igal tabil "Save changes" nupp, mis kehtib ainult selle tabi valikutele.

---

## 7. Andmemudel (kohalik DB)

Kõik tabelid prefiks'iga `{$wpdb->prefix}smly_plus_` (Smaily-pool) ja `{$wpdb->prefix}smly_rec_` (rec-engine-pool).

### `smly_plus_event_queue`
Smaily-poole eventide queue (kontaktide sünk, automation triggers).

```sql
CREATE TABLE {$prefix}smly_plus_event_queue (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_type VARCHAR(64) NOT NULL,
  entity_id VARCHAR(128),
  payload LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL,
  attempts SMALLINT UNSIGNED DEFAULT 0,
  last_error TEXT,
  status ENUM('pending','sent','failed') DEFAULT 'pending',
  INDEX idx_status_created (status, created_at)
);
```

### `smly_rec_event_queue`
Rec-engine'i poole durable eventid (catalog, customers, orders). **NB**: browse-eventid **EI** kirjutu siia tabelisse — need lähevad transient-buffer'isse, mitte durable queue'sse.

```sql
CREATE TABLE {$prefix}smly_rec_event_queue (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_type VARCHAR(64) NOT NULL,
  entity_id VARCHAR(128),
  event_uuid CHAR(36) NOT NULL,  -- per-event UUID idempotency'iks
  depends_on_event_id CHAR(36) NULL,  -- event_uuid eelmisest event'ist (vt §11)
  payload LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL,
  attempts SMALLINT UNSIGNED DEFAULT 0,
  max_attempts SMALLINT UNSIGNED DEFAULT 5,
  last_error TEXT,
  status ENUM('pending','sent','failed','blocked') DEFAULT 'pending',
  next_retry_at DATETIME,
  INDEX idx_status_retry (status, next_retry_at),
  INDEX idx_depends_on (depends_on_event_id),
  UNIQUE KEY uniq_event_uuid (event_uuid)
);
```

**Status `blocked`** tähistab event'i, mille `depends_on_event_id`-le viidatud event ei ole veel `sent`. Block lifecycle:
- Algselt event sisestatakse `status='pending'`
- Flush-job kontrollib enne saatmist `depends_on_event_id` staatust
  - Kui dependency `sent` → event saadetakse normaalselt
  - Kui dependency `pending`/`blocked` → event jääb `pending`, jätkub järgmise flush'iga
  - Kui dependency `failed` → event märgitakse `failed`, `last_error='dependency_failed'`

### `smly_plus_backfill_job`
Backfill'ide olek (kontaktid + rec-engine andmetüübid).

```sql
CREATE TABLE {$prefix}smly_plus_backfill_job (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_type VARCHAR(64) NOT NULL,
  target VARCHAR(64) NOT NULL,
  status ENUM('idle','running','completed','failed') DEFAULT 'idle',
  total_count INT UNSIGNED,
  processed_count INT UNSIGNED DEFAULT 0,
  cursor VARCHAR(255),
  started_at DATETIME,
  completed_at DATETIME,
  error_message TEXT,
  UNIQUE KEY uniq_type_target (job_type, target)
);
```

### `smly_plus_automation_mapping`
Multilingual-aware automation-workflow mapping.

```sql
CREATE TABLE {$prefix}smly_plus_automation_mapping (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  trigger_type ENUM('welcome','first_order','abandoned_cart') NOT NULL,
  language VARCHAR(10) NOT NULL,
  account_key VARCHAR(64) NOT NULL,
  workflow_id VARCHAR(128) NOT NULL,
  is_default_fallback BOOLEAN DEFAULT FALSE,
  UNIQUE KEY uniq_trigger_lang_account (trigger_type, language, account_key)
);
```

### `smly_rec_visitor`
Anon visitor → identified merge tracking.

```sql
CREATE TABLE {$prefix}smly_rec_visitor (
  visitor_id CHAR(36) PRIMARY KEY,
  email VARCHAR(255),
  identified_at DATETIME,
  identified_source ENUM('login','register','checkout','email_link'),
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  INDEX idx_email (email)
);
```

**Beacon buffer**: transient-tabel **EI** ole vajalik — kasutame WordPress transient API-t (`set_transient`, `get_transient`) 30-sekundi browse-event buffer'iks. Action Scheduler flush'ib transient sisu rec-engine'isse iga 30s ja kustutab transient'i.

---

## 8. API kontraadid

### Smaily-poole (olemasolev + täiendused)

**Olemasolev** (Smaily Connect 1.6.1):
- `GET /api/account/` — connection validation
- `GET /api/autoresponder/` — workflow listing
- `POST /api/contact/` — single contact sync (upsert)
- `POST /api/autoresponder/{id}` — trigger automation

### Rec-engine'i poole

**Autoritatiivne contract**: vt `RECENGINE_API_CONTRACT.md` v1.0. Allpool on viidatud kokkuvõte plugin-side implementatsiooni jaoks.

**Auth flow — Setup-token URL paste:**

Klient klõpsab rec-engine'i admin UI-s "Setup new tenant" → genereeritakse one-time URL `{engine_base_url}/setup/{token}` (24h TTL, one-time use) → klient kopeerib URL-i WP-plugin'i Step 1 setup-välja. Plugin extract'b token'i ja kutsub:

```
POST {engine_base_url}/setup/exchange
Headers:
  Content-Type: application/json
  User-Agent: SmailyConnect/2.0.0-beta.1 (WordPress; WooCommerce)
Body:
{
  "setup_token": "abc123xyz",
  "plugin_info": {
    "name": "smaily-connect",
    "version": "2.0.0-beta.1",
    "platform": "wordpress",
    "platform_version": "6.4.2",
    "ecommerce_platform": "woocommerce",
    "ecommerce_platform_version": "8.5.1",
    "site_url": "https://shop.example.com"
  }
}
```

**Vastus 200 OK** sisaldab (vt API_CONTRACT §1 täielik):
- `tenant_id`, `tenant_name`, `api_key`, `engine_base_url`, `engine_version`
- `endpoints` objekt (täielikud URL-id 9 endpoint-i jaoks)
- `config` objekt: cookie nimed (4 nime), TTL-id, rate limits, supported_languages, URL-param-nimed

**Plugin salvestab kõik need väärtused** `wp_options`-i krüpteeritult (`autoload=false`). **Mitte hardcode'ida** ühtegi URL-i, cookie-nime ega URL-parameetri-nime kuhugi plugin-koodi — alati lugeda config'st.

**Engine base URL on muutuv**: pilootmise ajal `https://re-seven-indol.vercel.app` (Vercel preview), production-versiooni puhul muutub. Plugin handle'b seda transparently — kui mootor migreerub, klient teeb uue setup-token exchange'i ja config uuendub.

**Setup-URL override-mehhanism**: esimese setup-call'i jaoks (kui veel pole `engine_base_url` config'is) vajab plugin alg-URL-i. Vaikimisi: `https://re-seven-indol.vercel.app/setup/exchange` konstandi tasemel `Constants` klassis. **Override-mehhanism filter-i kaudu**:

```php
// Filter: smaily_connect_setup_url
$setup_url = apply_filters(
    'smaily_connect_setup_url',
    'https://re-seven-indol.vercel.app/setup/exchange'
);
```

Production-migratsiooni puhul Erkki uuendab konstandi-väärtust ühe-rea-PR-iga ja release'b uue plugin-versiooni. Klient saab plugin-update'i, restart'ib setup-flow'i uue URL-iga. Filter võimaldab ka **per-site override-i** (näit testkeskkonna jaoks).

**Auth header iga edasise päringu peal**: `Authorization: Bearer {api_key}`.

**Engine version check**: iga API-vastusega tuleb `X-Engine-Version: 1.0.0` header. Plugin teab oma `compatible_engine_version_range` (näit `>=1.0.0,<2.0.0`). Out-of-range: admin notice "Plugin update available", aga plugin **ei keeldu** töötamast (graceful degradation).

**Error-handling**: vt API_CONTRACT §"Vea-käsitsemine". 4xx ei retry'da (validation, auth, not_found), 429/5xx retry'takse exponential backoff'iga. Iga error-response sisaldab `request_id` — plugin kuvab seda admin notice'is debug-jaoks.

**Rate limit**: 100 req/sec catalog/customers/orders'ile, 500 req/sec browse'ile, 10 req/min setup-exchange'ile. 429 vastuses `Retry-After` header — plugin austab, eksponentsi-backoff max 5 katset.

---

## 9. Event-tüübid

**Smaily-poolsed eventid** (smly_plus_event_queue):

| Event type | WP hook | Sihtmärk |
|------------|---------|----------|
| `contact.sync` | `user_register`, `profile_update`, `woocommerce_save_account_details` | `POST /api/contact/` |
| `automation.welcome` | `user_register`, subscription-form submit | `POST /api/autoresponder/{wf_id}` |
| `automation.first_order` | `woocommerce_order_status_completed` (kui esimene tellimus) | `POST /api/autoresponder/{wf_id}` |
| `automation.abandoned_cart` | cron (15 min) | `POST /api/autoresponder/{wf_id}` |

**Rec-engine'i durable eventid** (smly_rec_event_queue):

| Event type | WP hook | Endpoint |
|------------|---------|----------|
| `catalog.upsert` | `save_post_product` (uue ja muutuse puhul) | `/api/v1/ingest/catalog` |
| `catalog.delete` | `before_delete_post` (kui product) | `/api/v1/ingest/catalog` |
| `customer.upsert` | `woocommerce_created_customer`, `user_register`, `profile_update`, `woocommerce_save_account_details` | `/api/v1/ingest/customers` |
| `order.created` | `woocommerce_checkout_order_processed` (HPOS-aware) | `/api/v1/ingest/orders` |
| `order.updated` | `woocommerce_order_status_changed` | `/api/v1/ingest/orders` |
| `identity.merge` | sünteetiline (vt §10) | `/api/v1/identity/merge` |

**Kõik rec-engine eventid sisaldavad** (lisaks event-tüüpi spetsiifilistele väljadele):
- `event_id` — UUID v4 idempotency'iks (rec-engine dedupes 60-min aknas)
- `session_id` — visitor anon-session ID (= `smaily_anon_sid` cookie). **Iga** event'iga, sh `customer.upsert` ja `order.created`, et rec-engine saaks teha retroactive session-to-customer bindingu

**Order-eventide attribution payload** (`order.created`, `order.updated`): plugin loeb checkout-hookis **4 küpsist** ja salvestab need **kohe order-meta'sse** (`woocommerce_checkout_order_processed` hook'is). Hiljemates hookides (näit `woocommerce_order_status_completed` admin-poolt) loeme andmed order-meta'st, mitte küpsisest — küpsised pole hilisemates kontekstides saadaval.

**Order-meta võtmed**:
- `_smaily_anon_session_id` (= `smaily_anon_sid` cookie hetkel)
- `_smaily_visitor_token` (= `smaily_rec_uid` cookie hetkel)
- `_smaily_rec_id` (= `smaily_rec_id` cookie hetkel)
- `_smaily_rec_ctx` (= `smaily_rec_ctx` cookie hetkel)

**Order-event payload** sisaldab:
- `smaily_rec_id` (last-touch rec-id, mis soovituse-kliki klient tegi)
- `smaily_visitor_token` (email-clickilt saadud visitor token)
- `smaily_rec_ctx` (last-touch kontekst — welcome/cart_abandoned/jne)
- `session_id` (anon-session ID)

Mootor teeb attribution-matching'u nendelt 4-lt väljalt — vt API_CONTRACT §5 "Attribution flow".

**Identity-merge dependency**: `identity.merge` event'i saatmiseks **peab** sama email-iga customer **eelnevalt rec-engine'is eksisteerima** (`POST /api/v1/ingest/customers`-iga sünkitud). Plugin'i event-queue mehhanism peab arvestama event-järjekorraga: customer.upsert **enne** identity.merge sama emaili kohta. Kui customer.upsert ebaõnnestub, identity.merge **jääb pending** kuni eelmine õnnestub. Vt §11 `event_dependency` mehhanism.

**Browse-eventid** (transient buffer → flush):

| Event type | Trigger (client-side JS) | Endpoint |
|------------|--------------------------|----------|
| `browse.page_view` | document load | `/api/v1/ingest/browse` |
| `browse.product_view` | product page detect | sama |
| `browse.category_view` | category page detect | sama |
| `browse.search` | search-result page | sama |
| `browse.cart_add` | `add_to_cart` JS event | sama |
| `browse.cart_remove` | `remove_from_cart` JS event | sama |
| `browse.cart_view` | cart-page load | sama |

Iga browse-event sisaldab `event_id` UUID-d idempotency'iks (rec-engine dedupes 60-min aknas).

**Browse-event `source` väli**: plugin saadab `source: "plugin_woo"` vastavalt API_CONTRACT v1.0 §6 enum-ile. See identifitseerib **tehnoloogilist platvormi** (WooCommerce), mitte konkreetset plugin-implementatsiooni — sama loogika nagu `plugin_shopify` (Shopify), `plugin_magento` (Magento) tulevikus. Plugin-side audit-trail tuleb User-Agent header'ist ja setup-päringu `plugin_info` objektist.

---

## 10. Cookies, identity, GDPR

### Cookie strateegia

| Cookie | TTL | Eesmärk | HttpOnly | Secure | SameSite |
|--------|-----|---------|----------|--------|----------|
| `smaily_rec_uid` | 365 päeva | Visitor token (email-link click) | false | true | Lax |
| `smaily_anon_sid` | 30 päeva | Anonymous session ID (UUID v4) | false | true | Lax |
| `smaily_rec_id` | 30 päeva | Last-touch rec attribution | false | true | Lax |
| `smaily_rec_ctx` | 30 päeva | Last-touch context | false | true | Lax |
| `smly_btok` | 24h | Beacon HMAC token | false | true | Strict |

**Cookie nimed tulevad setup-response `config` objektist** — mitte hardcode'ida.

**HttpOnly=false** vajalik, sest JS beacon peab cookie'd lugema. Turvalisus tagatud server-side proxy mustriga (§3).

**Üks persistent visitor_id** — sama brauseriga tagasi tulles säilib `smaily_anon_sid` (30 päeva). Uus brauser/seade → uus ID. Identity merge ühendab senise ID ajaloo emailiga.

### Consent

- **WP Consent API** (WP 6.5+): `wp_set_consent`, plugin registreerub kui `marketing` ja `statistics` kategoorias
- **Detect**: Cookiebot (`window.Cookiebot`), Complianz (`window.cmplz_*`), CookieYes (`window.getCkyConsent`)
- Kui consent puudub: browsing-beacon ei käivitu, `smaily_anon_sid` küpsist ei seata
- Backend server-side eventid (orders, cart-add server-side, customer) **ei sõltu** consent'ist — need on transactional
- Kui klient on identifitseeritud (email teada checkout'il/login'il/email-link'il), browsing võib jätkuda kui marketing consent on antud

### Identity-merge triggerid

Kolm sõltumatut mehhanismi:

1. **WP login / registration**: hook'id `wp_login`, `user_register`, `woocommerce_created_customer`. Plugin loeb `smaily_anon_sid` küpsisest praeguse visitor_id ja saadab `identity.merge` event'i koos kasutaja emailiga ja `source: 'login'` (või `'register'`).

2. **Checkout**: hook `woocommerce_checkout_order_processed`. Sama loogika, `source: 'checkout'`.

3. **Email link click** (`smaily_vt` URL-parameeter):
   - Rec-engine'i kampaania-render lisab iga link'i query-parameetrid `smaily_vt={signed_token}` (visitor token, JWT, sisaldab `{ email, tenant_id, expires_at }`, 30 päeva TTL), `smaily_rec={rec_id}` (millise soovituse klikk), `smaily_ctx={context}` (welcome/cart_abandoned/jne)
   - Plugin registreerib WP `template_redirect` hook'i, mis detekteerib parameetrid URL-ist
   - Verifitseerib HMAC-allkirja (rec-engine'i pub-key, plugin saab selle setup-token exchange'is)
   - Decode'ib `email` payloadist
   - Kontrollib `smaily_rec_uid` küpsist — kui puudub, seab uue; kui olemas, säilitab
   - Seab ka `smaily_rec_id` ja `smaily_rec_ctx` küpsised last-touch attribution'iks (30 päeva)
   - Saadab `identity.merge` event'i koos `{ visitor_id, email, source: "email_link" }`
   - Strip'ib `smaily_vt`, `smaily_rec`, `smaily_ctx` URL-ist `wp_safe_redirect`-iga puhta URL-i peale (browseri history clean)
   - **NB**: `utm_source`, `utm_campaign` jäävad URL-i puutumata (GA-analytics kasutab neid). MITTE kasutada `utm_content`-i, mis rikub GA A/B-testimist.

### GDPR endpointid

- **`DELETE /api/v1/customer/{email}`** — kasutatakse WP `wp_privacy_personal_data_eraser_*` hook'idest
- **`GET /api/v1/customer/{email}/export`** — kasutatakse WP `wp_privacy_personal_data_exporter_*` hook'idest, vastus lisatakse WP-eksport-ZIP-i
- **`POST /api/v1/customer/{email}/opt-out`** — kasutatakse "My Account" → "Privacy" toggle'ist "Don't use my data for recommendations"

---

## 11. Background-jobid (Action Scheduler)

| Job slug | Frequency | Purpose |
|----------|-----------|---------|
| `smly_plus_flush_event_queue` | iga 60s | Smaily-poolne queue flush |
| `smly_plus_retry_failed_events` | iga 5 min | Re-try failed eventid (max 5 attempts) |
| `smly_plus_contact_sync` | iga päev | Daily kontaktide sünk Smaily-sse |
| `smly_plus_abandoned_cart` | iga 15 min | Abandoned cart trigger (migreeritud upstream WP-Cron-ist) |
| `smly_plus_backfill_runner` | one-shot per job | Backfill batchide töötlemine |
| `smly_rec_flush_event_queue` | iga 60s | Rec-engine durable queue flush |
| `smly_rec_flush_browse_buffer` | iga 30s | Browse-event transient → rec-engine batch (kuni 100) |
| `smly_rec_retry_failed_events` | iga 5 min | Rec-engine durable retry, exponentsi-backoff |
| `smly_rec_visitor_cleanup` | iga päev | Anon visitorid > 365 päeva ilma identify'ta → kustutus |

**Action Scheduler bundle composer-iga** (`woocommerce/action-scheduler`), **ei sõltu** WC olemasolust. Plugin'i `composer.json`:

```json
"require": {
  "php": ">=8.0",
  "woocommerce/action-scheduler": "^3.7"
}
```

### Deduplication ühe-akna sees

Vältimaks duplikatsiooni (näit. `save_post_product` võib käivituda mitu korda samas requesti'is — autosave + manual save), kasutab plugin Action Scheduler `as_next_scheduled_action` checki:

```php
$hook_args = ['product_id' => $product_id];
if (as_next_scheduled_action('smly_rec_sync_catalog_product', $hook_args)) {
    return;  // Already queued, don't add duplicate
}
as_enqueue_async_action('smly_rec_sync_catalog_product', $hook_args, 'smaily-connect-catalog');
```

See on lihtsam kui custom dedupe-tabel ja töötab automatically — AS oma "scheduled" järjekorras enne flush'i. Eventi-tüüpide jaoks, kus mitu kõnet **on legitiim** (näit `customer.upsert` profile + order korraga), kasutame eraldi `entity_id`-de loogikat (sama klient saab `wp_login` ja `woocommerce_checkout_order_processed` peale kaks erinevat customer.upsert-i).

### Event-dependency mehhanism

Mõned rec-engine eventid sõltuvad eelmiste eventide õnnestumisest. Konkreetne juhtum: `identity.merge` event vajab, et sama email-iga `customer.upsert` event oleks **eelnevalt** õnnestunud (rec-engine tagastab 404, kui customer ei eksisteeri — vt API_CONTRACT §7).

**Implementeerimine `smly_rec_event_queue`-i `depends_on_event_id` veerus** (vt §7 schema laiendus):

```sql
ALTER TABLE {$prefix}smly_rec_event_queue
  ADD COLUMN depends_on_event_id CHAR(36) NULL AFTER event_uuid,
  ADD INDEX idx_depends_on (depends_on_event_id);
```

**Flush-loogika**: `smly_rec_flush_event_queue` ei saada eventi, mille `depends_on_event_id` viidatud event on `status != 'sent'`. Kui sõltuvuse-event lõpuks õnnestub, dependent-event vabaneb järgmise flush'i puhul. Kui sõltuvuse-event lõplikult ebaõnnestub (max_attempts ammendub), dependent-event märgitakse `status='failed'` `last_error='dependency_failed: {parent_event_id}'`.

**Kasutusjuhud:**
- `identity.merge` → sõltub `customer.upsert` sama email-iga
- `order.created` → sõltub `customer.upsert` sama email-iga (kui klient on uus, sünki esmalt klient)
- `catalog.upsert` variant'idele → ei oma dependency'd (variandid saadetakse iseseisvalt)

**Plugin-side enqueue-loogika** (näide pseudo-koodina):

```php
$customer_event_id = $event_queue->enqueue('customer.upsert', $email, $customer_payload);
$merge_event_id = $event_queue->enqueue(
    'identity.merge',
    $email,
    $merge_payload,
    depends_on: $customer_event_id
);
```

---

## 12. Backfill lifecycle

1. **Trigger**: klient klõpsab "Start backfill" wizard'is või settings'is
2. **Init**: plugin loob `backfill_job` rea, status='running', `total_count` arvutatakse:
   - Kontaktid: `count(get_users(['role__in' => ...]))`
   - Tellimused: `wc_get_orders(['return' => 'ids', 'limit' => -1])` count (HPOS-aware)
   - Tooted: `wp_count_posts('product')`
   - Kliendid: `count(get_users(['role' => 'customer']))`
3. **Schedule**: Action Scheduler käivitab `smly_plus_backfill_runner` job'i
4. **Iteration**: iga käivitus võtab cursor'ist järgmised 100 entiteeti, vormindab, saadab vastavasse endpoint'isse. Õnnestumisel uuendab `cursor` ja `processed_count`. Salvestab uue Action Scheduler iteratsiooni 30s pärast (rate-limit'i vältimiseks).
5. **Complete**: kui cursor jõuab lõppu, status='completed', `completed_at` täidetakse
6. **Failure**: API-error → status='failed', error_message täidetakse. Klient näeb "Retry" nupu.
7. **UI**: WP REST endpoint `/wp-json/smaily-connect/v1/backfill/status?job_type=orders` tagastab JSON-i `{ status, processed, total, percent, eta_seconds }`. Wizard polling iga 5s, settings iga 30s.

**Idempotentsus**: re-run uuendab olemasolevaid recorde rec-engine'is (upsert by `sku` toodete jaoks, `email` klientide jaoks, `order_id` tellimuste jaoks). Sama entity ei loo duplikaate.

**SKU-puudujäägid tooted**: skip + admin notice "X products skipped, missing SKU. [View list]" — `Tools → Smaily Connect → SKU report` link kuvab täielikku nimekirja.

---

## 13. Event Log vaade

Eraldi tab Settings-vaates. Sisu:

- **Tabel**: viimaste 7 päeva eventid (paginated, 50 rida/page)
- **Veerud**: timestamp, event_type, entity_id, source (Smaily/rec_engine), status, attempts/max_attempts, last_error preview (truncated)
- **Filtreeri**: per event-type, per status (success/failed/pending/retrying), per source
- **Single-row drill-down** (klõps rea peal → modal): täielik payload JSON, full last_error, retry-history
- **"Retry now" nupp** manualseks katseks failed/retrying events
- **"Export failed events as CSV"** debug'iks
- **Sticky failure banner** ülal: "X failed events in last 24h" + "View only failed" link

**Andmeallikas**: SELECT `smly_plus_event_queue` UNION `smly_rec_event_queue`, ORDER BY created_at DESC. WP REST endpoint `/wp-json/smaily-connect/v1/events` paginated.

**Action Scheduler integratsioon**: lisaks plugin-eventidele kuvame AS-tabelist plugin-jobid (filter `hook LIKE 'smly_plus_%' OR hook LIKE 'smly_rec_%'`). Eraldi tab või toggle.

---

## 13a. Admin notifications + email notifications

Plugin teavitab kasutajat (WP admin) probleemidest **kolmel tasandil**:

1. **Event Log entry** — kõik märkimisväärsed sündmused logitakse, **alati**
2. **Admin notice** — WP admin-paneelis kuvatav teade (dismissible või sticky)
3. **Email** — saadetakse `wp_mail()`-iga admin email-le (`get_option('admin_email')` või custom)

Tasandid on **kumulatiivsed** — kõik admin notice'd on ka Event Log'is, kõik emailid on ka admin notice'd. Mitte iga sündmus aga jõuab kõigile tasanditele.

### Notification severity-levels

| Severity | Event Log | Admin Notice | Email |
|----------|-----------|--------------|-------|
| **`info`** | ✓ | ✗ | ✗ |
| **`warning`** | ✓ | ✓ (dismissible) | ✗ |
| **`error`** | ✓ | ✓ (sticky kuni resolvitud) | ✓ (vaikimisi opt-out võimalik) |
| **`critical`** | ✓ | ✓ (sticky) | ✓ (vaikimisi sees, opt-out olemas) |

### Konkreetsed notifications-juhtumid (v1.0 baas-set)

| Sündmus | Severity | Trigger |
|---------|----------|---------|
| Backfill batch failed (retry succeeded) | `info` | Action Scheduler retry-job |
| SKU-puudujäägi tooted skipped | `warning` | Backfill / catalog-sync, kui count > 0 |
| Engine version mismatch (minor) | `warning` | `X-Engine-Version` parse |
| Engine version mismatch (major) | `error` | `X-Engine-Version` parse — incompatibility |
| Backfill failed (max retry exhausted) | `error` | Action Scheduler final-failure |
| Rec-engine connection failed (5xx >1h) | `error` | Health-check cron-job |
| Smaily connection failed (5xx >1h) | `error` | Health-check cron-job |
| Failed events count >50 in 24h | `error` | Health-check cron-job |
| API-key revoked (401 + `api_key_revoked`) | `critical` | Iga API-vastus pärsib jälgima |
| Setup-token expired | `critical` | Setup-token exchange päring |
| Plugin upstream-merge available | `info` | Hilisemas faasis |

### Email throttling

Vältida spam'i:
- **Sama event-type + entity_id** ei saadeta uuesti **24h jooksul**
- **Critical**-events ei throttle'ta — saadetakse alati, kuna kasutaja peab kohe teadma
- Throttle-table: `wp_options`-i singleton (`smly_notification_throttle`), key = `event_type:entity_id`, value = `last_sent_at`

### Email mall ja keel

- **Mall**: bundled plugin'i `templates/email/*.php` failides. Plain-text + HTML versioon (mitme-osaline email)
- **Sisu**: severity-ikoon, sündmuse pealkiri, kontekst-info, "View in Event Log →" link
- **Keel**: kasutaja WP admin keel (`get_user_locale()`), fallback site_locale, fallback EN
- **From**: `wordpress@{site_domain}` (WP default) või custom (Settings'is)
- **Subject**: `[{site_name}] Smaily Connect: {event_title}` formaadis

### Settings UI

**Settings → Notifications** alapaneel (osa Connection-tab'ist või eraldi tab):

- **Toggle**: "Send email notifications for critical events" — vaikimisi sees
- **Toggle**: "Send email notifications for errors" — vaikimisi sees
- **Toggle**: "Send email notifications for warnings" — vaikimisi väljas
- **Input**: "Notification email address" — vaikimisi `get_option('admin_email')`, override võimalus
- **"Send test email" nupp** — kontrollida, et emailid jõuavad kohale
- **Info-blokk**: "Notifications are also visible in [Event Log →] regardless of email settings"

### Implementeerimine

Klass `NotificationManager` `includes/Notifications/` kaustas:

```php
class NotificationManager {
    public function notify(string $event_type, string $severity, array $context = []): void;
    private function logToEventLog(...): void;
    private function showAdminNotice(...): void;
    private function sendEmailIfAppropriate(...): void;
    private function isThrottled(string $event_type, string $entity_id): bool;
}
```

Kasutusnäide:
```php
$notifications->notify(
    'engine_connection_failed',
    'error',
    [
        'entity_id' => 'rec_engine',
        'message' => 'Rec-engine has been unreachable for over 1 hour',
        'request_id' => 'req_8f3k...',
        'failed_events_count' => 47,
    ]
);
```

**Admin notice rendering**: kasutab WP standardseid `add_action('admin_notices', ...)` hookeid. Notice'd persistent'itakse `wp_options`-i (`smly_active_notices`) — sticky'd jäävad kuvatuks kuni manuaalse dismissi või automaatse resolutsiooni (näit. järgmine edukas API-call peatab "connection failed" notice'i).

**Dismissi handling**: dismissible notices'el `data-notice-id` atribuut, JS `wp.ajax`-iga POST'ib dismissi WP REST endpoint'ile. Ei tule uuesti.

### Future expansion (v1.x backlog)

- **In-app notification center** (bell-ikoon admin-bar'is)
- **Slack/Discord webhook** integration (sama severity-loogikaga)
- **Per-event-type custom-template** support (klient saab ise muuta email-malli)
- **Email digest** (kogu kõik info-level events päevasse kokku, saata hommikul)

---

## 14. WordPress.org marketplace kvaliteedinõuded

Plugin peab läbima WordPress Plugin Check (PCP) tööriista roheliselt enne upstream-merge'i. Konkreetsed kohustused:

**Scripts & styles:**
- Kõik enqueue'd läbi `wp_enqueue_script` / `wp_enqueue_style` (mitte inline `<script>` tag'id)
- **Mitte ühtegi CDN-pärinevat resurssi** (fontid, JS-libid — kõik bundle'itud plugin-i sees)
- Geist/Inter fontid laetakse plugin-i `assets/fonts/` kaustast WP-poolt

**SQL & data:**
- Mitte ühtegi otsest `$wpdb->query("SELECT ... FROM wp_posts ...")` SQL-i
- HPOS: `wc_get_order()`, `wc_get_orders()`, mitte `wp_posts` queries
- `declare_compatibility('custom_order_tables', __FILE__, true)`
- Kõik user-input sanitize'itud (`sanitize_text_field`, `sanitize_email`, `wp_kses_post`)
- Kõik output escape'itud (`esc_html`, `esc_attr`, `esc_url`)

**Security:**
- Capability checks (`current_user_can('manage_options')`) enne admin-action'eid
- Nonce'id kõigil admin-vormidel ja AJAX-callidel (`wp_create_nonce`, `check_admin_referer`)
- API-key krüpteeritud `wp_options` salvestuses
- Beacon-key MITTE KUNAGI client-side koodis (server-side proxy)

**i18n:**
- Text domain `smaily-connect` deklareeritud plugin-header'is
- `load_plugin_textdomain()` kutsutud `plugins_loaded` hook'is
- Kogu UI-tekst kasutab `__()`, `_e()`, `_n()` funktsioone
- React-bundle'is `wp.i18n.__()` kasutus, `wp-i18n` dependency'na
- `.pot` fail genereeritud `wp i18n make-pot` käsuga build'is
- Tõlked: ET + EN minimaalselt v1-s

**Plugin lifecycle:**
- `register_activation_hook`: DB-migrations, default options
- `register_deactivation_hook`: cron'id maha (Action Scheduler jätab queued jobid alles)
- `uninstall.php`: Settings'is "Remove all plugin data on uninstall" toggle (vaikimisi väljas)
  - Toggle sees: kustutab kõik plugin DB-tabelid, options, user-meta, AS-jobid
  - Toggle väljas: säilitab andmed

**Errors:**
- `WP_DEBUG=true` keskkonnas mitte ühtegi PHP warning/notice'it
- Logimised `error_log` kaudu, mitte `var_dump` / `print_r`

**Multi-site:**
- Per-site activation, network-wide pole MVP-s
- `is_multisite()` check, kui vajalik

---

## 15. Pet-pilootkliendi acceptance criteria

End-to-end test, mis peab töötama enne klienti live:

1. Plugin aktiveeritud puhtas WP + WooCommerce paigaldus. Detect: keeled (ET + EN), Elementor olemas, CF7 olemas. HPOS aktiveeritud.
2. Wizard Step 1 — sisestan Smaily credentialid → test connection ✓. Mitmekeelsuse küsimus avaneb → valin **Mode B**. Sisestan rec-engine setup-token URL → test connection ✓ → kuvab "Connected to tenant: [Pet Shop Name]".
3. Step 2 — välja valikud aktsepteeritud. Käivitan backfill — 2000-5000 kasutajat sünkroniseeruvad Smaily-sse 5-25 min jooksul, progress live.
4. Step 3 — Welcome / First order / Abandoned cart sektsioonid kuvavad Mode B tabeli (ET + EN read + Default-fallback radio). Valin igale ridale workflow. Salvestan.
5. Step 4 — kõik 4 esimest linnukest sees, browsing väljas. Käivitan kõik 3 backfilli (orders, customers, products) — kõik jõuavad rec-engine'i 10-30 min jooksul, progress live.
6. Step 5 — info-kaardid kuvatakse, lingid töötavad in-window (mitte target=_blank).
7. Step 6 — kokkuvõte kuvab kõik aktiveeritud featuurid.
8. **Live test 1 — Welcome**: loon uue WP-kasutaja → ET kontekstis → Smaily-s käivitub ET welcome workflow. Repeat EN-iga.
9. **Live test 2 — First order**: olemasoleva kasutaja esimene tellimus → first_order workflow käivitub. Teine tellimus sama kasutaja → ei käivitu.
10. **Live test 3 — Abandoned cart**: lisan tooted korvi, lahkun, 30 min hiljem → cart.item_added eventid jõuavad rec-engine'isse + abandoned_cart workflow käivitub Smaily-s.
11. **Live test 4 — Browsing (eraldi aktiveerin)**: vaatan 5 toodet anonüümselt → `browse.product_view` eventid jõuavad rec-engine'isse `visitor_id` all 30s aknas. Logan sisse → `identity.merge` event saadetakse → rec-engine ühendab ajaloo emailiga.
12. **Live test 5 — Email-link merge (cross-device)**: saadan rec-engine'ist kampaania `smaily_vt`+`smaily_rec`+`smaily_ctx`-tokenitega link'idega. Avan e-maili teises brauseris (uus küpsis), klikin linki → `template_redirect` detekteerib parameetrid, decode'ib emaili, seab kõik 4 cookie'd, saadab `identity.merge` source='email_link'. URL puhastatakse. Edasine browsing seal brauseris seotakse automaatselt sama email'iga.
13. **Live test 6 — Product update**: muudan tootes hinna → `catalog.upsert` event jõuab rec-engine'isse 60s jooksul.
14. **Live test 7 — Mode change**: vahetan B→A, lisan ET-le eraldi Smaily account → vana credential läheb "Default account" alla → ET workflowd kasutavad uut accountit, fallback default.
15. **Live test 8 — GDPR**: WP-admin Tools → Erase Personal Data → email → confirm → `DELETE /api/v1/customer/{email}` kutsutakse → rec-engine kustutab andmed.
16. **Live test 9 — Engine version mismatch**: simuleeri `X-Engine-Version: 2.0.0` → admin notice kuvatakse, plugin jätkab töötama.
17. **Failure test**: blokeeri rec-engine endpoint (firewall) → eventid lähevad failed status'esse → 5 retry × backoff → settings'is admin notice "Rec-engine connection failed, X events queued". Endpoint tagasi → flush jätkub.
18. **WP-CLI test**: `wp plugin check smaily-connect` → 0 warnings.
19. **Performance test**: 5000 kasutaja backfill jookseb läbi alla 30 min PHP-FPM workers'i ülekoormamata.

---

## 16. Backlog (v1.x / v2)

- CF7 / Elementori vormide eventid rec-engine'isse
- A/B testimine rec-block performance'i mõõtmiseks
- Per-product opt-out browsingust (sensitiivsed kategooriad)
- Smaily-poolne client-side embed (rec-block iframe poesse)
- Migration tooling teistest ESP-dest
- Redis-queue + dedicated workerid suurte mahu jaoks
- Sub-account / multi-store support ühe plugina alt
- Webhook back-channel: rec-engine → plugin (sync.completed/failed, recs.updated, tenant.alert)
- WP Network (multisite) network-wide aktivatsioon
- Shopify / Magento / PrestaShop natiivsete pluginate andmebaas — kuna API on multi-platform agnostic, tulevad need eraldi plugin'idena, sama API contract'iga

---

## 17. Lahtised küsimused (v0.5 lukus, vajavad enne v1.0)

1. **Geist vs Inter font** — STYLE_MAPPING.md eeldab Interit (tõenäolisim Smaily valik). Kui Smaily kasutab muud fonti, vaja vahetust enne Faas 2 algust. Variant 3 (mu hinnangud) põhjal otsustatud, vajab pilootkliendi review-faasis kinnitust.
2. **Smaily disainisüsteemi täpsed hex'id** — STYLE_MAPPING.md kasutab hinnangulisi väärtusi (Variant 3 valik logo `#E91E63` + UI-screenshot'i põhjal). Vaja kontrolli Faas 2 lõpus pilootkliendi review-faasis.
3. **Production engine_base_url** — praegu Vercel preview (`re-seven-indol.vercel.app`). Production migratsioon enne või pärast pilootkliendi go-live? Code peab teadma, et URL on **muutuv** ja kõik viited tulevad setup-response'ist.
4. **Email notification opt-in vaikimisi** — kas critical-level notifications (API revoke, engine connection-down >1h) saadetakse vaikimisi admin-email-le, või klient peab opt-in'ima? Mu kalle: vaikimisi sees critical-level'ile (kasutaja peab teadma kui plugin lakkab töötamast), opt-out võimalus Settings'is. Vt §13a.

## 18. Sünk RECENGINE_API_CONTRACT v1.0-ga (v0.4/v0.5 muudatuste log)

v0.3 → v0.4 muudatused:

- §0 Päis: plugin versioon `2.0.0-beta.X` → `2.0.0` upstream-merge'il (Erkki kinnitanud)
- §8 Rec-engine: setup-flow täpsustatud API_CONTRACT v1.0 järgi, sh `plugin_info.name` = `smaily-connect`, User-Agent string format
- §8 Engine_base_url: rõhutatud, et URL on muutuv (Vercel preview praegu, prod migratsioon hiljem) — kõik URL-id setup-response'ist, mitte hardcode
- §9 Event-tüübid: lisatud `event_id` UUID ja `session_id` nõue iga event'iga; order-eventidele attribution-payload 4 cookie-väärtust; identity-merge dependency selgesõnaliselt
- §7 `smly_rec_event_queue` schema: lisatud `depends_on_event_id` veerg ja `blocked` status
- §11 Background-jobid: lisatud event-dependency mehhanism alapunktina (`identity.merge` sõltub `customer.upsert`-ist)
- §17 Lahtised küsimused: eemaldatud "API_CONTRACT puudub" (lahendatud), lisatud production URL küsimus

v0.5 → v0.6 muudatused (mootori-poolse `PLUGIN_IMPLEMENTATION_WP.md` v1.0 ülevaatusest):

- §8 Setup-URL: lisatud override-mehhanism `apply_filters('smaily_connect_setup_url', ...)` esimese setup-call'i jaoks (production-migratsiooni paindlikkus)
- §9 Order-attribution: order-meta cookie-salvestamine kohe `woocommerce_checkout_order_processed` hookis (mitte küpsisest hilisemates kontekstides — küpsised pole sealsetes hookides saadaval)
- §11 Deduplication: lisatud `as_next_scheduled_action()` check Action Scheduler'is (vältab duplikate `save_post_product` autosave + manual save ja sarnastel juhtudel)

**Lisaviide**: mootori-poolne `PLUGIN_IMPLEMENTATION_WP.md` v1.0 (eraldi fail) sisaldab konkreetseid WP/WC koodinäiteid (WPML/Polylang API-kasutus, HPOS order reader, beacon JS, GDPR exporter/eraser registreerimine, EngineClient retry-loogika). See on **viitedokument koodinäidetega**, mille mu PROJECT_PLAN.md viitab. Kui mootori-side dokumendi koodinäited on vastuolus meie PLUGIN.md spec'iga (näit. plugin-nimi `smaily-rec-engine`, paigutus `src/`-kausta), **meie spec võidab** — fork-strategy tähendab `smaily-connect` nime ja `includes/` paigutust. Mootori-side dokument on koodinäidete tasandil õpetlik, aga arhitektuurselt mitte autoritatiivne.
