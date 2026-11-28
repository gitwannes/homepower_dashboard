<!-- --- file: docs/operator-b1-marstek.md -->
# B1 — Operatorchecklist Marstek Venus E 3.0

Duidelijke checklist vóór en tijdens **B1** (meting / grafieken, geen eigen sturing).  
Doel: batterij in **Auto**, vaste bereikbaarheid via Ethernet, klaar voor 1-seconde logging vanuit Synology.

**Geldt voor:** elke installatie met HomeWizard P1 + Marstek (instellingen per site, geen vaste IPs in de code). 

---

## 1. Elektrisch

- [ ] Eigen kring / eigen zekering **16 A** (of 20 A) op **L1** — zo ontgrendel je **2,5 kW**
- [ ] Batterij aangesloten op die kring (geen gedeeld stopcontact / geen 800 W-limiet)
- [ ] Plaatsing **binnen**, bij voorkeur **boven ~15 °C** (LFP laadt slechter bij kou)
- [ ] Twee personen voor plaatsen (toestel ~63–65 kg, geen wieltjes)

---

## 2. Netwerk (verplicht voor B1)

- [ ] **Bekabeld Ethernet** van Marstek naar de lokale LAN-switch/router
- [ ] Op de Marstek een **vast IP-adres** instellen (DHCP-reservering op de router **of** vast IP op het toestel)
- [ ] Noteer: IP, subnet, gateway, en dat Modbus **poort 502** bereikbaar is
- [ ] Controleer dat **Synology** dit IP kan bereiken
- [ ] Geen port-forward van Modbus/UDP naar het internet

---

## 3. Marstek-app — eerste configuratie

- [ ] Toestel in bedrijf nemen volgens Marstek-handleiding
- [ ] **Firmware updaten naar de nieuwste versie** (vóór we registers vastleggen)
- [ ] Meter / CT: kies **P1-meter**, vul het **IP van de HomeWizard P1** in, opslaan
- [ ] Faseregeling: **driefasig / totale som**
- [ ] Maximumvermogen: **2500 W**
- [ ] Werkingsmodus: **Auto** (zelfconsumptie / zero-sum via P1) — **niet** de Marstek AI-tariefmodus
- [ ] Open API / UDP (poort 30000) **uit** laten of uitgeschakeld houden als die met P1/Modbus conflicteert (liever bekabeld Modbus voor onze logging)

---

## 5. Administratie (België / Vlaanderen)

- [ ] Binnen **30 dagen** na indienstname aanmelden via **Mijn Fluvius** (decentrale opslag **2,5 kW**, Synergrid C10/26)
- [ ] **Brandverzekeraar** per e-mail informeren over de thuisbatterij

---

## 6. Klaar voor software B1 (na hardware)

Pas als alles hierboven groen is:

- [ ] Vast Marstek-IP + firmware actueel + modus **Auto**
- [ ] P1 Lokale API aan + gekoppeld in Marstek-app
- [ ] Synology bereikt `Marstek-IP:502`
- [ ] Na software: Marstek-IP invullen in **homepower Settings** (zelfde plaats als P1)
- [ ] Pad **`/marstek`**: statuspagina (live SOC, W, link OK/fout, link naar Settings) — geen geschiedenisgrafieken; sturing = later B2

Tijdens de soak (~2 weken):

- [ ] Marstek in **Auto** laten staan (geen AI, geen handmatige force-charge tenzij nood)
- [ ] Controleren of logging/grafieken lopen (batterijlijn, SOC, Balance)
- [ ] Afwijkingen noteren (lege batterij ’s avonds, pieken, foutmeldingen)
