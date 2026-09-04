# Njiwa for Shopware 6

WhatsApp your customers when their order is paid, shipped, cancelled or
refunded, and get a message yourself when one comes in.

## Install

```
composer require upeo/njiwa-shopware
bin/console plugin:refresh
bin/console plugin:install --activate UpeoNjiwa
bin/console cache:clear
bin/build-administration.sh
```

Or copy this folder to `custom/plugins/UpeoNjiwa` and run the same commands.

The last one rebuilds the administration so that **Settings → Njiwa WhatsApp**
appears. Skip it and everything still sends; you just have no buttons to check
the setup with, and have to use the API calls further down instead.

Shopware 6.5 or 6.6, PHP 8.1 or newer.

**You need a message queue worker running.** Every message is handed to
Shopware's queue so that nothing is sent while a customer is standing at the
checkout. If nothing consumes that queue, nothing is ever sent. Most shops
already have `bin/console messenger:consume` running under a supervisor, or the
admin worker turned on; if yours does not, that is the one thing to fix before
turning anything on here.

## Set it up

**Extensions → My extensions → Njiwa WhatsApp → ⋮ → Configure.**

Paste your API key from [console.upeo.ai](https://console.upeo.ai) → API keys
and save. Then tick the events you want and edit the wording. Every field on
that page explains itself; the short version:

| Setting | What it is for |
| --- | --- |
| Send WhatsApp messages | The master switch. Off keeps every setting and sends nothing. |
| API key | `sk_test_` delivers nothing, `sk_live_` sends for real. |
| Njiwa address | Leave it alone unless you were given your own. |
| Send from | Which of your numbers sends. Empty means the account default. |
| Each event | On, off, and the exact wording. Empty wording sends nothing. |
| Your WhatsApp numbers | Where the new-order alert goes. Several, comma separated. |

Every setting can be set per sales channel using the picker at the top of the
page, so a shop that sells under two names can say two different things.

**Start with a test key.** A key beginning `sk_test_` checks and stores every
message and delivers nothing. Turn on the events you want, place a test order,
read the shop's log in `var/log` for lines from the `njiwa` channel, and only
then swap in the `sk_live_` key. A `sk_live_` key sends real WhatsApp messages
to real phones and costs real money.

## Check it works

In the administration, go to **Settings → Njiwa WhatsApp**. Leave the sales
channel empty to check the shop-wide settings, or pick one to check what that
channel has saved. There are two buttons, and whatever comes back — including a
refusal and the reason for it — is printed on the page.

**Test connection** lists the WhatsApp numbers your Njiwa account really has,
their state, and which is default, so you find out now rather than at the
moment a customer should have been messaged. It sends nothing.

**Send test message** sends one fixed message to one number you name. The
wording is fixed in the code: you supply the recipient and nothing else. On a
live key that is a real message that costs real money, so it is limited to five
a minute.

Both buttons need the same permission as changing the settings they are
checking, and both are the endpoints below, so nothing is checked by the screen
that is not checked by the shop.

### The same two checks, without the administration

Useful when you are setting a shop up over SSH. Get a token the way you would
for any other Shopware admin API call:

```
TOKEN=$(curl -s https://your-shop.example/api/oauth/token \
  -H 'Content-Type: application/json' \
  -d '{"grant_type":"password","client_id":"administration","username":"admin","password":"your-password"}' \
  | python3 -c 'import json,sys; print(json.load(sys.stdin)["access_token"])')
```

```
curl -X POST https://your-shop.example/api/_action/upeo-njiwa/test-connection \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{}'
```

```
curl -X POST https://your-shop.example/api/_action/upeo-njiwa/send-test-message \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"to": "254712345678"}'
```

Both accept a `salesChannelId` if you have configured a channel separately.
Both are POST only.

## What gets sent, and when

| When | Who hears about it |
| --- | --- |
| The order is placed | The customer: we have your order, waiting for payment |
| A payment moves to **paid** | The customer: payment received, getting it ready |
| A delivery moves to **shipped** | The customer: it is on its way |
| The order moves to **cancelled** | The customer: it has been cancelled |
| A payment moves to **refunded** | The customer: the money is coming back |
| The order is placed | You: a new order came in |

Each one is off until you turn it on. Installing this plugin sends nothing.

Partly paid and partly shipped are deliberately not on that list. Telling
somebody their order has shipped when half of it has is a customer waiting at
the door.

The alert to you is sent **once per order**, when the order is placed. In
Shopware that is the first moment an order is real: what exists before it is a
cart, and a cart is somebody who reached the payment page and may never come
back.

## The wording

Plain text with placeholders in braces. The settings page lists them all; they
are `{first_name}`, `{last_name}`, `{customer_name}`, `{order_number}`,
`{order_total}`, `{order_date}`, `{order_status}`, `{payment_method}`,
`{items}`, `{item_count}`, `{shop_name}`, `{order_url}` and `{admin_url}`.

A placeholder that does not exist, `{order_no}` say, is removed before sending
rather than posted to a customer, and a line in the log says where to look.

`{order_url}` is the customer's own view of their order — the same address
Shopware's order confirmation email links to, so a guest is not asked to log
in. `{admin_url}` opens the order in the administration and is built from your
`APP_URL`; if you have moved the administration off `/admin`, take it out of
your wording rather than trust it.

Clearing a wording box stops that one message without turning the event off.

## Things worth knowing

**The checkout never waits.** Every message is put on Shopware's own message
queue and sent by a worker. A slow network, or Njiwa being down, cannot delay
an order, and cannot break one.

**Nothing is sent twice.** Before anything is sent, a row is claimed in
`upeo_njiwa_message` keyed on the order, the event and the recipient. A second
attempt collides with it and stops, whether it comes a second later from
another worker or a week later because somebody moved a delivery back and forth
to correct a tracking code. Each message also carries an idempotency key, which
Njiwa honours for twenty-four hours, so a retry inside a day replays the first
answer instead of messaging the customer again.

**A failure is written down, not thrown.** Shopware has no order note stream to
write to, so the record lives in two places: the shop's own log in `var/log`,
under the `njiwa` channel, and the `upeo_njiwa_message` table, which keeps the
recipient, Njiwa's own message id and the reason for anything that failed. That
table is the one that survives log rotation, and it is what to look at when
somebody asks whether a customer was ever told.

**Failures that are worth retrying are retried.** A network error or a 429 is
Njiwa saying "not now": the message was never accepted, so the claim is handed
back and the queue tries again. A refusal — a bad key, a number that is not on
WhatsApp — is written down and left alone, because repeating it only fills the
queue with work that will fail the same way.

**A customer with no phone number is normal.** Nothing is sent, and nothing is
complained about.

**Group addresses are refused.** A value ending `@g.us` is a WhatsApp group, and
Njiwa will post to it. One saved settings page could otherwise message hundreds
of people from your own number, so anything that is not a phone number is
dropped.

**Phone numbers keep their leading zero.** `0712345678` is passed on as typed
and Njiwa reads it against your own sending number's country, which is the same
phone. The one place a leading zero is wrong is **Send from**, where there is no
other number to read it against; that one must be in full international form.

**Your API key is stored like every other setting.** Shopware keeps plugin
configuration in the `system_config` table and does not encrypt it, so treat a
database dump as something that contains this key. If one gets out, replace the
key in the Njiwa console rather than changing it here.

**Uninstalling with "keep user data" ticked keeps the record** of what was sent.
A clean uninstall drops the table and the settings.

## Running the tests

```
composer install
vendor/bin/phpunit
```

Or, from the root of a shop that already has this plugin in `custom/plugins`:

```
vendor/bin/phpunit -c custom/plugins/UpeoNjiwa/phpunit.xml.dist
```

They cover the wording and the phone numbers, need no Shopware and no database,
and include a check that the default wording in the settings form and the
default wording in the code have not drifted apart.

## What it does not do

**It does not receive replies.** Inbound WhatsApp and delivery receipts arrive
as webhooks, and verifying one needs the signing secret for that number, which
the console does not yet show. Until it does, a receiving feature could not
check that a request really came from Njiwa, so there is not one.

**It does not keep its own copy of the messages.** Njiwa already stores every
message, its status and its failure reason. What is kept here is the fact that
one was asked for, which is what stops it being asked for twice.

**It does not run campaigns.** Bulk sending to past customers is what the Njiwa
console is for, on Business plans and above.

---

Docs: https://docs.njiwa.upeo.ai · Console: https://console.upeo.ai
UPEO.AI · hello@upeo.ai · 0116888777 on WhatsApp
