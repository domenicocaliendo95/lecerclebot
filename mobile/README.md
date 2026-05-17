# Le Cercle Club — App Mobile

Player app per Le Cercle Tennis Club. Stack: Expo SDK 52 + Expo Router + NativeWind v4 + TypeScript.

## Setup (prima volta)

```bash
cd mobile
npm install
```

Poi:

```bash
npm start          # avvia Metro bundler (QR + simulator picker)
npm run ios        # apre simulator iOS
npm run android    # apre emulatore Android
```

Per testarla sul telefono fisico: installa l'app **Expo Go** dallo store, poi scansiona il QR code mostrato da `npm start`.

## Struttura

```
mobile/
├── app/                        # Route file-based (Expo Router)
│   ├── _layout.tsx             # root layout: fonts, providers, stack
│   ├── index.tsx               # → redirect a /(auth)/login
│   └── (auth)/
│       ├── _layout.tsx         # stack auth (no header)
│       └── login.tsx           # schermata login OTP
├── components/                 # componenti riusabili (Button, Card, ...)
├── lib/
│   ├── theme.ts                # design tokens Riviera (palette light/dark, radii, spacing)
│   └── fonts.ts                # Google Fonts da caricare (Fraunces, Manrope, Italianno)
├── assets/images/              # icona, splash, adaptive icon
├── app.json                    # config Expo (nome, bundle ID, plugins)
├── tailwind.config.js          # NativeWind theme con palette/font Riviera
└── package.json                # deps
```

## Design system — Riviera

Tutte le scelte visive sono in `/mocks/riviera.html` (mock HTML statici) e replicate qui in NativeWind:

- **Palette**: sage `#6B8068` (primary), cream `#ECE3CE` (surface), ocra `#C89B5A` (accent)
- **Font**: Fraunces (display serif), Manrope (UI sans), Italianno (script decorativo)
- **Forme**: rounded sempre, ombre soft tinta sage, gradient sage 135° per CTA
- **Dark mode**: bg `#1A211D`, surface `#243029`

## Backend

API base: `https://bot.lecercleclub.it/api/v1/app/*` (configurabile in `app.json` → `extra.apiBaseUrl`).

Endpoint auth (entry point):
- `POST /auth/request-otp` — manda OTP via WhatsApp
- `POST /auth/verify-otp` — valida codice, ritorna Sanctum bearer token

## Stato

🚧 **Fase 0 — Fondamenta** (in corso)

- [x] Scaffold Expo + NativeWind + TypeScript
- [x] Theme tokens Riviera
- [x] Font loading
- [x] Schermata login (UI)
- [ ] Chiamata API request-otp
- [ ] Schermata OTP verify
- [ ] Persistenza token (expo-secure-store)
- [ ] Biometric unlock
- [ ] Push notifications setup
- [ ] Schermata home

Dopo Fase 0: parità WhatsApp bot (Fase 1) → app-only features (Fase 2-4).
