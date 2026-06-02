# LESSONS.md — õppetunnid AI-agendiga ehitamisel

Koostatud Smaily Connect WordPress plugin'i Faas 2 lõpu põhjal, kus integration-bugide
parandamine võttis ~19 iteratsiooni. Suur osa sellest oli välditav. See dokument on
mõeldud **järgmise projekti algusesse** kaasa võtmiseks ja **Code-agendile** üleandmiseks,
et samad asjad ei korduks.

---

## TL;DR — kolm asja päevast üks

1. **Build-marker** — commit-hash nähtav runtime's (console / footer / `/version`). Tapab "kas bug või cache?" määramatuse.
2. **Agent näeb päris-keskkonda** — Docker / wp-env / brauser / päris-API agendi käes **enne** kood-tööd, mitte hiljem.
3. **Integration-test piiridel** — iga "valmis" tükk vajab üht otsast-lõpuni flow-testi päris-keskkonnas, mitte ainult unit-coverage'it.

Pluss kaks mitte-tehnilist:
- **Domeeni-walkthrough püüab spec-vead**, mida ükski test ei püüa.
- **Live-probe enne koodimist**, kui leping-detail (väljanimi, payload-asukoht, response-formaat) pole dokumenteeritud — 5-min curl kahe-süsteemi-vahelise eelduse vastu hoiab ära päeva-iteratsiooni hiljem.

---

## 1. Juurpõhjus: integration-kiht oli süstemaatiliselt testimata

Kogu Faas 2 lõpu valu taandub ühele asjale. Backend-loogika oli tugev (95% unit-coverage,
140 PHPUnit + 96 Vitest testi, kõik rohelised). Aga **komponentide vahelised piirid** olid
mockitud, mitte päriselt testitud.

Iga bug elas **piiril**, mitte komponendi sees:

| Bug | Piir |
|-----|------|
| `restRoot` vs `restUrl` field-mismatch | PHP ↔ TypeScript |
| Wizard ei kutsunud salvestust | Wizard ↔ backend |
| Legacy hook crash REST-salvestusel | Uus kood ↔ legacy kood |
| Backfill DB-tabel puudus | Plugin ↔ WP-migration (dbDelta) |
| Workflows tagastas mock-andmeid | Plugin ↔ Smaily-API |
| Salvestab võtmega X, loeb võtmega Y | Write ↔ read |

**Üldine reegel: mockid peidavad piire. Integration-bugid elavad piiridel.**
Mida rohkem komponente süsteemis (siin: React + REST + WP + WC + Polylang + legacy + Smaily-API),
seda rohkem piire, seda olulisem päris-integration-test.

---

## 2. Suurimad ajaraiskajad, järjestatud

### 2.1 Cache-määramatus (kõige kallim)

**Mis juhtus:** korduvalt "ei salvesta" → reinstall → vahel töötab. Me ei teadnud,
kas staging jookseb üldse õiget koodi. Iga "ei tööta" oli kahemõtteline: bug või vana kood?

**Lahendus (leidsime liiga hilja):** `buildHash` boot-payloadis. ~5 rida koodi.
Console-check `window.appBoot.buildHash === "abc1234"` kinnitab sekundiga, kas õige kood jookseb.

**Reegel järgmiseks:** iga deploy'tav projekt vajab build-marker'it **päevast üks**.
Commit-hash (või `-dirty` flag kui working tree muudetud) nähtav runtime's. Ilma selleta
on kogu staging-debugimine pime.

### 2.2 Agent ei näinud päris-keskkonda (teine kallim)

**Mis juhtus:** agent parandas CSS-i ja integration-loogikat **pimesi** — arvas fix'i,
inimene testis staging'is, katki, kordasime. Align-bug võttis 5 katset. Backfill-bug
(puuduv DB-tabel) ei oleks **kunagi** staging-tsükliga lahenenud, sest seda ei näe ilma
DB-ligipääsuta.

**Lahendus (leidsime liiga hilja):** Docker + wp-env + chromium agendi keskkonnas.
Pärast seda nägi agent päris-WP-d ise, luges debug.log-i, reprodutseeris bugid.
Backfill-bug lahenes esimese wp-env-käivitusega (debug.log näitas SQL-viga kohe).

**Reegel järgmiseks:** kui agent ehitab millegi jaoks, mis jookseb keskkonnas X, peab
agendil olema **ligipääs keskkonnale X päevast üks**. WP plugin → wp-env. React app → brauser.
API → päris-HTTP-test. Pimesi-parandamine on aeglane ja ebatäpne.

### 2.3 Mock-testid lõid vale turvatunde (juurpõhjus enamiku bugide taga)

**Mis juhtus:** kõik unit-testid rohelised, aga iga integration-bug läks läbi. Mock testib
funktsiooni **üksinda**, mitte flow'd **otsast-lõpuni**. "Roheline test" ≠ "töötav feature".

