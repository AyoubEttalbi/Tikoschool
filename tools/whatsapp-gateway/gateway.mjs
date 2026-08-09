/**
 * Minimal send-only WhatsApp gateway.
 *
 * Speaks the same HTTP contract as Evolution API — POST /message/sendText/:instance with
 * an `apikey` header — so App\Support\WhatsApp works against either without a code change.
 * That is deliberate: test with this, swap to Evolution later (or never) by changing one
 * env var.
 *
 * Send-only on purpose. It registers no message handler, joins no groups and exposes no
 * read endpoints. The school sends absence notices; nothing else needs to exist, and every
 * capability the gateway does not have is one that cannot be abused if the key leaks.
 */
import { createServer } from 'node:http';
import { mkdirSync, rmSync } from 'node:fs';
import makeWASocket, {
  useMultiFileAuthState,
  DisconnectReason,
  fetchLatestBaileysVersion,
} from '@whiskeysockets/baileys';
import qrcode from 'qrcode-terminal';
import QRImage from 'qrcode';

const PORT = Number(process.env.PORT || 8080);
const BIND = process.env.BIND || '127.0.0.1';
const DEV_API_KEY = 'local-dev-key';
const API_KEY = process.env.GATEWAY_API_KEY || DEV_API_KEY;
const INSTANCE = process.env.INSTANCE || 'tikoschool';
const AUTH_DIR = process.env.AUTH_DIR || './auth';
const DRY_RUN = process.env.DRY_RUN === '1';

mkdirSync(AUTH_DIR, { recursive: true });

let sock = null;
let state = DRY_RUN ? 'open' : 'connecting';
let lastQr = null;

async function connect() {
  if (DRY_RUN) {
    console.log('[wa] DRY_RUN — no WhatsApp connection, sends are accepted and discarded');
    return;
  }

  const { state: auth, saveCreds } = await useMultiFileAuthState(AUTH_DIR);
  const { version } = await fetchLatestBaileysVersion();

  sock = makeWASocket({
    version,
    auth,
    // Never mark the school's account as online: it would make staff phones show the
    // number as "active" and, more importantly, trigger read receipts on parent replies.
    markOnlineOnConnect: false,
    syncFullHistory: false,
  });

  sock.ev.on('creds.update', saveCreds);

  sock.ev.on('connection.update', (u) => {
    if (u.qr) {
      lastQr = u.qr;
      state = 'qr';
      console.log('\n[wa] Scan this with the school phone: WhatsApp > Linked devices\n');
      qrcode.generate(u.qr, { small: true });
    }

    if (u.connection === 'open') {
      state = 'open';
      lastQr = null;
      console.log('[wa] connected as', sock?.user?.id);
    }

    if (u.connection === 'close') {
      const code = u.lastDisconnect?.error?.output?.statusCode;
      const loggedOut = code === DisconnectReason.loggedOut;
      state = loggedOut ? 'logged_out' : 'connecting';
      console.log('[wa] closed, code', code, loggedOut ? '(logged out — delete auth dir and re-scan)' : '(reconnecting)');
      if (!loggedOut) setTimeout(connect, 3000);
    }
  });
}

/*
 * Tear the session down and start a fresh one.
 *
 * `wipeCreds` is the whole difference between "try again" and "pair a new phone".
 * WhatsApp revokes the pairing on a logged_out close, so the credentials left on disk are
 * dead: connect() with them still present fails the same way forever, which is exactly how
 * this gateway ended up parked in logged_out with no QR and no way out short of a redeploy.
 * Wiping is destructive, so it happens only when the credentials are already known dead.
 */
async function restart({ wipeCreds }) {
  try {
    sock?.end?.(undefined);
  } catch {
    // Already gone. The point is only that the old socket stops competing with the new one.
  }

  sock = null;
  lastQr = null;

  if (wipeCreds) {
    rmSync(AUTH_DIR, { recursive: true, force: true });
    mkdirSync(AUTH_DIR, { recursive: true });
  }

  state = 'connecting';
  await connect();
}

const json = (res, code, body) => {
  res.writeHead(code, { 'content-type': 'application/json' });
  res.end(JSON.stringify(body));
};

const readBody = (req) =>
  new Promise((resolve, reject) => {
    let raw = '';
    req.on('data', (c) => {
      raw += c;
      if (raw.length > 1e6) reject(new Error('body too large'));
    });
    req.on('end', () => {
      try {
        resolve(raw ? JSON.parse(raw) : {});
      } catch {
        reject(new Error('invalid JSON'));
      }
    });
  });

