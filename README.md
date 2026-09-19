# Kunskapstratten – PHP

Fristående kunskapsbank för en vanlig PHP-webbserver. Serverkod, mallar,
Markdown, YAML, AI-klient och import är vanlig PHP utan ramverk, Composer eller
pakethanterare.

## Lösenord

Standardlösenordet är `admin123`. För att byta lösenord:

1. Kopiera `config.example.php` till `config.php`.
2. Öppna `config.php` och ändra värdet direkt:

   ```php
   'password' => 'ditt-eget-lösenord',
   ```

3. Spara filen och ladda upp den bredvid `index.php`.

Alla kunskapsbanker använder samma lösenord. `config.php` ignoreras av Git så
att det egna lösenordet inte checkas in.

## Ladda upp på webbserver

1. Klona repot eller ladda ned källkoden:

   ```bash
   git clone https://github.com/yllemo/kunskapstratten-php.git
   ```

2. Ladda upp projektets **innehåll**, inklusive `.htaccess` och `content/`,
   till exempel till `public_html/kunskap/`.
3. Servern behöver **PHP 8.2 eller senare** och tilläggen `curl`, `mbstring`,
   `dom`, `xml`, `xmlreader`, `xmlwriter`, `simplexml`, `zip`, `fileinfo`, `gd`
   och `iconv`, samt `zlib` för komprimerade PDF:er. Dessa ingår normalt i webbhotellets PHP-installation men kan
   behöva aktiveras i kontrollpanelen.
4. Logga in med standardlösenordet `admin123`. För ett eget lösenord, kopiera
   `config.example.php` till `config.php` och ändra `password`.
5. Ge PHP skrivbehörighet till `content/` och dess undermappar. Använd helst en
   innehållsmapp **utanför den publika webbkatalogen** och ange dess absoluta
   sökväg som `content_root` i `config.php`. Flytta då hela projektets
   `content`-innehåll dit.
6. Öppna webbplatsen över HTTPS och logga in. Välj AI-server och modell under
   **Inställningar → AI**, testa anslutningen och spara.

Alla kunskapsbanker delar samma inloggningslösenord. Varje bank har egna
dokument, skills, importer, inställningar och minne. Det finns inte separata
användarkonton.
Din egen API-nyckel sparas i webbläsarens localStorage under Inställningar → AI.
Status visar om den finns; Radera lokal nyckel tar bort den direkt. En lokal
nyckel prioriteras framför `ai.api_key` i `config.php`. Serverns nyckel
returneras aldrig till webbläsaren. Lokal nyckel gäller alla banker i samma
installation och webbläsare, och skickas till PHP vid AI-anrop utan att sparas
i bankinställningar. CLI/cron använder alltid nyckeln i `config.php`.
Tidigare nycklar i `data/settings.json` används inte och tas bort nästa gång
du sparar inställningarna.

För serverns reservnyckel, ange följande i `config.php` (lägg till i befintlig `ai`-sektion):

```php
'ai' => [
    'api_key' => 'din-servernyckel',
],
```

**Spara** lagrar den personliga nyckeln i localStorage och kontrollerar att den
kan läsas tillbaka. **Visa** visar din lokala nyckel; **Testa anslutning** provar
nyckeln mot vald AI-tjänst. **Radera lokal nyckel** raderar direkt, även om du
sedan avbryter dialogen. Den lokala nyckeln behålls över omladdning och utloggning.

Alla interna länkar fungerar med `index.php?r=...`, även i en undermapp och
utan URL-omskrivning. **Skyddet för privata kataloger måste ändå fungera.**
Apache-konfigurationen medföljer i `.htaccess`; IIS-konfigurationen finns i
`web.config` och kräver URL Rewrite. Låt inte servern exponera `content`,
`app` eller `templates` som statiska filer.

För Nginx behövs motsvarande regler i serverkonfigurationen (anpassa `/kunskap/`):

