---
title: Kom igång med Kunskapstratten
source_file: levereras med paketet
source_hash: ''
converted_at: '2026-09-10T00:00:00+00:00'
tags:
- guide
- kom-igång
author: Kunskapstratten
summary: En kort guide till banker, dokument, import, skills och AI-inställningar.
source_type: exempel
---

# Kom igång med Kunskapstratten

## Välj eller skapa en kunskapsbank

Välj bank i listan högst upp. Med **＋ Bank** skapar du en ny, tom bank. Varje
bank har egna dokument, skills, importer, inställningar och minne.

## Lägg till innehåll

- Välj **＋ Nytt** för att skriva ett Markdown-dokument direkt.
- Välj **Ladda upp** för att lägga filer i bankens inbox.
- Klicka **Uppdatera** när uppladdade filer ska bearbetas.

Importerade original sparas i den aktiva bankens `processed`-mapp och den
bearbetade texten visas under **Bläddra**.

## Använd AI och skills

Öppna **Inställningar → AI** för att välja AI-server och modell. Under
**Chatta** väljer du vilka dokument som ska användas som underlag. En skill är
en återanvändbar instruktion som kan köras mot valda dokument. Exemplet
**Sammanfatta dokument** visar grundformatet och kan redigeras eller raderas.

## Drift och säkerhetskopiering

Alla data ligger under `content/<bank-id>/storage`. Säkerhetskopiera hela
`content`-mappen och `config.php`. Byt standardlösenordet `admin123` enligt
instruktionerna i `README.md` innan webbplatsen används skarpt.
