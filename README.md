# Kunskapstratten – PHP

Fristående kunskapsbank för en vanlig PHP-webbserver. Serverkod, mallar,
Markdown, YAML, AI-klient och import är vanlig PHP utan ramverk, Composer eller
pakethanterare.

## Lösenord

Standardlösenordet är `changeme`. För att byta lösenord:

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
   och `iconv`. Dessa ingår normalt i webbhotellets PHP-installation men kan
   behöva aktiveras i kontrollpanelen.
4. Logga in med standardlösenordet `changeme`. För ett eget lösenord, kopiera
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
API-nyckeln sparas på servern och returneras inte till webbläsaren.

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
- Filuppladdning, SHA-256-dubblettkontroll, valfri AI-metadata, bildbeskrivningar,
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

Dokumentkonverteringen använder PHP-bibliotek i stället för MarkItDown, så
exakt Markdown-formatering kan skilja sig från Python-versionens resultat.

| Format | Stöd och begränsning |
| --- | --- |
| MD, TXT, HTML/HTM, CSV, JSON, XML | Inbyggd konvertering; HTML-skript körs inte. |
| PDF | Textbaserade PDF-filer. Skannade PDF-filer behöver OCR före import. |
| DOCX, PPTX | Text och stycken. Inbäddade bilder/diagram och avancerad layout återskapas inte. |
| DOC | PHPWord-läsare; äldre eller ovanliga varianter kan behöva sparas om till DOCX. |
| PPT | Textutdrag ur binära textposter; mallar, anteckningar och äldre sparningar kan ingå. PPTX ger bättre struktur. |
| XLSX, XLS | Kalkylblad som Markdown-tabeller. Formler läses som formeltext och körs inte. |
| EPUB | Textkapitel ur arkivet i filnamnsordning. |
| ZIP | Textfiler i arkivet samlas i en artikel. Nästlade arkiv och binära bilagor hoppas över. |
| PNG, JPG/JPEG, GIF, BMP, WebP | Bilden sparas; AI-beskrivning kräver att vald modell kan läsa bildformatet. |
| MP3, WAV | Kräver `/audio/transcriptions` på vald AI-server och rätt transkriptionsmodell. En vanlig Ollama-chattmodell räcker inte. |

Läsarstödet för äldre Word beskrivs i [PHPWords dokumentation](https://phpoffice.github.io/PHPWord/usage/readers.html).

AI-servern anropas från **webbservern**. `localhost` betyder därför webbservern,
inte datorn där webbläsaren körs. För en AI-server på din egen dator behöver
webbservern kunna nå den via en lämplig nätverksanslutning. Chatt och dokument
skickas endast till den AI-adress du anger. Monaco, Mermaid och chattens
Markdown-bibliotek hämtas från CDN, precis som i originalgränssnittet.

För Ollama väljer du **Ollama** och använder normalt
`http://127.0.0.1:11434` utan `/v1`. Appen använder Ollamas egna `/api/tags`
och `/api/chat`. Klicka **Hämta** för att läsa installerade modeller, välj en
modell och klicka sedan **Testa anslutning**.

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
