#!/usr/bin/env bash
# End-to-end MVP smoke test against a RUNNING, DEMO-SEEDED Local node (contract/api/mvp-flows.md, flows A, A2, B).
#   scripts/smoke-mvp.sh                      # default http://127.0.0.1:8080 (start it with scripts/local-node.sh start)
#   API=http://10.56.69.150:8080 scripts/smoke-mvp.sh
# Needs: curl, jq, node >= 22 (Reverb listener). Mutates the demo DB (creates orders, payments, a booking) but is re-runnable.
set -uo pipefail
cd "$(dirname "$0")/.."
export PATH="/opt/homebrew/opt/mysql@8.4/bin:$PATH"

API="${API:-http://127.0.0.1:8080}"; V1="$API/api/v1"
DB_NAME="${SMOKE_DB:-r007_local}"
TMP="$(mktemp -d)"; trap 'kill $(jobs -p) 2>/dev/null; rm -rf "$TMP"' EXIT
PASS=0; FAIL=0

did() { php -r 'require "vendor/autoload.php"; echo App\Support\Demo\DemoIds::of($argv[1]);' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mok\033[0m   %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
step() { printf '\n== %s\n' "$1"; }
check() { # description, actual, expected
  if [ "$2" = "$3" ]; then ok "$1 ($2)"; else bad "$1: expected '$3' got '$2'"; [ -n "${BODY:-}" ] && printf '       last body: %s\n' "$(echo "$BODY" | head -c 600)"; fi
}
truthy() { if [ "$2" = "true" ]; then ok "$1"; else bad "$1 (got '$2')"; [ -n "${BODY:-}" ] && printf '       last body: %s\n' "$(echo "$BODY" | head -c 600)"; fi; }

# req METHOD PATH TOKEN [JSON] [extra curl -H args...]   -> sets CODE BODY ETAG
req() {
  local method="$1" path="$2" tok="$3" body="${4:-}"; shift 4 2>/dev/null || shift $#
  local args=(-sS -o "$TMP/body" -D "$TMP/hdr" -w '%{http_code}' -X "$method" "$V1$path" -H 'Accept: application/json' -H "Idempotency-Key: smoke-$(date +%s%N)-$RANDOM")
  [ -n "$tok" ] && args+=(-H "Authorization: Bearer $tok")
  [ -n "${DEVTOKEN:-}" ] && args+=(-H "X-Device-Token: $DEVTOKEN")
  [ -n "$body" ] && args+=(-H 'Content-Type: application/json' -d "$body")
  args+=("$@")
  CODE="$(curl "${args[@]}")"; BODY="$(cat "$TMP/body")"; ETAG="$(grep -i '^etag:' "$TMP/hdr" | tail -1 | sed 's/^[^:]*: *//' | tr -d '\r')"
}
j() { echo "$BODY" | jq -r "$1"; }
login() { DEVTOKEN="${2:-}" req POST /auth/staff/login "" "{\"credentialType\":\"PIN\",\"identifier\":\"$1\",\"secret\":\"1234\"}"; [ "$CODE" = 200 ] || { bad "login $1 -> $CODE"; echo "$BODY"; exit 1; }; j .accessToken; }
events() { jq -r "select(.event==\"$2\") | .event" "$TMP/$1.jsonl" 2>/dev/null | wc -l | tr -d ' '; }
wait_event() { # file event [secs]
  for _ in $(seq 1 $((${3:-8}*4))); do [ "$(events "$1" "$2")" -gt 0 ] && return 0; sleep 0.25; done; return 1; }

RST="$(did facility:RESTAURANT)"; RECEPTION="$(did facility:RECEPTION)"; ARENA="$(did facility:SPORTS_ARENA)"; STORE="$(did facility:SPORTS_STORE)"
JOLLOF="$(did product:FD-JOL-CH)"; STAR="$(did product:BR-STAR)"
KITCHEN_ST="$(did op:MAIN_KITCHEN:MAIN_KITCHEN)"; BAR_ST="$(did op:RESTAURANT:RESTAURANT_COUNTER)"
WAITER_DEV="$(did device:TABLET_WAITER_01)"; WAITER_TOK=r7d_dev_tablet_waiter_01
SUP_DEV="$(did device:TABLET_SUPERVISOR_1)"; SUP_TOK=r7d_dev_tablet_supervisor_1
KDS_TOK=r7d_dev_kds_main_kitchen; POS_TOK=r7d_dev_pos_restaurant
RECEPTION_TOK=r7d_dev_tablet_waiter_02; ENTRANCE_DEV="$(did device:TABLET_SPORTS_ENTRANCE)"; ENTRANCE_TOK=r7d_dev_tablet_sports_entrance
STORE_DEV="$(did device:TABLET_SPORTS_STORE)"; STORE_TOK=r7d_dev_tablet_sports_store
RECEPTION_DEV="$(did device:TABLET_WAITER_02)"

step "1. System info + device token"
DEVTOKEN= req GET /system/info ""; check "GET /system/info" "$CODE" 200
check "deploymentMode" "$(j .deploymentMode)" local
REALTIME_PORT="$(j .realtime.port)"; check "realtime port advertised" "$REALTIME_PORT" 8081
DEVTOKEN="$WAITER_TOK" req GET "/devices/$WAITER_DEV" ""; check "device token accepted: GET /devices/{id}" "$CODE" 200
check "device mode (explicit)" "$(j .mode)" ATTENDANT
DEVTOKEN="$SUP_TOK" req GET "/devices/$SUP_DEV" ""; check "supervisor tablet mode" "$(j .mode)" SUPERVISOR
DEVTOKEN="$STORE_TOK" req GET "/devices/$STORE_DEV" ""; check "sports store tablet mode" "$(j .mode)" SPORTS_STORE
DEVTOKEN="$ENTRANCE_TOK" req GET "/devices/$ENTRANCE_DEV" ""; check "sports entrance tablet mode" "$(j .mode)" SPORTS_ENTRANCE

step "2. PIN login (attendant on waiter tablet 01) + tablet checkout"
WAIT="$(login wait1 $WAITER_TOK)"; [ -n "$WAIT" ] && ok "wait1 logged in"
DEVTOKEN="$WAITER_TOK"; WAIT_ID="$(did staff:wait1)"
req POST "/devices/$WAITER_DEV/checkout" "$WAIT" "{\"staffId\":\"$WAIT_ID\",\"facilityId\":\"$RST\"}"
if [ "$CODE" = 409 ]; then req POST "/devices/$WAITER_DEV/checkin" "$WAIT" '{}'; req POST "/devices/$WAITER_DEV/checkout" "$WAIT" "{\"staffId\":\"$WAIT_ID\",\"facilityId\":\"$RST\"}"; fi
check "POST /devices/{id}/checkout" "$CODE" 200
check "checkout facility" "$(j .checkout.facilityId // .facilityId)" "$RST"
# a manager checks the supervisor tablet out at the Restaurant so approvals can reach it
MGR="$(login manager1)"; DEVTOKEN=
req POST "/devices/$SUP_DEV/checkout" "$MGR" "{\"staffId\":\"$(did staff:supervisor1)\",\"facilityId\":\"$RST\"}"
if [ "$CODE" = 409 ]; then req POST "/devices/$SUP_DEV/checkin" "$MGR" '{}'; req POST "/devices/$SUP_DEV/checkout" "$MGR" "{\"staffId\":\"$(did staff:supervisor1)\",\"facilityId\":\"$RST\"}"; fi
check "supervisor tablet checked out" "$CODE" 200

step "3. Cashier session, facility capabilities, menu, table map"
CASH2="$(login cashier2)"; DEVTOKEN=
req GET "/cash-sessions?facilityId=$RST&status=OPEN" "$CASH2"
OPEN_SESSION="$(j '.items[0].id // empty')"
if [ -z "$OPEN_SESSION" ]; then req POST /cash-sessions "$CASH2" "{\"facilityId\":\"$RST\",\"openingFloat\":\"5000.0000\"}"; check "open cash session" "$CODE" 201; OPEN_SESSION="$(j .id)"; else ok "reusing open cash session"; fi
DEVTOKEN="$WAITER_TOK"; req GET "/facilities/$RST/capabilities" "$WAIT"; check "capabilities" "$CODE" 200
req GET "/catalog/products?facilityId=$RST&limit=200" "$WAIT"; check "menu" "$CODE" 200; truthy "menu has jollof + beer" "$(j "[.items[].sku] | (index(\"FD-JOL-CH\") != null) and (index(\"BR-STAR\") != null)")"
req GET "/tables?facilityId=$RST&limit=200" "$WAIT"; check "tables" "$CODE" 200
TABLE="$(j '[.items[] | select(.status=="AVAILABLE" or .status=="FREE")][0].id // .items[0].id')"; TABLE_LABEL="$(j "[.items[] | select(.id==\"$TABLE\")][0].label")"
echo "     using table $TABLE_LABEL"
OWNER="$(login owner1)"; DEVTOKEN=
stock() { # item name, location name -> quantity (decimal string)
  req GET "/inventory/balances?limit=1000" "$OWNER"
  local loc; loc="$(curl -s "$V1/inventory/locations?limit=100" -H "Authorization: Bearer $OWNER" | jq -r "[.items[] | select(.name==\"$2\")][0].id")"
  echo "$BODY" | jq -r "[.items[] | select(.itemName==\"$1\" and .locationId==\"$loc\")][0].quantity"
}
BEER_BEFORE="$(stock 'Star Lager Beer 60cl' 'Restaurant Store')"; echo "     stock Star Lager @ Restaurant Store before: $BEER_BEFORE"

step "4. Realtime listeners (Reverb): kitchen station, restaurant orders, waiter device"
KDS_USER="$(login kitchen1 $KDS_TOK)"
node scripts/ws-listen.mjs --api "$API" --token "$KDS_USER" --device "$KDS_TOK" --out "$TMP/kds.jsonl" --channels "private-kds.station.$KITCHEN_ST" --ttl 300 & 
node scripts/ws-listen.mjs --api "$API" --token "$WAIT" --device "$WAITER_TOK" --out "$TMP/waiter.jsonl" --channels "private-facility.$RST.orders,private-device.$WAITER_DEV" --ttl 300 &
node scripts/ws-listen.mjs --api "$API" --token "$(login supervisor1 $SUP_TOK)" --device "$SUP_TOK" --out "$TMP/sup.jsonl" --channels "private-device.$SUP_DEV" --ttl 300 &
sleep 2
check "kds listener subscribed" "$(events kds __subscribed)" 1
check "waiter listener subscribed (orders + device)" "$(events waiter __subscribed)" 2
check "supervisor listener subscribed" "$(events sup __subscribed)" 1

step "5. Open table, order, send"
DEVTOKEN="$WAITER_TOK"
req POST "/tables/$TABLE/open" "$WAIT" '{}'; check "POST /tables/{id}/open" "$CODE" 200
req POST /orders "$WAIT" "{\"facilityId\":\"$RST\",\"tableId\":\"$TABLE\",\"channel\":\"DINE_IN\",\"lines\":[{\"productId\":\"$JOLLOF\",\"quantity\":2,\"notes\":\"no pepper\"},{\"productId\":\"$STAR\",\"quantity\":2}]}"
check "POST /orders" "$CODE" 201; ORDER="$(j .id)"; ORDER_TOTAL="$(j .total)"; check "order status" "$(j .status)" DRAFT
check "order total = 2x4500 + 2x1500" "$ORDER_TOTAL" "12000.0000"
req POST "/orders/$ORDER/send" "$WAIT" '{}' -H "If-Match: $ETAG"; check "POST /orders/{id}/send" "$CODE" 200; check "order SENT" "$(j .status)" SENT
BEER_AFTER_SEND="$(stock 'Star Lager Beer 60cl' 'Restaurant Store')"
check "stock decremented by 2 on send" "$(echo "$BEER_BEFORE - $BEER_AFTER_SEND" | bc)" 2.0000
wait_event waiter order.updated && ok "Reverb: order.updated on facility orders channel" || bad "no order.updated event"
wait_event kds prep-ticket.created && ok "Reverb: prep-ticket.created on kitchen station" || bad "no prep-ticket.created event"

step "6. Kitchen accepts -> in progress -> ready; bar ticket by supervisor; serve"
KDS="$(login kitchen1 $KDS_TOK)"; DEVTOKEN="$KDS_TOK"
req GET "/kds/stations?facilityId=$RST" "$KDS"; check "GET /kds/stations" "$CODE" 200
req GET "/kds/stations/$KITCHEN_ST/tickets" "$KDS"; check "GET station tickets" "$CODE" 200
TICKET="$(j "[.items[] | select(.orderId==\"$ORDER\")][0].id")"; [ -n "$TICKET" ] && [ "$TICKET" != null ] && ok "kitchen ticket for the order ($TICKET)" || bad "no kitchen ticket"
req GET "/prep-tickets/$TICKET" "$KDS"
for to in ACCEPTED IN_PROGRESS READY; do
  req POST "/prep-tickets/$TICKET/transition" "$KDS" "{\"to\":\"$to\"}" -H "If-Match: $ETAG"; check "ticket -> $to" "$CODE" 200
done
wait_event kds prep-ticket.updated && ok "Reverb: prep-ticket.updated" || bad "no prep-ticket.updated"
SUPV="$(login supervisor1)"; DEVTOKEN=
req GET "/kds/stations/$BAR_ST/tickets" "$SUPV"; BTICKET="$(j "[.items[] | select(.orderId==\"$ORDER\")][0].id")"
req GET "/prep-tickets/$BTICKET" "$SUPV"
for to in IN_PROGRESS READY; do req POST "/prep-tickets/$BTICKET/transition" "$SUPV" "{\"to\":\"$to\"}" -H "If-Match: $ETAG"; check "bar ticket -> $to" "$CODE" 200; done
wait_event waiter order.ready && ok "Reverb: order.ready reached the attendant" || bad "no order.ready"
DEVTOKEN="$WAITER_TOK"; req GET "/orders/$ORDER" "$WAIT"; check "order READY" "$(j .status)" READY
req POST "/orders/$ORDER/serve" "$WAIT" '{}' -H "If-Match: $ETAG"; check "POST /orders/{id}/serve" "$CODE" 200; check "order SERVED" "$(j .status)" SERVED

step "7. Second order: void requires supervisor approval"
TABLE2="$(curl -s "$V1/tables?facilityId=$RST&limit=200" -H "Authorization: Bearer $WAIT" -H "X-Device-Token: $WAITER_TOK" | jq -r '[.items[] | select(.status=="FREE")][1].id')"
req POST "/tables/$TABLE2/open" "$WAIT" '{}'
req POST /orders "$WAIT" "{\"facilityId\":\"$RST\",\"tableId\":\"$TABLE2\",\"lines\":[{\"productId\":\"$STAR\",\"quantity\":3}]}"; ORDER2="$(j .id)"; check "order 2 created" "$CODE" 201
req POST "/orders/$ORDER2/send" "$WAIT" '{}' -H "If-Match: $ETAG"; check "order 2 sent" "$CODE" 200
req POST "/orders/$ORDER2/void" "$WAIT" '{"reason":"Guest changed mind"}' -H "If-Match: $ETAG"
check "void by attendant -> 202 pending approval" "$CODE" 202; APPROVAL="$(j .approval.id)"
wait_event sup approval.requested && ok "Reverb: approval.requested reached the supervisor tablet" || bad "no approval.requested"
DEVTOKEN=; req GET "/approvals?scope=approvable" "$SUPV"; truthy "approval listed for supervisor" "$(j "[.items[].id] | index(\"$APPROVAL\") != null")"
req POST "/approvals/$APPROVAL/decision" "$SUPV" '{"decision":"APPROVE"}'; check "supervisor approves" "$CODE" 200
DEVTOKEN="$WAITER_TOK"; req GET "/orders/$ORDER2" "$WAIT"; check "order 2 VOIDED" "$(j .status)" VOIDED
wait_event waiter approval.decided && ok "Reverb: approval.decided reached the attendant device" || bad "no approval.decided"
BEER_AFTER_VOID="$(stock 'Star Lager Beer 60cl' 'Restaurant Store')"
check "voided order's stock restored (net -2 overall)" "$(echo "$BEER_BEFORE - $BEER_AFTER_VOID" | bc)" 2.0000

step "8. Tab, settle with split cash + transfer, receipt"
DEVTOKEN="$WAITER_TOK"
req POST /tabs "$WAIT" "{\"facilityId\":\"$RST\",\"tableId\":\"$TABLE\"}"; check "POST /tabs" "$CODE" 201; TAB="$(j .id)"
req POST "/tabs/$TAB/orders" "$WAIT" "{\"orderIds\":[\"$ORDER\"]}" -H "If-Match: $ETAG"; check "attach order to tab" "$CODE" 200
DEVTOKEN="$POS_TOK"; CASH="$(login cashier2 $POS_TOK)"
req POST "/tabs/$TAB/settle" "$CASH" "{\"cashSessionId\":\"$OPEN_SESSION\",\"tenders\":[{\"tenderType\":\"CASH\",\"amount\":\"7000.0000\",\"tendered\":\"10000.0000\"},{\"tenderType\":\"TRANSFER\",\"amount\":\"5000.0000\",\"reference\":\"SMOKE-TRF-$RANDOM\"}]}"
check "POST /tabs/{id}/settle (cash 7000 + transfer 5000)" "$CODE" 200
check "payments recorded" "$(j '.payments | length')" 2; check "change due" "$(j .changeDue)" 3000.0000
RECEIPT="$(j .receiptId)"; [ -n "$RECEIPT" ] && [ "$RECEIPT" != null ] && ok "receiptId returned" || bad "no receiptId"
req GET "/receipts/$RECEIPT" "$CASH"; check "GET /receipts/{id}" "$CODE" 200
truthy "receipt shows both tenders" "$(j '(tostring | contains("CASH")) and (tostring | contains("TRANSFER"))')"
DEVTOKEN="$WAITER_TOK"; req GET "/orders/$ORDER" "$WAIT"; check "order SETTLED" "$(j .status)" SETTLED

step "9. Reception: cash session, availability, hold, order lines, pay, QR"
SUPV_ID="$(did staff:supervisor1)"; STORE_KEEPER_ID="$(did staff:storekeeper1)"
DEVTOKEN="$RECEPTION_TOK"; REC="$(login cashier1 $RECEPTION_TOK)"; REC_ID="$(did staff:cashier1)"
req POST "/devices/$RECEPTION_DEV/checkout" "$REC" "{\"staffId\":\"$REC_ID\",\"facilityId\":\"$RECEPTION\"}"
if [ "$CODE" = 409 ]; then req POST "/devices/$RECEPTION_DEV/checkin" "$REC" '{}'; req POST "/devices/$RECEPTION_DEV/checkout" "$REC" "{\"staffId\":\"$REC_ID\",\"facilityId\":\"$RECEPTION\"}"; fi
check "reception device checkout" "$CODE" 200
req GET "/cash-sessions?facilityId=$RECEPTION&status=OPEN" "$REC"; REC_SESSION="$(j '.items[0].id // empty')"
if [ -z "$REC_SESSION" ]; then req POST /cash-sessions "$REC" "{\"facilityId\":\"$RECEPTION\",\"openingFloat\":\"0.0000\"}"; check "reception cash session" "$CODE" 201; REC_SESSION="$(j .id)"; fi
COURT="$(did resource:LAWN_TENNIS:COURT_1)"
FROM="$(date -u -d '+1 day' +%Y-%m-%dT00:00:00Z 2>/dev/null || date -u -v+1d +%Y-%m-%dT00:00:00Z)"; TO="$(date -u -d '+2 day' +%Y-%m-%dT00:00:00Z 2>/dev/null || date -u -v+2d +%Y-%m-%dT00:00:00Z)"
req GET "/bookings/resources/$COURT/availability?from=$FROM&to=$TO" "$REC"; check "availability" "$CODE" 200
SLOT_START="$(j '[.slots[] | select(.available)][0].start')"; SLOT_END="$(j '[.slots[] | select(.available)][0].end')"; echo "     slot $SLOT_START - $SLOT_END"
req POST /bookings/hold "$REC" "{\"resourceId\":\"$COURT\",\"start\":\"$SLOT_START\",\"end\":\"$SLOT_END\",\"customer\":{\"name\":\"Chinedu Eze\"}}"
check "POST /bookings/hold" "$CODE" 201; BOOKING="$(j .id)"; check "booking HELD" "$(j .status)" HELD; BK_ETAG="$ETAG"
truthy "hold has expiry" "$(j '.holdExpiresAt != null')"
# a second hold on the same slot loses the race
req POST /bookings/hold "$REC" "{\"resourceId\":\"$COURT\",\"start\":\"$SLOT_START\",\"end\":\"$SLOT_END\"}"; check "second hold on the same slot -> 409" "$CODE" 409; check "code" "$(j .code)" slot_unavailable
req GET "/catalog/products?facilityId=$RECEPTION&limit=200" "$REC"; check "reception catalog" "$CODE" 200
sku_id() { echo "$BODY" | jq -r "[.items[] | select(.sku==\"$1\")][0].id"; }
FEE="$(sku_id FEE-TENNIS)"; RACKET="$(sku_id RENTAL-RACKET)"; BALLS="$(sku_id GOODS-TENNIS-BALLS)"
[ "$FEE" != null ] && [ "$RACKET" != null ] && [ "$BALLS" != null ] && ok "reception sells court fee, racket hire and balls" || bad "reception catalog is missing court fee / rental / balls (fee=$FEE racket=$RACKET balls=$BALLS)"
req POST /orders "$REC" "{\"facilityId\":\"$RECEPTION\",\"channel\":\"COUNTER\",\"customerName\":\"Chinedu Eze\",\"lines\":[{\"productId\":\"$FEE\",\"quantity\":1},{\"productId\":\"$RACKET\",\"quantity\":2},{\"productId\":\"$BALLS\",\"quantity\":1}]}"
check "POST /orders (slot fee + rental + store item)" "$CODE" 201; RORDER="$(j .id)"; RTOTAL="$(j .total)"; echo "     order total $RTOTAL"
req POST "/bookings/$BOOKING/order" "$REC" "{\"orderId\":\"$RORDER\"}" -H "If-Match: $BK_ETAG"; check "attach order to the hold" "$CODE" 200; check "booking PENDING_PAYMENT" "$(j .status)" PENDING_PAYMENT; BK_ETAG="$ETAG"
req POST "/bookings/$BOOKING/confirm" "$REC" "{\"cashSessionId\":\"$REC_SESSION\",\"tenders\":[{\"tenderType\":\"CASH\",\"amount\":\"$RTOTAL\",\"tendered\":\"$RTOTAL\"}]}" -H "If-Match: $BK_ETAG"
check "POST /bookings/{id}/confirm (cash)" "$CODE" 200; check "booking CONFIRMED" "$(j .status)" CONFIRMED; ENT="$(j .entitlementId)"
[ -n "$ENT" ] && [ "$ENT" != null ] && ok "entitlement issued ($ENT)" || bad "no entitlementId"
req GET "/entitlements/$ENT" "$REC"; check "GET /entitlements/{id}" "$CODE" 200; QR="$(j .qrToken)"; truthy "QR token present" "$(j '.qrToken | startswith("R7.")')"
check "entitlement items (ACCESS, RENTAL, ITEM)" "$(j '[.items[].kind] | join(",")')" "ACCESS,RENTAL,ITEM"

step "10. Sports Entrance: QR redeem (VALID then USED)"
MGR2="$(login manager1)"; DEVTOKEN=
req POST "/devices/$ENTRANCE_DEV/checkout" "$MGR2" "{\"staffId\":\"$SUPV_ID\",\"facilityId\":\"$ARENA\"}"
if [ "$CODE" = 409 ]; then req POST "/devices/$ENTRANCE_DEV/checkin" "$MGR2" '{}'; req POST "/devices/$ENTRANCE_DEV/checkout" "$MGR2" "{\"staffId\":\"$SUPV_ID\",\"facilityId\":\"$ARENA\"}"; fi
check "entrance tablet checkout" "$CODE" 200
GATE="$(login supervisor1 $ENTRANCE_TOK)"; DEVTOKEN="$ENTRANCE_TOK"
req POST "/entitlement-tokens/$QR/redeem" "$GATE" '{"action":"ENTRY"}'; check "redeem HTTP" "$CODE" 200
if [ "$(j .result)" = NOT_YET_VALID ]; then
  # The booked slot is in the future (courts are bookable a day ahead / outside opening hours). Move THIS entitlement's validity window
  # to "now" in the dev DB so the gate flow can be exercised at any time of day.
  echo "     (info) slot not open yet - shifting this entitlement's validity window to now (dev DB $DB_NAME)"
  mysql -h "${DB_HOST:-127.0.0.1}" -u "${SMOKE_DB_USER:-root}" ${SMOKE_DB_PASS:+-p"$SMOKE_DB_PASS"} "$DB_NAME" -e "UPDATE entitlement_item SET valid_from = UTC_TIMESTAMP(6) - INTERVAL 1 HOUR, valid_until = UTC_TIMESTAMP(6) + INTERVAL 2 HOUR WHERE entitlement_id = UNHEX(REPLACE('$ENT','-',''))" \
    || bad "could not shift validity window (set SMOKE_DB / mysql on PATH)"
  req POST "/entitlement-tokens/$QR/redeem" "$GATE" '{"action":"ENTRY"}'
fi
check "first scan" "$(j .result)" VALID
req POST "/entitlement-tokens/$QR/redeem" "$GATE" '{"action":"ENTRY"}'; check "redeem HTTP (replay)" "$CODE" 200; check "second scan" "$(j .result)" USED

step "11. Sports Store: release and return rental"
DEVTOKEN=; req POST "/devices/$STORE_DEV/checkout" "$MGR2" "{\"staffId\":\"$STORE_KEEPER_ID\",\"facilityId\":\"$STORE\"}"
if [ "$CODE" = 409 ]; then req POST "/devices/$STORE_DEV/checkin" "$MGR2" '{}'; req POST "/devices/$STORE_DEV/checkout" "$MGR2" "{\"staffId\":\"$STORE_KEEPER_ID\",\"facilityId\":\"$STORE\"}"; fi
check "store tablet checkout" "$CODE" 200
SK="$(login storekeeper1 $STORE_TOK)"; DEVTOKEN="$STORE_TOK"
req GET "/entitlement-tokens/$QR" "$SK"; check "store scans QR (lookup)" "$CODE" 200
RENTAL_ITEM="$(j '[.items[] | select(.kind=="RENTAL")][0].id')"
req POST "/entitlements/$ENT/release" "$SK" "{\"itemIds\":[\"$RENTAL_ITEM\"]}"; check "POST release" "$CODE" 200
check "rental RELEASED" "$(j "[.items[] | select(.id==\"$RENTAL_ITEM\")][0].rentalStatus")" RELEASED
req POST "/entitlements/$ENT/return" "$SK" "{\"itemIds\":[\"$RENTAL_ITEM\"],\"condition\":\"OK\"}"; check "POST return" "$CODE" 200
check "rental RETURNED" "$(j "[.items[] | select(.id==\"$RENTAL_ITEM\")][0].rentalStatus")" RETURNED

step "12. Inventory reconciles, outbox events, audit chain"
DEVTOKEN=
BEER_FINAL="$(stock 'Star Lager Beer 60cl' 'Restaurant Store')"
check "beer stock: exactly 2 bottles consumed by the run (send 2 + send 3 - void 3)" "$(echo "$BEER_BEFORE - $BEER_FINAL" | bc)" 2.0000
RECON="$(DB_DATABASE="$DB_NAME" REDIS_DB=4 REDIS_CACHE_DB=5 REDIS_PREFIX=r007_local_ php artisan r007:inventory:reconcile 2>&1)"; RC=$?
check "r007:inventory:reconcile exit code (balance == SUM(movements))" "$RC" 0
req GET "/sync/outbox?limit=200" "$OWNER"; check "GET /sync/outbox" "$CODE" 200
for ev in OrderCreated OrderVoided OrderSettled PaymentCompleted TabSettled StockConsumed BookingConfirmedLocally EntitlementIssued TicketRedeemed RentalReleased RentalReturned; do
  n="$(echo "$BODY" | jq "[.items[] | select((.eventType // .type)==\"$ev\")] | length")"; [ "${n:-0}" -gt 0 ] && ok "outbox holds $ev (x$n in the latest 200 rows)" || bad "no $ev event in the latest 200 outbox rows"
done
truthy "outbox non-empty" "$(j '(.items | length) > 0')"
req GET /audit/verify "$OWNER"; check "GET /audit/verify" "$CODE" 200; truthy "audit hash chain verifies" "$(j '.valid')"

printf '\n==== smoke result: %d passed, %d failed ====\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
