# Fetching Teltonika (FMB130) Events from Wialon in Real Time — PHP

This guide shows how to use a **Wialon access token** with **PHP** to receive, as they happen, the messages (and the Teltonika I/O parameters inside them) that your FMB130 sends to Wialon.

> **Important:** Wialon's Remote API is request/response only. Wialon's own FAQ says there is no true push over the API. "Real time" therefore means **polling `avl_evts` every ~2 seconds** (Method A). If you need a real push, use a **notification that sends an HTTP request to your server** (Method B).

---

## 1. How the data flows

```
FMB130 --(Teltonika protocol, TCP/UDP)--> Wialon server --(Remote API)--> your PHP script
```

- Wialon stores every record the device sends as a **message**.
- The Teltonika I/O elements from the page you linked are stored in the message's `p` (parameters) object.
- Some are renamed by Wialon (e.g. `pwr_ext` for external voltage, `pwr_int` for battery voltage, `gsm` for signal level, `hdop`). Others appear as **`io_<AVL ID>`**, where the ID is the "Property ID in AVL packet" from the Teltonika table.
- On Wialon Hosting the FMB130 connects to port **21920**, and the unit's *Device type* must be **Teltonika FMB130** with the **IMEI** as unique ID.

---

## 2. Get an access token (one time)

Open this URL in a browser, log in with your Wialon user, and copy `access_token` from the address bar after the redirect:

```
https://hosting.wialon.com/login.html?client_id=MyPhpApp&access_type=-1&activation_time=0&duration=0&flags=0x1
```

Notes from the docs:

- The token is **72 characters**.
- `duration=0` means unlimited. Default is 30 days (2592000 s).
- Tokens not used for **100 days** are deleted automatically.
- Max **1000 active tokens** per user.
- `access_type` sets the token's rights. `-1` (all rights) is convenient for testing. Use narrower rights in production (see the *Tokens* data format page).
- Treat the token like a password. Keep it in an environment variable or config file outside the web root.

For Wialon Local, replace `hosting.wialon.com` with your own monitoring site URL.

---

## 3. Method A — Poll `avl_evts` (pseudo real-time)

### 3.1 The sequence

1. `token/login` with your token. Response contains `eid` (this is your **session ID**, `sid`).
2. `core/update_data_flags` to **subscribe** to the units and choose what you want to receive.
3. Loop: call `avl_evts?sid=...` every ~2 seconds. It returns only what happened **since the previous call**.

### 3.2 Login

```
POST https://hst-api.wialon.com/wialon/ajax.html
svc=token/login
params={"token":"YOUR_72_CHAR_TOKEN"}
```

Use the host for your data centre (`hst-api.wialon.com`, `.us`, `.eu`, `.org`). For Wialon Local use your monitoring site URL.

### 3.3 Subscribe to units

```
svc=core/update_data_flags
sid=YOUR_EID
params={"spec":[{"type":"col","data":[UNIT_ID_1,UNIT_ID_2],"flags":33,"mode":1}]}
```

| Field | Meaning |
|---|---|
| `type` | `id` (single), `col` (list of IDs), or `type` (all items of a type, e.g. `"data":"avl_unit"`) |
| `flags` | `33` = `1` (general properties) + `32` (**subscribe to new messages**) |
| `mode` | `1` = add flags to what is already in the session, `0` = redefine them |

Useful unit flags (decimal):

| Flag | Value | What it does |
|---|---|---|
| General properties | `1` | Name, ID. Recommended together with `32`. |
| Messages | `32` | Subscribes to every new message of the unit. Adds no data to the response by itself. |
| Last message and location | `1024` | Last known position and last message. |
| Sensors | `4096` | Sensor definitions of the unit. |
| Message parameters | `1048576` | Accumulated parameter values (`prms`) with `v`, `ct`, `at`. |

Add flags together. For example `1 + 32 + 1048576 = 1048609`.

> Setting flag `32` alone makes `update_data_flags` return an empty `d` object. This looks like a failed subscription but isn't. Always combine it with `1`.

### 3.4 Read events

```
GET https://hst-api.wialon.com/avl_evts?sid=YOUR_EID
```

Response:

```json
{
  "tm": 1767000000,
  "events": [
    {
      "i": 12345678,        // unit ID
      "t": "m",             // m = message, u = update, d = delete
      "d": {                // for "m": the message itself
        "t": 1767000000,    // message time (UTC)
        "tp": "ud",         // message type: data message
        "pos": {"x": 36.68, "y": -3.37, "s": 42, "c": 90, "sc": 12},
        "p": {              // <-- Teltonika IO parameters
          "pwr_ext": 12.4,
          "gsm": 4,
          "io_239": 1
        }
      }
    }
  ],
  "sensors": []             // calculated sensor values (if sensors are configured)
}
```

The exact content of `d` depends on the message type; see the *Messages* page of the docs. Parameter names in `p` come from your unit, so **log one real message first** and copy the names from it.