```nginx
location ~ ^/kunskap/(app|templates|content)(/|$) { deny all; }
location ~ ^/kunskap/(config.*\.php|README\.md|run\.php|\.) { deny all; }
location /kunskap/ { try_files $uri $uri/ /kunskap/index.php?$query_string; }
location = /kunskap/index.php {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/kunskap/index.php;
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    fastcgi_buffering off;
    fastcgi_read_timeout 180s;
}
```

Anpassa socketen till serverns PHP-version. Undvik en generell PHP-regel som
går före skyddsreglerna. Kontrollera att `/kunskap/content/default/storage/kunskapsbank/kom-igang.md`
ger 403/404 innan du lägger in privata dokument. Apache och IIS behöver också
stödja sina medföljande skyddsregler. PHP:s utvecklingsserver är bara för lokal testning.

Exempel på PHP-inställningar för större importer:

```ini
upload_max_filesize = 40M
post_max_size = 100M
memory_limit = 512M
max_execution_time = 180
output_buffering = Off
```

Appens egen gräns är 40 MB per fil och kan ändras i `config.php`. Webbhotellets
gränser gäller också. Bearbeta stora inboxar via CLI/cron om webbhotellet
avbryter långa HTTP-anrop. AI-strömning kräver att proxy/FastCGI inte buffrar svaret.

## Funktioner

- Bläddring med kort, lista och tabell, fulltextsökning, typfilter, sortering,
  24/48/96 dokument per sida samt sökbara taggar med artikelantal.
- Flera separata kunskapsbanker. Byt bank i sidhuvudet eller skapa en ny med
  **＋ Bank**; valet sparas i den aktuella webbläsarsessionen.
- Markdown-artiklar med YAML-frontmatter, Monaco på desktop, textfält på mobil,
  Markdown-nedladdning, originalfiler och valbar snabbförhandsvisning.
- Filuppladdning, SHA-256-dubblettkontroll, valfri AI-formatering och frontmatter/taggar, bildbeskrivningar,
  arkivering av original och tydliga fel som låter misslyckade filer ligga kvar i inboxen.
- Strömmande chatt, stoppknapp, valda KB-dokument, tillfälliga bilagor, gemensamt
  minne, tokenuppskattning, källhänvisningar och export till kunskapsbanken.
- Skapa och redigera skills, spara dokumentförval, använd i chatt eller kör
  separat, kopiera resultat och spara resultat som Markdown.
- Mermaid, kopierbara kodblock, ljust/mörkt tema, mobilmeny och inbyggd hjälp.
- AI-/bank-/minnesinställningar och återställning med förhandsgranskning,
  engångstoken, fem minuters giltighet och serverkontrollerad mattefråga.
- Lösenordsinloggning för webbserver, CSRF-skydd, sökvägskontroll och fillås
  mellan importer, chattar, ändringar och återställning.

Dokumentkonverteringen är inbyggd PHP. Ingen Composer, Python, pip, OCR eller
extern konverterare behövs. PHP-tilläggen `zip`, `dom`, `mbstring` och `zlib`
måste vara aktiva på webbservern.

| Format | Inbyggd konvertering |
| --- | --- |
| PDF | Textströmmar, komprimering, textarrayer och Unicode-teckenkartor. |
| DOCX | Stycken, rubrikstilar, listor och tabeller. |
| PPTX | Text, titlar och tabeller per bild. |
| XLSX | Bladnamn, cellplacering, delade/inline-strängar och sparade formelresultat. |
| DOC, PPT, XLS | Enkelt textutdrag ur äldre binärformat; spara helst om till DOCX/PPTX/XLSX. |
| TXT, MD, HTML, CSV, JSON, XML | Text eller Markdown. |
| EPUB, ZIP | Textinnehåll ur arkivet. |
| Bilder | Originalbild och eventuell AI-beskrivning. |
| MP3, WAV | Kräver separat AI-server med transkriberingsstöd. |

Under **Inställningar → Import** väljer du Markdown eller ren text, tabellformat,
sid-/bildnummer och hur PDF-rader sammanfogas. Inställningarna sparas per bank
och påverkar nya importer. För jämförelse av samma dokument kan du använda två
banker med olika inställningar.

