# Rec-engine — mootori-poole nõuded ja soovitused

Koostatud Smaily Connect WP plugin'i Faas 3 ettevalmistuse põhjal. Plugin (ja tulevased
Shopify-app + muud kanalid) eeldavad, et mootor täidab need nõuded. Jaotatud prioriteedi
järgi: **P0 = blokeerib pilootkliendi live'i**, **P1 = vajalik enne laiemat levikut**,
**P2 = hea tava / tulevik**.

Autoritatiivne API-leping: `RECENGINE_API_CONTRACT.md`. See dokument on **mootori-poole
to-do**, mitte lepingu asendus.

---

## Seis (uuendatud Faas 3 sub-PR 3.1.2 + 3.2 ettevalmistuse järel)

**P0 — kõik valmis ✅** Pilootkliendi live pole enam mootori-blokeeritud.
- P0 #1 ✅ Production avalik (Vercel No Protection)
- P0 #2 ✅ Api-key-auth jõustatud igal endpointil (verifitseeritud `401` ilma key'ta)
- P0 #3 ✅ Setup-exchange töötab + one-time jõustatud (plugin 3.1.2 live-test tõestas: HTTP 200 päris-mootor, tenant + api_key + round-trip; teine kõne sama token'iga keeldub)
- P0 #4 ✅ Stabiilne vastuse-formaat (`X-Engine-Version: 1.0.0` header kohal, JSON-struktuur lepingus)

**P1 — pooleli / tegemata** (vajalik enne teist klienti)
- P1 #5 api-key revoke — staatus tundmatu
- P1 #6 rate-limiting — mootor ütles "100 req/sec sisenev" (3.2 raport), jõustamise-staatus tundmatu
- P1 #7 multi-tenant isolatsioon — eeldatakse implementatsioonist, vaja kinnitust
- P1 #8 GDPR — Faas 3 sub-PR 3.8 vajab

**P2 — osaliselt tehtud**
- P2 #9 ✅ Idempotentsus IMPLEMENTEERITUD (mootori commit 985c488, migration 0025 `ingest_event_log`,
  unique `(tenant_id, event_id)` + 90-päeva retention, 22/22 dedup-test PASS, `{"deduplicated": true}` response)
- P2 #10 versioonimine — `X-Engine-Version` olemas (vt P0 #4), URL-versioon `/v1/` kasutusel
- P2 #11 🟡 ping-endpoint ROUTE olemas (`GET /api/v1/ingest/ping`, 401 ilma key'ta verifitseeritud),
  AGA setup-response endpoints-map'is ei sisaldu — audit pooleli mootori-poolel (1-2 päeva)
- P2 #12 batch-tugi — mootor talub kuni 25000 objekti/päring, plugin jääb spec-konservatiivseks (100)

**Uus / mitte-kategoriseeritud** (avastatud Faas 3 sub-PR 3.2 plaani auditil)
- 🆕 `event_id` asukoht catalog-body's pole spec-docis dokumenteeritud — mootor lisas commit 985c488-s,
  aga **per-product vs top-level on lahtine**. Plugin teeb live-probe enne koodimist. **Mootori-tiim:
  palun dokumenteeri ametlikult RECENGINE_API_CONTRACT.md-s §3-5 (catalog/customers/orders body-struktuur).**

---

## P0 — blokeerib pilootkliendi live'i

### 1. Production avalikult jõataav, ilma Vercel SSO-ta ✅ TEHTUD
Vercel Deployment Protection (SSO wall) peab olema **production'ilt eemaldatud**
("No Protection"). Põhjus: plugin on masin-klient, mitte inimene — ta ei saa Vercel-SSO-login'i
läbida. Pilootkliendi WP-server peab mootorile pääsema internetist.

- Vercel → Project Settings → Deployment Protection → Production = No Protection
- Turvalisus liigub SSO-lt **api-key-auth'ile** (vt punkt 2). Mootor ei jää kaitseta — kaitse
  on lihtsalt õiges kihis (masin-auth, mitte inimese-login).
