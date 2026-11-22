# Menedżer CRM AI

Moduł do Perfex CRM, który łączy CRM z asystentami AI przez MCP i OAuth: Claude, Codex, ChatGPT, Gemini CLI. Asystent czyta dane i za zgodą przygotowuje zmiany. Każdą zmianę widać przed zapisem i da się ją cofnąć.

Autor: Dariusz Trachimowicz, https://d-x.pl/. Licencja: GPL v2 lub nowsza (kod modułu; Perfex CRM ma własną licencję).

## Co robi

- **Logowanie bez kopiowania haseł.** W Claude dodajesz konektor z adresem z zakładki Start, logujesz się do CRM i zatwierdzasz poziom oraz termin dostępu.
- **Lista uprawnionych.** Połączyć AI mogą tylko osoby, które administrator dodał na listę. Administratorzy są na niej zawsze. Usunięcie osoby od razu odcina jej połączenia.
- **Uprawnienia z Perfexa.** Asystent nigdy nie zrobi więcej niż rola osoby w CRM. Poziom w module (Odczyt, Praca, Pełny) dodatkowo to ogranicza.
- **Dane:** klienci i kontakty, leady, zgłoszenia, zadania, projekty, faktury, oferty, propozycje, płatności, baza wiedzy, ogłoszenia, kalendarz.
- **Zmiany z podglądem:** zadania, komentarze, notatki, leady, statusy zgłoszeń, szkice odpowiedzi, szkice artykułów bazy wiedzy, klienci, kontakty, projekty. Cofnąć można każdą zmianę pól oraz własny komentarz, notatkę i szkic. Utworzonego klienta, kontaktu, zadania, leada czy artykułu AI nie cofa: usuwa je pracownik w panelu.
- **Nowy kontakt nie dostaje maili:** flagi wysyłki faktur, ofert i powiadomień są wyłączone, włącza je pracownik.
- **Bez usuwania i bez wysyłek.** Moduł nie ma narzędzi do usuwania danych. Odpowiedź do klienta powstaje jako notatka wewnętrzna zgłoszenia, wysyła ją pracownik. Zapisy omijają modele Perfexa, więc nie uruchamiają maili ani powiadomień.
- **Historia** każdego użycia narzędzia, przechowywana 30 dni.
- **Aktualizacje** z wydań na GitHubie, przyciskiem na zakładce Start.

## Wymagania

- Perfex CRM 3.0 lub nowszy, PHP 8.1 lub nowszy, rozszerzenia curl i zip.
- CRM pod adresem HTTPS.
- Serwer www musi przepuszczać do Perfexa adresy `/menedzer_crm_ai/.well-known/...`. Apache z plikiem `.htaccess` Perfexa robi to sam. Domyślna konfiguracja CloudPanel przechwytuje każdy adres z `/.well-known`: zakładka Start pokazuje gotową regułę nginx.

## Instalacja

1. Pobierz `menedzer_crm_ai-X.Y.Z.zip` z wydań na GitHubie.
2. W Perfexie: Ustawienia, Moduły, Wyślij moduł, wskaż plik, a potem Włącz.
3. Otwórz Ustawienia, Menedżer CRM AI, Start i uruchom Sprawdź.
4. Dodaj osoby w zakładce Uprawnienia.
5. Skopiuj adres serwera MCP i dodaj konektor w Claude.

## Wiersz poleceń

Z katalogu Perfexa, jako użytkownik strony: `php index.php menedzer_crm_ai/cli/status`, `cli/install`, `cli/update`, `cli/token <id pracownika> [read|work|full] [dni]` (poświadczenie pokazane raz), `cli/revoke <id połączenia>`. Przez przeglądarkę te adresy zwracają 404.

## Rozwój

- Kod modułu: `menedzer_crm_ai/`. Testy: `tests/e2e.js` (Playwright, lokalny Perfex w Dockerze).
- Paczka: `python build.py` tworzy `dist/menedzer_crm_ai-X.Y.Z.zip` z plików śledzonych w git.
- Wydanie: podbij `Version` i `MCAI_VERSION` w `menedzer_crm_ai/menedzer_crm_ai.php`, wpis w `CHANGELOG.md`, `python build.py`, commit, `gh release create vX.Y.Z dist/menedzer_crm_ai-X.Y.Z.zip`.

---

**English.** Perfex CRM module connecting the CRM to AI assistants (Claude, Codex, ChatGPT, Gemini CLI) over MCP with OAuth. Only staff on the allow list can connect; the AI never exceeds the person's Perfex role; every change has a preview, verification and rollback; no deleting and nothing is ever sent to customers. Install the release ZIP via Setup, Modules, Upload.