PDF-konverteringen återger text, inte exakt sidlayout. Kolumner och ovanliga
fontkodningar kan ge förenklad eller ofullständig läsordning. Krypterade PDF:er
måste sparas utan lösenord. Skannade PDF:er har ingen text att hämta och ger
ett tydligt meddelande; OCR är inte ett installationskrav. Office-layout,
sammanslagna celler och avancerad formatering förenklas. XLSX-formler körs inte;
saknas ett sparat resultat blir cellen tom.

## Lokal start

```powershell
cd kunskapstratten-php
php -S 127.0.0.1:8088 index.php
```

Öppna `http://127.0.0.1:8088`. Kommandot kräver PHP installerat lokalt.

## Filer och befintliga data

```text
index.php                 Webbserverns ingång
app/                      PHP-kod för routing, lagring, AI och import
templates/                Statisk HTML-del för inställningsdialogen
static/                   Originalets CSS och JavaScript, plus PHP-anpassning
content/                               En katalog per kunskapsbank
content/default/bank.json              Bankens namn och id
content/default/storage/kunskapsbank/  Markdown och bilder
content/default/storage/skills/        Skills och dokumentförval
content/default/storage/inbox/         Filer att bearbeta
content/default/storage/processed/     Original
content/default/storage/data/          Register, inställningar och MEMORY.md
content/default/storage/logs/          Lokala loggar
```

Standardbanken innehåller bara dokumentet **Kom igång med Kunskapstratten** och
exempelskillen **Sammanfatta dokument**. Inbox, importer, original och register
är tomma. Källkoden innehåller inga API-nycklar, sparade AI-inställningar,
personliga lösenord, minnesfiler eller testdata.

## Kommandorad och verifiering

```text
php run.php process             Bearbeta inboxen en gång
php run.php process --force     Bearbeta även dubbletter i inboxen
php run.php watch 10            Bevaka var tionde sekund
php run.php status              Visa antal dokument, skills och väntande filer
php run.php skills              Lista skills
php run.php banks               Lista banker; aktiv bank markeras med *
```

Lägg till `--bank=bank-id` till `process`, `watch`, `status` eller `skills` för
att arbeta mot en viss bank, till exempel `php run.php status --bank=ekonomi`.

Ett webbhotell med cron kan köra `php /absolut/sökväg/run.php process`.
Alla programsökvägar utgår från projektmappen; processen behöver inte ha den
som arbetskatalog. `watch` behövs inte för normal webbdrift.

Webbhotellets PHP-konfiguration och en skarp AI-anslutning behöver kontrolleras
på målservern.

## Snygga till Markdown med AI

I dokument- och skill-redigeraren finns **Snygga till med AI**. Funktionen använder
texten i redigeraren, inklusive osparade ändringar, och föreslår Markdown-struktur,
taggar och frontmatter. Dokumentets ord och ordning kontrolleras mot originalet;
ändringar av kod och länkar stoppas också. Källkopplingar och egna metadatafält
behålls. AI-förslaget visas i redigeraren och sparas först när du klickar **Spara**.
**Ångra AI-formatering** återställer texten före senaste formateringen.

AI måste vara aktiverad. Ollama körs direkt från webbläsaren; övriga tjänster
använder din lokala API-nyckel eller reservnyckeln i config.php. Dokument över
100 kB skickas inte, och texten trunkeras aldrig automatiskt.

## AI-formatering vid första importen

**Inställningar → Import → Snygga till Markdown och uppdatera frontmatter/taggar
med AI vid import** är aktiverat som standard. Med aktiverad AI använder importen
samma instruktioner och kontroller som redigerarens knapp. Minst en giltig tagg
krävs; taggar normaliseras och dubbletter tas bort. Titel, sammanfattning och
uppdateringstid sparas i YAML tillsammans med befintliga källkopplingar.

OpenAI-kompatibla tjänster kör AI-steget på servern. Med Ollama kör **Bearbeta nu**
och **Uppdatera** steget i webbläsaren och sparar det validerade resultatet.
Lämna sidan öppen tills det är klart. Vid avbrott ligger importen kvar med
`ai_format: pending`; nästa Uppdatera försöker igen. CLI/cron med Ollama konverterar
filen och lämnar AI-steget väntande till nästa uppdatering i webbläsaren.