**Lahendus:** agent hakkas **brauseris (chromium) täis-flow'd jooksutama** enne iga ZIP-i —
sama, mida inimene käsitsi teeks (samm 1 → 6, sulge, ava uuesti, kontrolli säilivust).
Pärast seda: esimene puhas läbimäng.

**Reegel järgmiseks:** roheline unit-test ≠ töötav feature. Iga "valmis" sub-PR vajab
**üht otsast-lõpuni flow-testi päris-keskkonnas**, mitte ainult unit-coverage'it.
Unit loogikale (kiire, väärtuslik), integration piiridele (mida algul puudus).

### 2.4 Mock peegeldab su eeldusi, mitte tegelikkust

**Mis juhtus** (Faas 3 sub-PR 3.1.2): plugin kutsus rec-engine'i path'iga `/setup/exchange`,
mock-server vastas `/setup/exchange`-le rõõmsalt. Mõlemad testid rohelised. Päris-mootor
nõudis aga `/api/setup/exchange` — niisiis plugin × mootor ühilduvus oli **katki**, kuigi mock
ütles "kõik OK". Mock oli **ehitatud sama eelduse järgi** kui plugin (vale path), niisiis
mock kinnitas eeldust, mitte tegelikkust.

**Sama muster kordus 3.2-s** — mootor lisas `event_id` aktseptimise catalog-body's
(commit 985c488), aga **asukoht body's pole dokumenteeritud** (per-product vs top-level).
Mock saaks ehitada kummagi variandi peale ja olla roheline, päris-mootor aktsepteerib ainult ühte.

**Lahendus:** kui leping-detail on **dokumenteerimata** või **hiljuti lisatud**, tee **live-probe**
enne koodimist — 2 curl'i päris-otspunkti vastu, vaata kumb 200 / kumb 4xx. Lukusta päris-mootori
vastusega, mitte mock-eeldusega. Seejärel ehita mock **vastama** päris-käitumisele.

**Reegel järgmiseks:** mock testib plugin-loogikat **mock-eelduste** vastu. Päris-ühilduvust
testib AINULT live-päring tegeliku süsteemi vastu. Niisiis **mõlemad on vajalikud**, kuid
**live-päring on kohustuslik** enne ZIP-i / merge'i, mitte "kui jõuame". Path-bug oleks pidanud
viie minuti curl'iga lahenema 5 päeva enne, kui live-test selle tabas.

### 2.5 Konteksti-audit uue sessiooni alguses

