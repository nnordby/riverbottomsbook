# riverbottomsbook.com

Static launch site for *From River Bottoms to Rock Bottom and Back* — Don Lineberry.
One page (`index.html`) + `img/`. Tailwind/FontAwesome via CDN. The "Contact Don"
form (podcast/speaking/engagement inquiries) posts to a Google Apps Script endpoint
(serverless) that emails Don and logs each inquiry to a Sheet.

## Deploy — auto on push (Plesk managed Git)

- Push to **`main`** → auto-deploys to **live** (https://riverbottomsbook.com).
- Push to **`preview`** → auto-deploys to **/preview/** (https://riverbottomsbook.com/preview/)
  for review before going live. Typical flow for Don's copy edits:
  commit to `preview`, share the preview URL, then merge `preview` → `main`.

No build step — Plesk copies the files. Host: Plesk/Contabo, metamorfix.org subscription.