### 3.5 Limits and session rules

| Rule | Value |
|---|---|
| Session lifetime without requests | **5 minutes** (the regular `avl_evts` polling keeps it alive) |
| `avl_evts` rate limit | **max 10 requests per 10 seconds** (poll at 1–2 s) |
| Simultaneous API requests | max 10 |
| Message rate per unit | no more than 1 per second |
| Error `1` | Invalid or expired session. Log in again and re-subscribe. |
| Error `1003` | Request limit exceeded. Slow down. |
| `"migrate": 1` with empty `events` | Session is moving servers. Not an error. Keep polling with the same `sid`. |

---

## 4. Complete PHP script (no dependencies, only cURL)

Save as `wialon_listener.php` and run from the command line: `php wialon_listener.php`.

```php
<?php
declare(strict_types=1);

// ---------- CONFIG ----------
const HOST         = 'https://hst-api.wialon.com'; // your Wialon host
const TOKEN        = 'PUT_YOUR_72_CHAR_TOKEN_HERE'; // better: getenv('WIALON_TOKEN')
const UNIT_IDS     = [12345678];                    // Wialon internal unit IDs
const POLL_SECONDS = 2;                             // keep >= 1 (limit: 10 avl_evts / 10 s)
const FLAGS        = 1 | 32;                        // general props + message subscription

// ---------- HELPERS ----------
function http(string $url, ?array $post = null): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ];
    if ($post !== null) {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($post); // x-www-form-urlencoded
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("cURL error: $err");
    }
    curl_close($ch);
    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException("Invalid JSON: $body");
    }
    return $data;
}

function call(string $svc, array $params, ?string $sid = null): array
{
    $post = ['svc' => $svc, 'params' => json_encode($params)];
    if ($sid !== null) {
        $post['sid'] = $sid;
    }
    $res = http(HOST . '/wialon/ajax.html', $post);
    if (isset($res['error']) && $res['error'] !== 0) {
        throw new RuntimeException("Wialon error {$res['error']} on $svc", (int)$res['error']);
    }
    return $res;
}

function login(): string
{
    $res = call('token/login', ['token' => TOKEN]);
    return $res['eid']; // session ID
}

function subscribe(string $sid): array
{
    $res = call('core/update_data_flags', [
        'spec' => [[
            'type'  => 'col',
            'data'  => UNIT_IDS,
            'flags' => FLAGS,
            'mode'  => 1,
        ]],
    ], $sid);

    // map unit id => name (from the general properties flag)
    $names = [];
    foreach ($res as $item) {
        $names[$item['i']] = $item['d']['nm'] ?? (string)$item['i'];
    }
    return $names;
}

// ---------- YOUR EVENT LOGIC ----------
function handleMessage(int $unitId, string $unitName, array $msg): void
{
    $time   = gmdate('Y-m-d H:i:s', $msg['t'] ?? time()) . ' UTC';
    $params = $msg['p'] ?? [];   // Teltonika IO parameters live here
    $pos    = $msg['pos'] ?? null;

    echo "[$time] $unitName ($unitId)\n";
    if ($pos) {
        echo "  pos: {$pos['y']}, {$pos['x']}  speed: " . ($pos['s'] ?? '-') . "\n";
    }
    foreach ($params as $name => $value) {
        echo "  $name = " . (is_scalar($value) ? $value : json_encode($value)) . "\n";
    }

    // Example: react to a specific parameter.
    // Check the real name in your unit's messages first (e.g. io_239, pwr_ext).
    if (isset($params['io_239']) && (int)$params['io_239'] === 1) {
        echo "  >>> Ignition ON detected\n";
        // TODO: save to DB, call webhook, send alert, ...
    }
}

// ---------- MAIN LOOP ----------
$sid   = login();
$names = subscribe($sid);
echo "Listening on " . count($names) . " unit(s)...\n";

while (true) {
    try {
        $res = http(HOST . '/avl_evts?sid=' . urlencode($sid));

        if (isset($res['error'])) {
            // 1 = session invalid/expired -> login again and re-subscribe
            if ((int)$res['error'] === 1) {
                $sid   = login();
                $names = subscribe($sid);
                continue;
            }
            throw new RuntimeException('avl_evts error ' . $res['error']);
        }

        foreach ($res['events'] ?? [] as $ev) {
            if (($ev['t'] ?? '') === 'm') {   // new message from a unit
                $id = (int)$ev['i'];
                handleMessage($id, $names[$id] ?? (string)$id, $ev['d'] ?? []);
            }
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
        sleep(5); // back off, then try again
    }

    sleep(POLL_SECONDS);
}
```

### Finding your unit ID

Wialon API uses **internal item IDs**, not the IMEI. Get them with `core/search_items`:

```
svc=core/search_items
params={"spec":{"itemsType":"avl_unit","propName":"sys_name","propValueMask":"*","sortType":"sys_name"},"force":1,"flags":1,"from":0,"to":0}
```