- Dev/preview-keskkonnad **võivad** SSO taha jääda; ainult production peab avalik olema.

### 2. Iga endpoint jõustab api-key auth'i ✅ TEHTUD
Avalik mootor ilma auth'ita = kõik andmed lahti. Iga endpoint (välja arvatud setup-exchange,
vt punkt 3) peab nõudma kehtivat api-key'd.

- Päring ilma kehtiva api-key'ta → **HTTP 401**
- Api-key edastatakse päringus (Bearer header või lepingus määratud viis)
- Kehtib KÕIGILE andme-endpointidele: events, recommendations, identity-merge, GDPR, backfill-ingest
- Ainus erand: `POST /setup/exchange` (kasutab setup-token'it, mitte api-key'd)

### 3. Setup-token exchange (`POST /api/setup/exchange`) ✅ TEHTUD
Plugin saab merchant'ilt one-time setup-token'i, vahetab selle püsiva api-key vastu.
**Verifitseeritud plugin 3.1.2 live-testis** (HTTP 200, tenant_id + api_key + endpoints-map).

- ✅ **Setup-token on tõesti one-time** — pärast edukat exchange'i ei tohi sama token'iga
  teist api-key'd saada. Plugin live-test tõestas (token "burned" pärast esimest kasutust).
- ✅ Exchange tagastab api-key, mille plugin salvestab krüpteeritult oma poolel
- ✅ Path: `/api/setup/exchange` (mitte `/setup/exchange` — `/api` prefiks kohustuslik)
- Vajalik vastuse-formaat ja staatuskoodid peavad vastama lepingule, et plugin-Client neid parse'iks

### 4. Stabiilne, dokumenteeritud vastuse-formaat ✅ TEHTUD
Plugin parse'ib vastuseid. Iga endpoint peab tagastama **järjepideva** struktuuri.

- ✅ JSON, lepingus määratud kujul
- ✅ `X-Engine-Version: 1.0.0` header kohal (verifitseeritud)
- ✅ Vea-vastused struktureeritud (kood + sõnum), mitte HTML-leht ega tühi body
- ✅ 4xx vs 5xx eristus tähenduslik (4xx = kliendi-viga, ära retry; 5xx = mootori-viga, võib retry)

---

## P1 — vajalik enne laiemat levikut

### 5. Api-key revoke / rotatsioon
Kui pilootkliendi api-key lekib (näit. plugin-andmebaas kompromiteeritud), peab olema viis
see **tühistada** ilma teisi kliente mõjutamata.

- Endpoint või admin-võimalus api-key revoke'imiseks
- Revoke'itud key → 401, plugin peab suutma uue setup-token'iga uue key saada
- Soovituslik: api-key seotud konkreetse merchant/shop-id-ga (multi-tenant isolatsioon)

### 6. Rate-limiting
Avalik endpoint = DDoS / kuritarvituse risk. Iga api-key (või IP) peaks olema rate-limited.

- Mõistlik piir per api-key (event-ingest võib olla kõrgem, recommendations madalam)
- Üle piiri → **HTTP 429** + `Retry-After` header
- Plugin peaks 429 graatsiliselt käsitlema (event-queue retry hiljem) — aga mootor peab limiidi **jõustama**

### 7. Multi-tenant isolatsioon
Iga merchant'i andmed (events, visitors, recommendations) peavad olema **rangelt eraldatud**.

- Merchant A api-key ei tohi KUNAGI näha merchant B andmeid
- Api-key → shop-id mapping jõustatud igal päringul
- Kriitiline GDPR + ärisaladuse jaoks (üks merchant ei näe teise kliendibaasi)

### 8. GDPR-endpointide tugi
Plugin pakub WP Privacy API integratsiooni (Faas 3 sub-PR 3.8). Mootor peab toetama:

- Kasutaja-andmete **eksport** (mida mootor selle kontakti kohta hoiab)
- Kasutaja-andmete **kustutus** (right to be forgotten)
- Endpointid + formaat lepingus; mootor peab tegelikult andmed kustutama, mitte ainult märkima

---

## P2 — hea tava / tulevik