AI-fel eller förslag som ändrar ord behåller den konverterade texten och ger en
varning. Dokument över 100 kB AI-formateras inte automatiskt. Avstängd AI eller
avstängd AI-import ger vanlig PHP-konvertering. Redigerarknappen visar vilka
taggar som föreslogs och om Markdown-strukturen ändrades eller behölls.

## Kodbaserad Markdown-städning

**Snygga till** kör först vanlig PHP-städning och därefter AI om AI är aktiverad.
Kodstädningen fungerar även utan AI och används vid import. Den normaliserar
radslut, tar bort BOM, osynliga skräptecken och kontrolltecken, ersätter hårda
mellanslag och begränsar upprepade tomrader. Avsiktliga Markdown-radbrytningar
(två mellanslag) behålls. Kodblock, indenterad kod, inline-kod och länkadresser
skyddas; innehållsrader eller vanliga ord tas inte bort.

Om AI-anropet misslyckas finns den kodstädade texten kvar i redigeraren.
**Ångra uppsnyggning** återställer originalet före båda stegen. Granska och
klicka **Spara** för att spara filen. Kodstädning ändrar inte taggarnas betydelse;
AI uppdaterar frontmatter/taggar när det är aktiverat.

## Rubriker, tabeller och taggar även utan AI

**Snygga till** skapar nu också rubriker från korta befintliga rubrikrader,
normaliserar Markdown-tabeller, skapar saknade tabellavdelare och omvandlar
sammanhängande tab-separerade rader till tabeller. Vanliga ord och deras ordning
behålls; kod skyddas. Kortfattade taggförslag hämtas från dokumentets innehåll
och skrivs i YAML även när AI är avstängd eller anropet misslyckas.

AI-taggar ersätter de lokala förslagen när ett giltigt svar kommer. Saknad
sammanfattning i ett i övrigt giltigt AI-svar kastar inte längre bort rubriker,
tabeller och taggar. Ollama, LM Studio och OpenAI-kompatibla tjänster använder samma instruktioner, JSON-schema och kontroll av ord, kod och länkar.

**Uppdatera** reparerar också tidigare dokument med tomma taggar eller misslyckad
AI-import (upp till 2 MB): kodbaserad struktur/taggar sparas och AI-steget
återförs till kön när det är aktiverat och texten ryms inom AI-gränsen.

AI-instruktionerna för uppsnyggning bedömer nu hela dispositionen: logiska
ämnesbyten blir rubriker, självständiga uppräkningar blir listor och upprepade
fält blir tabeller, även när texten från början är löpande prosa. Exemplen i
prompten visar hur det görs utan att skriva om orden. AI:ns valda rubrikhierarki
bevaras efter valideringen och skrivs inte över av den enklare kodheuristiken.

AI-formateringen använder den aktuella AI-konfigurationen under Inställningar för
både import och Snygga till. Ollama får schemat i `format`; LM Studio och
OpenAI-kompatibla tjänster får `response_format` med `json_schema`. Om en äldre
kompatibel server uttryckligen saknar stöd för detta görs ett nytt försök med
samma instruktioner och samma efterkontroll. Byte av leverantör, adress eller
modell under bearbetningen stoppar förslaget; starta då bearbetningen igen.

### Snygga till markerad text

Markera text i redigeraren och klicka **Snygga till** för att bearbeta endast
markeringen. Text utanför markeringen och dokumentets frontmatter bevaras.
Utan markering bearbetas hela dokumentet, inklusive frontmatter och taggar.
Markeringar i YAML-frontmatter hanteras genom att i stället köra hela dokumentet.
Både Monaco och den vanliga textrutan stöds. **Ångra uppsnyggning** återställer
hela texten före bearbetningen; ändringarna sparas först med **Spara**.