Each returned item has `id` and `nm` (name).

---

## 5. Getting calculated sensor values (optional)

If you create sensors on the unit (for example "Ignition", "Fuel level") that point to Teltonika parameters:

- **Live:** `avl_evts` returns a `sensors` array for units that received new data messages. The key is the sensor ID, with `value` and `format.value`.
- **On demand:** `unit/calc_last_message` (params: `unitId`, optional `sensors` array, `flags`) returns `{sensorId: value}` from the last message.
- A value of **`-348201.3876`** means "no data / not available".

---

## 6. Method B — True push with a Wialon notification (webhook)

Use this if you want Wialon to call **your server** when an event happens, with no polling.

1. In Wialon: **Notifications → New**.
2. Choose a trigger that matches your event (the notifications guide lists the trigger types, such as sensor value or parameter conditions).
3. Select the unit(s).
4. Action: **Send a request**.
5. Enter your server URL and choose **GET** or **POST**.
6. In the text field write the dynamic part with tags, for example:
   `unit=%UNIT%&speed=%SPEED%`
   For GET it is appended to the URL. For POST it goes in the body.
7. Receive it in PHP:

```php
<?php
// receiver.php
file_put_contents(
    __DIR__ . '/events.log',
    date('c') . ' ' . json_encode($_REQUEST) . ' ' . file_get_contents('php://input') . PHP_EOL,
    FILE_APPEND
);
http_response_code(200);
echo 'ok';
```

Notes:

- Wialon notifications cannot add custom HTTP headers (a feature request on the forum). Protect the endpoint with a secret in the URL or query string, and HTTPS.
- Combine both methods if needed: the webhook tells you *something happened*, and the token API (`messages/load_last`) fetches the full message.

---

## 7. Reading history instead of live data

Use `messages/load_last` (latest N messages) or `messages/load_interval` (a time range).

```
svc=messages/load_interval
params={"itemId":UNIT_ID,"timeFrom":1767000000,"timeTo":1767003600,"flags":1,"flagsMask":65281,"loadCount":100}
```

Use `itemId` (not `unit`) and decimal numbers for `flags` and `flagsMask`. Hex like `0xFF01` causes `WRONG_PARAMS`.

---

## 8. Troubleshooting

| Symptom | Likely cause / fix |
|---|---|
| `error: 1` | Session expired. Call `token/login` again and re-subscribe. |
| `error: 4` (WRONG_PARAMS) | Wrong param names, hex instead of decimal, or malformed JSON. |
| `error: 1003` | Too many requests. Slow your polling (stay under 10 `avl_evts` per 10 s). |
| `update_data_flags` returns `{}` in `d` | You used flag `32` alone. Use `1 \| 32`. |
| No `m` events arrive | Unit isn't sending, wrong unit ID, or subscribed without flag `32`. |
| Parameter name not what you expect | Print the whole `p` object for one message and use those names. |
| Several scripts, same token, session problems | Reuse one session. Don't log in on every request. |

---

## 9. Security checklist

- Never hard-code the token in code that is committed or public.
- Use HTTPS only.
- Run the listener as a CLI process (systemd, supervisor, or cron-restarted), not inside a web request.
- Create a dedicated Wialon user (with only the units you need) and a token for it.

---

## 10. Sources

- Wialon Remote API intro and basic requests: https://help.wialon.com/en/api/user-guide/introduction
- Getting an access token: https://help.wialon.com/en/api/user-guide/getting-an-access-token
- `token/login`: https://help.wialon.com/en/api/user-guide/api-reference/token/login
- `core/update_data_flags`: https://help.wialon.com/en/api/user-guide/api-reference/core/update_data_flags
- `avl_evts`: https://help.wialon.com/en/api/user-guide/api-reference/other-methods/avl_evts
- Unit data flags and message parameters: https://help.wialon.com/en/api/user-guide/data-format/units
- Messages format: https://help.wialon.com/en/api/user-guide/data-format/messages
- `messages/load_interval`, `messages/load_last`: https://help.wialon.com/en/api/user-guide/api-reference/messages/load_interval
- `unit/calc_last_message`: https://help.wialon.com/en/api/user-guide/api-reference/unit/calc_last_message
- Limitations: https://help.wialon.com/en/wialon-hosting/user-guide/requirements-and-limitations/limitations
- SDK FAQ (real-time note): https://help.wialon.com/en/wialon-hosting/expert-articles/sdk/intro-to-sdk-faq
- Notification actions ("Send a request"): https://help.wialon.com/en/wialon-local/2204/user-guide/monitoring-system/notifications/notification-actions
- Teltonika FMB130 in Wialon (parameter names): https://wialon.com/en/gps-hardware/teltonika-fmb130
- Teltonika AVL IDs: https://wiki.teltonika-gps.com/view/FMB130_Teltonika_Data_Sending_Parameters_ID
