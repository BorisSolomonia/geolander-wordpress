# Geolander reservation confirmation email

The reservation confirmation system is already built and deployed. The remaining production task is to connect an email provider so WordPress can send messages automatically.

## Reservation workflow

1. The customer selects the exact car, rental dates, pickup location and return location.
2. The customer must enter their name and a valid email address before the site can open the prepared WhatsApp reservation message.
3. WordPress saves the request under **WordPress Admin → Booking Requests**.
4. When SMTP is configured, the customer receives a **request received — not yet confirmed** email.
5. Staff checks vehicle availability and the 10% prepayment.
6. Staff opens the booking and selects **Send confirmation now**.
7. The customer receives the final confirmation with the exact vehicle, dates, locations, rental charges, total, prepayment, remaining balance, insurance, deposit, mileage, tyre coverage and cancellation policy.

The initial request must not be described as a confirmed reservation. Confirmation is sent only after availability and the 10% prepayment have been verified.

## 1. Configure incoming email with Cloudflare

In Cloudflare:

1. Open **Email → Email Routing**.
2. Add and verify an existing Gmail or other business mailbox.
3. Create this forwarding rule:

```text
info@geo-lander.com → your personal or business inbox
```

Cloudflare configures the receiving records and forwards incoming messages. It does not provide the outbound SMTP service used by WordPress.

Official instructions: [Cloudflare Email Routing](https://developers.cloudflare.com/email-service/get-started/route-emails/)

## 2. Configure Brevo for outgoing transactional email

1. Create a Brevo account.
2. Add `geo-lander.com` under **Settings → Senders, Domains & Dedicated IPs → Domains**.
3. Authenticate the domain.
4. Add the DKIM and DMARC records supplied by Brevo to Cloudflare DNS.
5. Create the sender **Geolander `<info@geo-lander.com>`**.
6. Create an **SMTP key**, not an API key.

Official instructions:

- [Authenticate a domain with Brevo](https://help.brevo.com/hc/en-us/articles/12163873383186-Authenticate-your-domain-with-Brevo-Brevo-code-DKIM-DMARC)
- [Send transactional emails through Brevo SMTP](https://help.brevo.com/hc/en-us/articles/7924908994450-Send-transactional-emails-using-Brevo-SMTP)

## 3. Add Railway environment variables

Add these variables to the WordPress service in Railway:

```text
GLC_SMTP_HOST=smtp-relay.brevo.com
GLC_SMTP_PORT=587
GLC_SMTP_USERNAME=<Brevo SMTP login>
GLC_SMTP_PASSWORD=<Brevo SMTP key>
GLC_SMTP_ENCRYPTION=tls
GLC_SMTP_FROM=info@geo-lander.com
```

Do not commit the SMTP password to Git or paste it in public messages. Store it only as a Railway environment variable. Railway should redeploy the service after the variables change.

## 4. Test the complete workflow

1. Submit a reservation using an email address you control.
2. Confirm that the request-received email arrives.
3. Open **WordPress Admin → Booking Requests**.
4. Open the test booking and verify every generated detail and price.
5. Select **Send confirmation now**.
6. Confirm that the final confirmation arrives and is not classified as spam.
7. Reply to the confirmation and confirm that Cloudflare forwards the reply to the configured mailbox.
8. Check the Brevo transactional logs for successful delivery.

Until SMTP is configured, **Open email draft** remains available in the booking administration screen and creates a prepared message in the computer's email application.