Vid gateway-timeout kan webbhotellets tidsgräns ha nåtts. Markera en mindre del
eller välj en snabbare modell. Ollamas tidsgräns gäller hela det strömmade svaret.
Om AI ändrar kod eller länkar stoppas AI-förslaget. Ändrade ord stoppas vid
import och på Varsam/Tydlig nivå; kodstädningen kan behållas
eller ångras.

### Nivåer för uppsnyggning i redigeraren

Välj nivån bredvid **Snygga till**:

- **Varsam:** reparerar Markdown och behåller dispositionen.
- **Tydlig struktur:** bearbetar rubriker, stycken, listor och återkommande fält.
- **Kraftig omskrivning** (standard): skriver om meningar, förbättrar språk och
  disposition, skapar beskrivande rubriker, listor och tabeller. Sakuppgifter och
  betydelse ska bevaras; kod, länkar och adresser skyddas med kodkontroll.

Redigeraren använder andra instruktioner än första importen. Samma nivå gäller
för markerad text och hela dokumentet, med alla AI-leverantörer. Varsam och Tydlig struktur bevarar originalets ord och ordning. Kraftig omskrivning
tillåter ändrade ord och omdisponering inom varje avsnitt. AI instrueras att bevara
fakta, namn, tal, datum, enheter och villkor; detta kan inte garanteras med en
ordjämförelse, så granska förslaget före Spara.
Tydlig/Kraftig gör högst ett extra AI-försök om första förslaget saknar
strukturändringar. Om AI fortfarande inte ändrar strukturen visas det tydligt.
Oförändrad text och metadata får ingen ny `updated_at` enbart för att AI körts.

### Tokenupprepning och HTTP 504 i OpenShift

Snygga till bearbetar större texter avsnittsvis med separata anrop och visar
vilket avsnitt som bearbetas. Uppdelning sker vid styckegränser; tabeller, listor
och kodblock hålls ihop. Avsnitten och hela det sammanfogade dokumentet
kontrolleras innan AI-förslaget visas. Om ett avsnitt misslyckas behålls
kodstädningen och AI-förslaget tillämpas inte delvis.

LM Studio/OpenAI-kompatibelt läge gör högst ett nytt försök utan tvingat
JSON-schema om servern uttryckligen avbryter på grund av tokenupprepning.
JSON-svaret och skyddet av ord, kod och länkar kontrolleras fortfarande.
Uppsnyggningen använder temperatur 0,15; vid upprepningsförsöket 0,2.

En långsam modell eller ett mycket stort sammanhängande avsnitt kan fortfarande
ge HTTP 504. OpenShift har en separat timeout på sin Route, utöver PHP och AI:s
timeout. Klusteradministratören kan till exempel sätta fem minuter:

```sh
oc annotate route <route-namn> -n <namespace> \
  haproxy.router.openshift.io/timeout=300s --overwrite
```