### 9. Idempotentsus event-ingest'is ✅ IMPLEMENTEERITUD
Plugin event-queue võib retry'da (võrgu-katkestus, 5xx). Mootor **välistab duplikaate**.

**Implementatsioon** (mootori commit 985c488, migration 0025):
- ✅ Event kannab unikaalset id-d (`event_id` wire-väljanimi, plugin saadab kõigil ingest-endpointidel)
- ✅ Unique `(tenant_id, event_id)` `ingest_event_log` tabelis, 90-päeva permanent retention
- ✅ Sama `event_id` kaks korda → `200 {"deduplicated": true}` (no-op)
- ✅ Backward-compat: kui plugin `event_id` ei saada → natural-key UPSERT (sku / email / external_order_id).
  `event_id` on **defensive layer** natural-key UPSERT'i peal, mitte asendus
- ✅ Plugin käsitleb `{"deduplicated": true}` kui edukat töötlust — queue-rida `completed`, mitte retry
- 22/22 dedup-test PASS (mootori-pool)

**Lahtine** (vt seis-kokkuvõte ülal): `event_id` **asukoht body's** spec-doc'is dokumenteerimata
(per-product vs top-level). Plugin teeb live-probe enne 3.2 koodimist; pärast probe-tulemust
mootori-tiim peaks **dokumenteerima** RECENGINE_API_CONTRACT.md §3-5-s.

### 10. Versioonimine
Api muutub ajas (Shopify, muud kanalid lisanduvad). Versioon-strateegia hoiab vanad kliendid töös.

- `X-Engine-Version` (juba P0 punkt 4) + kaalu URL-versioonimist (`/v1/...`) või header-versioonimist
- Breaking-change → uus versioon, vana säilib mõnda aega (plugin-update pole hetkeline kõigil merchant'idel)

### 11. Observability / health-endpoint 🟡 OSALISELT
Plugin (+ sina) peab teadma, kas mootor on töökorras.

**Implementatsioon** (mootori commit 668d463):
- ✅ Route OLEMAS: `GET /api/v1/ingest/ping`, verifitseeritud `401` ilma api-key'ta
- 🟡 **Endpoints-map'is ei sisaldu** setup-response'is — audit pooleli mootori-poolel (1-2 päeva).
  Pärast kinnitust plugin RecEngineConnectivityTest lülitub sisse automaatselt.
- Logimine mootori-poolel: kes/millal/mis endpoint (debug + abuse-detect) — staatus tundmatu

### 12. Backfill-maht ja batch-tugi
Plugin teeb rec-engine backfilli (orders/customers/products, batch 100 — Faas 3 sub-PR 3.5).
Suur merchant = palju andmeid.

- Mootor peab taluma batch-ingest'i (sadu/tuhandeid event'e järjest)
- Kaalu bulk-endpoint (üks päring, N event'i) vs üksik-päringud (jõudlus suure backfill'i puhul)
- Rate-limit (punkt 6) ei tohi backfilli liiga aeglaseks teha — kaalu kõrgem limiit ingest'ile

---

## Märkus turvalisuse kohta üldiselt

P0 #1-4 on **tehtud õiges järjekorras** — api-key-auth jõustati enne kui Vercel SSO eemaldati,
niisiis pole olnud "avalik + lahti" akent. ✓

Edaspidi: P1 (revoke, rate-limit, multi-tenant isolatsioon) peavad olema valmis **enne teist klienti**.
Pilootklient võib live'i minna P1-ta (üks merchant, kontrollitud keskkond), aga laiem levik vajab P1.

---

## Seos plugin-arendusega

Plugin Faas 3 sub-PR-id eeldavad neid:
- **3.1** (Client + setup-exchange) → P0 #3, #4
- **3.4** (events ingest) → P0 #2, P2 #9
- **3.5** (backfill) → P2 #12
- **3.7** (identity-merge) → P0 #2, P1 #7
- **3.8** (GDPR) → P1 #8

P0 kõik peavad olema valmis enne pilootkliendi live'i. P1 enne teist klienti. P2 jooksvalt.