const server = createServer(async (req, res) => {
  const url = new URL(req.url, `http://${req.headers.host}`);

  if (req.method === 'GET' && url.pathname === '/health') {
    return json(res, 200, { state, instance: INSTANCE, hasQr: Boolean(lastQr) });
  }

  /*
   * The QR as data, for the admin screen inside the app to render.
   *
   * Authenticated like the send endpoint: the QR is a credential — anyone who scans it
   * links THEIR device to the school's WhatsApp account and can then read every
   * conversation on it. It must never be readable without the key.
   */
  if (req.method === 'GET' && url.pathname === '/qr.json') {
    if (req.headers.apikey !== API_KEY) return json(res, 401, { message: 'bad apikey' });

    const payload = { state, instance: INSTANCE, qr: null };
    if (state !== 'open' && lastQr) {
      payload.qr = await QRImage.toDataURL(lastQr, { width: 320, margin: 2 });
    }

    return json(res, 200, payload);
  }

  // The QR as a scannable image, refreshed on its own because WhatsApp expires each code
  // after about twenty seconds. Open it in a browser and point the phone at the screen.
  if (req.method === 'GET' && url.pathname === '/qr') {
    if (state === 'open') {
      res.writeHead(200, { 'content-type': 'text/html' });
      return res.end('<body style="font:16px sans-serif;padding:2rem">Already connected. Nothing to scan.</body>');
    }
    if (!lastQr) {
      res.writeHead(200, { 'content-type': 'text/html' });
      return res.end('<meta http-equiv="refresh" content="1"><body style="font:16px sans-serif;padding:2rem">Waiting for the QR code…</body>');
    }
    const dataUrl = await QRImage.toDataURL(lastQr, { width: 320, margin: 2 });
    res.writeHead(200, { 'content-type': 'text/html' });
    return res.end(
      `<meta http-equiv="refresh" content="15">
       <body style="font:16px sans-serif;padding:2rem;text-align:center">
         <p><b>WhatsApp &gt; Linked devices &gt; Link a device</b></p>
         <img src="${dataUrl}" alt="QR">
         <p style="color:#666">Refreshes automatically. State: ${state}</p>
       </body>`
    );
  }

  /*
   * Unlink the phone, on purpose.
   *
   * Needed so an administrator can hand the account to a different phone, or cut a
   * suspected leak, without SSH. Deliberately destructive: it tells WhatsApp to drop the
   * pairing AND deletes the local credentials, because leaving stale creds behind means
   * the next start silently tries to resume a session the phone has already forgotten.
   */
  if (req.method === 'POST' && url.pathname === '/logout') {
    if (req.headers.apikey !== API_KEY) return json(res, 401, { message: 'bad apikey' });

    try {
      if (sock) await sock.logout();
    } catch (e) {
      // Already unlinked from the phone side. Still clear the local state below.
      console.log('[wa] logout returned', e?.message);
    }

    try {
      await restart({ wipeCreds: true });
    } catch (e) {
      return json(res, 500, { message: 'could not restart after logout: ' + e.message });
    }

    console.log('[wa] logged out — a new QR will follow');

    return json(res, 200, { state });
  }

  /*
   * Ask for a QR code.
   *
   * The counterpart to /logout, and the endpoint whose absence left the admin screen with
   * nothing to click: once WhatsApp revoked the pairing, the gateway sat in logged_out
   * forever — no QR, no reconnect, no recovery short of redeploying the container.
   *
   * Credentials are wiped ONLY when they are already dead. From any other state this is
   * just "drop the half-open socket and try again", which must not cost a working session.
   */
  if (req.method === 'POST' && url.pathname === '/connect') {
    if (req.headers.apikey !== API_KEY) return json(res, 401, { message: 'bad apikey' });

    if (state === 'open') {
      return json(res, 200, { state, message: 'already connected' });
    }

    try {
      await restart({ wipeCreds: state === 'logged_out' });
    } catch (e) {
      return json(res, 500, { message: 'could not start a new session: ' + e.message });
    }

    console.log('[wa] reconnect requested — a QR will follow shortly');

    return json(res, 200, { state });
  }

  const match = url.pathname.match(/^\/message\/sendText\/([\w-]+)$/);
  if (req.method === 'POST' && match) {
    // Constant-ish time is overkill for a LAN service, but an unauthenticated send
    // endpoint is a spam relay pointed at the school's own number.
    if (req.headers.apikey !== API_KEY) return json(res, 401, { message: 'bad apikey' });
    if (match[1] !== INSTANCE) return json(res, 404, { message: 'unknown instance' });

    let body;
    try {
      body = await readBody(req);
    } catch (e) {
      return json(res, 400, { message: e.message });
    }

    const number = String(body.number || '').replace(/\D/g, '');
    const text = String(body.text || '');
    if (!number || !text) return json(res, 400, { message: 'number and text are required' });

    if (state !== 'open') return json(res, 503, { message: `not connected (state: ${state})` });

    if (DRY_RUN) {
      console.log(`[wa] DRY_RUN accepted -> ${number.slice(0, 4)}***  ${text.length} chars`);
      return json(res, 201, { key: { id: 'dry-run' } });
    }

    try {
      const sent = await sock.sendMessage(`${number}@s.whatsapp.net`, { text });
      console.log(`[wa] sent -> ${number.slice(0, 4)}***`);
      return json(res, 201, { key: sent?.key });
    } catch (e) {
      console.error('[wa] send failed', e?.message);
      return json(res, 502, { message: e?.message || 'send failed' });
    }
  }

  json(res, 404, { message: 'not found' });
});

/*
 * Loopback by default. This process can message every parent in the school; it has no
 * business listening on a public interface.
 *
 * BIND exists for one case: inside Docker, where 127.0.0.1 is the container's own loopback
 * and the PHP container cannot reach it at all. There the address that matters is the
 * compose network, and the service is `expose`d rather than published, so it is still
 * unreachable from outside the host.
 *
 * The API key is the only thing standing between whoever can reach this port and the
 * school's WhatsApp account, so binding wider than loopback while still using the built-in
 * development key is refused rather than warned about.
 */
if (BIND !== '127.0.0.1' && API_KEY === DEV_API_KEY) {
  console.error('[wa] refusing to start: GATEWAY_API_KEY must be set when BIND is not 127.0.0.1');
  process.exit(1);
}

server.listen(PORT, BIND, () => {
  console.log(`[wa] listening on http://${BIND}:${PORT}  instance=${INSTANCE}  dry_run=${DRY_RUN}`);
  connect().catch((e) => console.error('[wa] connect failed', e));
});
