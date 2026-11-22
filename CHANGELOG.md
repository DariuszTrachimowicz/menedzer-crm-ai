# Zmiany

## 0.1.1, 22 listopada 2026

- Polecenia CLI dla administratora serwera: `token` (ręczne poświadczenie na te same zasady co w panelu, najwyżej rok), `revoke` (odcięcie połączenia), `update` (aktualizacja z najnowszego wydania).
- Data ukończenia zadania zachowana przy ponownym ukończeniu i ustawiana przy utworzeniu zadania jako ukończone.

## 0.1.0, 22 listopada 2026

- Pierwsza wersja: serwer MCP i OAuth 2.1 (rejestracja klienta, PKCE, rotowane tokeny, wykrywanie powtórek).
- Lista osób uprawnionych do łączenia AI; administratorzy zawsze z poziomem pełnym.
- Odczyt: klienci, kontakty, leady, zgłoszenia, zadania, projekty, faktury, oferty, propozycje, płatności, baza wiedzy, ogłoszenia, kalendarz, pracownicy. Widoczność według roli w Perfexie, bez haseł i kluczy.
- Zapisy z podglądem i sprawdzeniem: zadania, komentarze, notatki, szkice odpowiedzi w zgłoszeniach, statusy zgłoszeń, leady, szkice bazy wiedzy, klienci, kontakty, projekty. Cofanie zmian pól oraz własnych komentarzy, notatek i szkiców. Bez usuwania i bez wysyłek do klientów; nowy kontakt bez włączonych maili.
- Ręczne poświadczenie dla Codexa i Gemini CLI, historia 30 dni, sprawdzenie adresów logowania z gotową regułą nginx, aktualizacje z GitHuba.