Justera även eventuell Route framför själva AI-servern och PHP-/webbserverns
tidsgränser vid behov. Använd den faktiska routen och namespace för installationen.
Kodändringen ändrar inga klusterinställningar. Se [Red Hats dokumentation om
Route-timeout](https://developers.redhat.com/articles/2025/07/02/how-haproxy-router-settings-affect-middleware-applications).

### Mermaid-diagram

Kodblock med språket `mermaid` visas som diagram i dokumentets Markdown-vy
och i chattens färdiga Markdown-svar. Båda använder samma renderare och
`https://cdn.jsdelivr.net/npm/mermaid@latest/dist/mermaid.esm.min.mjs`.
Mermaid hämtas först när ett diagram behöver visas. Visa Mermaid-kod öppnar
originalkoden; vid renderingsfel visas koden automatiskt. Webbläsaren behöver
kunna nå jsDelivr.

### Originalfil och inloggning

I Markdown-vyn visas **Visa originalfil** när dokumentets `source_file` pekar
på en befintlig fil i bankens `processed`-mapp. Filen öppnas i en ny flik;
format som webbläsaren inte kan visa laddas ned.

Inloggningen sparas i en HttpOnly-cookie med SameSite=Strict i 32 dagar.
Tiden förnyas vid användning. Logga ut avslutar sessionen och raderar cookien.
Serverns sessionsfiler ligger separat i `content/.sessions`, med samma livslängd,
så att webbhotellets vanliga korta sessionrensning inte tar bort dem. Mappen
är privat och ska skyddas tillsammans med övrigt content. HTTPS via
`X-Forwarded-Proto` stöds för OpenShift och andra reverse proxies.

På OpenShift måste content inklusive `.sessions` ligga på beständig lagring
för att inloggningen ska finnas kvar efter poddomstart. Vid flera repliker
behöver de dela samma content-lagring. En raderad cookie eller sessionsfil
kräver ny inloggning. Befintliga inloggningar kan behöva förnyas en gång efter
uppdateringen eftersom sessionslagringen ändras.

### Markdown-vy och zoom

Markdown-vyn använder Marked med CommonMark/GFM-stöd, och DOMPurify för
sanering. Rubriker och ankarlänkar, fet/kursiv/genomstruken text, nästlade
numrerade och vanliga listor, checklistor, citat, referenslänkar, bilder,
kodblock och tabeller renderas i webbläsaren. Tabellernas kolumnjustering
bevaras; breda tabeller rullas horisontellt. PHP-visningen finns kvar som
enklare reserv om JavaScript eller CDN-laddningen saknas.

Klicka på en bild eller ett Mermaid-diagram för att öppna lightboxen. Dra för
att panorera och scrolla/nyp för att zooma. Knapparna +/−, Anpassa och 100 %
styr zoom; Esc stänger. Tangentbord: Enter öppnar ett fokuserat objekt,
+/− zoomar, 0 anpassar och piltangenterna panorerar. Mermaid-diagram i chatten
använder samma lightbox. Funktionen kräver inga nya serverpaket.

### Subdomän och undermapp

Samma installation kan nås både från en subdomäns rot och en undermapp.
Publika adresser till CSS, JavaScript, formulär och API följer sökvägen i
förfrågan, även när PHP:s interna `SCRIPT_NAME` fortfarande innehåller
undermappen. Exempel: subdomänen använder `/static/style.css`, medan
`https://aiwiki.se/kunskapstratten/` använder `/kunskapstratten/static/style.css`.
Ladda upp den uppdaterade `app/bootstrap.php` och ladda om sidan efter uppdatering.

### Skapa skills

Formuläret för egna skills förklarar skillnaden mellan **Namn**, **Beskrivning**
och **Instruktioner**. Ett tydligt namn gör skillen lätt att känna igen.
Beskrivningen ska ange när skillen är relevant, vilket underlag den behöver
och vilket resultat den ger; en AI-agent kan läsa den för att välja rätt skill.
Instruktionerna beskriver själva utförandet efter att skillen valts.

### Beständig konfiguration i OpenShift

Du kan lägga `config.php` i projektroten **eller** direkt i den monterade
`content`-mappen. `content/config.php` är lämplig när `content` ligger på en
beständig volym som överlever uppdateringar. Filen har samma format som
`config.example.php` och kan innehålla bara de värden du ändrar:

```php
<?php
return [
    'password' => 'byt-till-ett-eget-losenord',
    'ai' => ['api_key' => 'din-servernyckel'],
];
```

Ordningen är: `config.example.php` → rotens `config.php` →
`content/config.php` → miljövariabeln `KB_PASSWORD`. Om båda config-filerna
finns vinner alltså värden i `content/config.php`. Mappen bestäms först av
`KB_CONTENT_ROOT` om den är satt, annars av rotens `content_root` eller
standardmappen `content`; en config-fil inuti content kan inte flytta sin
egen mapp. Rotens `config.php` behövs inte när den beständiga filen räcker.

Webbserverns `.htaccess` och `web.config` blockerar direkt åtkomst till
`content`, men montera helst mappen utanför publik webbrot. `config.php` i
content ignoreras av Git.

Chattens skill-väljare använder samma diskreta fältstil på desktop och mobil.
Den visar den aktiva skillens beskrivning när valet ändras och går att använda
med tangentbord och touch.