**Mis juhtus** (Faas 3 sub-PR 3.2): agent võttis sessiooni-konteksti-kokkuvõtte, alustas plaaniga,
**aga kontrollis kõigepealt päris-koodi vastu**. Leidis 3 lahknevust: (a) plaani kasutatud nimed
juba hõivatud teises moodulis, (b) DB-skeem juba olemas (migration commit'itud), (c) aegunud
arhitektuuri-doc kirjeldab vana lähenemist enne hiljutist disain-otsust.

**See on õige käitumine.** Pikade vahede järel (sessioon-vahetus, taasstart, mälu-värskendus)
on **konteksti-kokkuvõte risk** — see on inimese arusaam, mitte koodi tegelik seis. Pime-koodimine
selle põhjal viib drift'i, mille avastab alles staging-test (= aeglane + kallis tagasiside-tsükkel).

**Reegel järgmiseks:** uue sessiooni alguses, **enne kui ükski rida koodi kirjutatakse**,
agent peab:
1. `git log` — kus oleme tegelikult (commit-hash + sõnumid)
2. Vaatama olemasolevaid komponente konteksti-plaani vastu (kas nimed vabad? kas tabel olemas?
   kas doc värske?)
3. Raporteerima leitud lahknevused **enne** plaani lukustamist

Aus konteksti-koodi audit säästab tunde hilisemat ümberkirjutamist.

---

## 3. Mitte-tehniline õppetund: spec-vead vs bugid

Mitu suurimat parandust **polnud bugid** — need olid **spec-vead** (ebaselgus või väljajätt
spetsifikatsioonis). Agent implementeeris täpselt seda, mis spec'is kirjas; viga oli spec'is.

Näited:
- Mode A "default account" loogika — äriliselt jaburus, mida ainult domeeni-ekspert nägi
- Wizard-first arhitektuur — UX-loogika otsus
- Field-naming-standard — cross-platform järjepidevuse vajadus
- "Continue peaks salvestama" — UX-ootus

**Reegel järgmiseks:** test kontrollib "kas kood teeb, mida spec ütleb". Ainult **domeeni-ekspert**
kontrollib "kas spec ise on mõttekas". Need on eraldiseisvad kontrollid — mõlemad vajalikud.
**Staging-walkthrough domeeni-eksperdiga püüab spec-vead, mida tehniline test ei püüa.**

---

## 4. Konkreetne checklist uue projekti algusesse

Päevast üks, enne feature-tööd:

- [ ] **Build-marker** — commit-hash runtime's nähtav (console / footer / `/version` endpoint)
- [ ] **Agendi ligipääs päris-keskkonnale** — Docker / wp-env / brauser / päris-API agendi käes
- [ ] **Integration-test-baas enne feature'eid** — "kas app käivitub, kas endpointid vastavad,
      kas DB-skeem luuakse, kas salvestus round-trip'ib" päris-keskkonnas (mitte mock)
- [ ] **Read/write sümmeetria reegel** — kui salvestad võtmega X, test loeb sama võtmega X tagasi
- [ ] **Endpoint-registreerimine ühest kohast** (array-loop / EndpointRegistry deklaratiivne list),
      mitte igaüks käsitsi — vältab copy-paste-lünga 404-e
- [ ] **Plugin/app täielik kustutus + reinstall** test-protokollis (mitte peale-upload) — vältab jäänuk/cache-segadust
- [ ] **Path-konstandid ühest kohast** — kõik välise süsteemi URL-id (`Client::PATH_*` vol sarnane),
      mitte inline-stringid eri failides. Vältab "mõni `/api`-ga, mõni ilma" lahknevust.
- [ ] **Tundlikud credentialid env-muutujates** — setup-token / api-key / parool **ei tohi sattuda**
      vestlusesse, raportisse, commit-message'i ega koodi. Viita "env-muutuja", ära kunagi pane väärtust.

Iga sub-PR / feature kohta:

- [ ] **Üks otsast-lõpuni flow-test päris-keskkonnas** (mitte ainult unit-coverage)
- [ ] **Agent näitab raportis "jooksutasin flow läbi, kinnitan"**, mitte ainult "unit-testid rohelised"
- [ ] **Live-probe enne koodimist**, kui leping-detail (väljanimi, payload-asukoht, response-formaat)
      dokumenteerimata või hiljuti lisatud — 5-min curl enne 5-päeva-iteratsiooni

Iga uue sessiooni alguses (pärast vahet):

- [ ] **Konteksti-koodi audit** — `git log`, kontrolli komponentide nimed/olemasolu vastu konteksti-plaani,
      raporteeri lahknevused **enne** plaani lukustamist
- [ ] **Keskkonna-kontroll** — Docker / wp-env / chromium / deps töötavad? Kui mitte, paranda enne feature-tööd

---

## 5. Mis töötas hästi (säilita)

Mitte kõik polnud valesti — need asjad olid head ja tasub korrata:

- **Sub-PR-haaval ehitus + review iga sammu järel** — hoidis tempot ja kvaliteeti
- **Tugev unit-test-distsipliin loogikale** — kiire areng, puhas backend. Probleem polnud
  "liiga vähe teste", vaid "vale tüüpi testid integration-kihis"
- **Aus piirangute tunnistus** — agent ütles, mida ta EI saanud testida (näit "päris-API vajab
  inimese credentiale"), mitte ei teeselnud
- **Turvateadlikkus** — agent tuvastas prompt-injection'i tööriista-väljundis + küsis luba enne
  piiri ületamist (binary-download)
- **Shared single-source komponendid** — `SubscriberPayloadBuilder`, `buildTabPayload`,
  `Client::PATH_*` konstandid jagatud ühe tõe-allikana, mitte duplikaadid (vältab drift'i)
- **EndpointRegistry deklaratiivne route-list** — üks list, kaks tarbijat (Bootstrap + test).
  Route-404 muutus struktuurselt võimatuks, mitte ainult "testitud". Parim
  arhitektuuriline samm Faas 3-s.
- **Mootori = path'ide tõe-allikas** (Faas 3 3.1.2 lihv) — plugin eelistab mootori-tagastatud
  endpoints-map'i hardcoded-konstantide ees. Mootori path-migrate ei vaja plugin-uuendust.
  Konstandid fallback. Õige sõltuvuse-suund kahe-süsteemi-disainis.
- **Koordineeritud disain-otsused mõlemalt poolt enne koodimist** — Faas 3 sub-PR 3.2
  idempotentsus-mudel (variant A) kinnitati neljal punktil mootori-tiimi poolt **enne**
  plugin-koodimist. Mootor implementeeris ette. Niisiis live-test pidi töötama esimesel
  kõnel, mitte avastama lahknevust. Vastand path-bug'i mustrile (eeldus → implementeeri →
  avasta erinevus). Lukusta leping enne koodi.

---

## 6. Tasakaalustav mõte

Mitte kõik iteratsioonid polnud välditavad. Faas 2 lõpp oli **esimene päris-keskkonna kokkupuude**,
mis paljastab alati eeldusi. Ja agendi kiirus (sub-PR päevas, puhas backend) tuli **just** sellest
unit-test-distsipliinist.

Kompromiss pole "rohkem teste vs vähem", vaid **õiget tüüpi testid õiges kohas**:
unit loogikale (kiire), integration piiridele (mida algul puudus).

Ülaltoodud checklist oleks Faas 2 lõpu ~19 iteratsioonist teinud hinnanguliselt ~5.
