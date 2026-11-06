# CrowdMerch Desk and Review & send (Scott, 7 Nov 2026)

**Desk** (`/desk`, the CrowdMerch home): every open job from CMAU_DEV in one list, each with the one
thing it needs next and that button on the right (`App\Desk\DeskJobs`, `App\Desk\JobState`).

- Inputs: website orders and samples (CM-), quotes (website quote requests and instant quotes, phone
  and staff quotes; wish lists are left out), project and sourcing requests (PR-, status new), and
  open change requests from My Orders (shown on their order).
- Stage counts across the top: To quote, Quote sent, To invoice, Waiting for payment, Proof & purchase
  orders, In production / shipping. Tabs: Needs me (default), Quotes, Orders, Production, Waiting on
  client, Done this week.
- Order: urgent first (need-by within 14 days, or a project request due for a reply today), then jobs
  that need staff, then by need-by date, then oldest first.
- A quote sent 3 or more days ago with no answer moves to Needs me as "Follow up".

**Next step card**: the same `JobState` draws a progress line and a dark "Next step" card at the top of
each website order and quote, with the checks the SaaS has done (alerts, logos needing help, purchase
orders created and sent, supplier freight) and the one button.

**Review & send** (`/emails/{id}/review`): one screen for the quote, invoice and purchase order emails.
The PDF and the email (rendered exactly as it goes out) side by side; the recipient can be changed;
nothing is sent until staff press Send (REPSTACK principle 6). "Email quote" on a quote and "Send to
supplier" on a purchase order now open this screen first.
