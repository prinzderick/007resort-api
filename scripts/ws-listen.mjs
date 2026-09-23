#!/usr/bin/env node
// Minimal Pusher-protocol (Reverb) listener for the smoke test. Node >= 22 (global WebSocket, fetch).
// usage: node scripts/ws-listen.mjs --api http://127.0.0.1:8080 --token <bearer> [--device <X-Device-Token>] --out events.jsonl --channels private-a,private-b [--ttl 120]
// Writes one JSON line per received event: {"channel","event","data"} and a {"event":"__subscribed","channel"} line per successful subscription.
import { appendFileSync, writeFileSync } from 'node:fs';

const arg = (n, d) => { const i = process.argv.indexOf('--' + n); return i > 0 ? process.argv[i + 1] : d; };
const api = arg('api', 'http://127.0.0.1:8080').replace(/\/$/, '');
const token = arg('token');
const device = arg('device');
const out = arg('out', 'events.jsonl');
const channels = (arg('channels', '')).split(',').filter(Boolean);
const ttl = Number(arg('ttl', '120')) * 1000;
writeFileSync(out, '');
const log = (o) => appendFileSync(out, JSON.stringify(o) + '\n');

const info = await (await fetch(`${api}/api/v1/system/info`)).json();
const rt = info.realtime;
const host = new URL(api).hostname; // clients dial the API host; the node advertises its LAN address
const ws = new WebSocket(`ws://${host}:${rt.port}/app/${rt.appKey}?protocol=7&client=smoke&version=1.0`);
const timer = setTimeout(() => { ws.close(); process.exit(0); }, ttl);

ws.onmessage = async (m) => {
  const msg = JSON.parse(m.data);
  if (msg.event === 'pusher:connection_established') {
    const socketId = JSON.parse(msg.data).socket_id;
    for (const channel of channels) {
      const r = await fetch(`${api}/api/v1/broadcasting/auth`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}`, ...(device ? { 'X-Device-Token': device } : {}) },
        body: JSON.stringify({ socket_id: socketId, channel_name: channel }),
      });
      if (!r.ok) { log({ event: '__auth_failed', channel, status: r.status, body: await r.text() }); continue; }
      ws.send(JSON.stringify({ event: 'pusher:subscribe', data: { auth: (await r.json()).auth, channel } }));
    }
  } else if (msg.event === 'pusher_internal:subscription_succeeded') {
    log({ event: '__subscribed', channel: msg.channel });
  } else if (msg.event === 'pusher:ping') {
    ws.send(JSON.stringify({ event: 'pusher:pong', data: {} }));
  } else if (!msg.event.startsWith('pusher')) {
    let data = msg.data; try { data = JSON.parse(msg.data); } catch {}
    log({ channel: msg.channel, event: msg.event, data });
  }
};
ws.onerror = (e) => log({ event: '__error', message: String(e.message ?? e) });
ws.onclose = () => { clearTimeout(timer); process.exit(0); };
