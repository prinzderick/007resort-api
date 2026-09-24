<!doctype html>
<html lang="en">
<body style="margin:0;background:#f4f1ea;font-family:Georgia,'Times New Roman',serif;color:#1f2a26;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f1ea;padding:32px 12px;">
<tr><td align="center">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:8px;overflow:hidden;">
<tr><td style="background:#0f3d2e;color:#f4f1ea;padding:24px 32px;font-size:22px;letter-spacing:1px;">007 Resort &amp; Spa</td></tr>
<tr><td style="padding:32px;font-size:16px;line-height:1.55;">
<p style="margin:0 0 16px;">Hello{{ $name ? ' '.$name : '' }},</p>
<p style="margin:0 0 24px;">Thanks for your interest in news and offers from 007 Resort &amp; Spa. Please confirm your email address to finish subscribing.</p>
<p style="margin:0 0 24px;"><a href="{{ $confirmUrl }}" style="display:inline-block;background:#c8963e;color:#1f2a26;text-decoration:none;font-weight:bold;padding:12px 28px;border-radius:4px;">Confirm my subscription</a></p>
<p style="margin:0 0 8px;font-size:14px;color:#5b665f;">If the button does not work, copy this link into your browser:<br><span style="word-break:break-all;">{{ $confirmUrl }}</span></p>
<p style="margin:16px 0 0;font-size:14px;color:#5b665f;">If you did not ask for this, ignore this email and nothing will happen.</p>
</td></tr>
<tr><td style="padding:16px 32px 28px;font-size:12px;color:#7d867f;border-top:1px solid #eee;">
<a href="{{ $unsubscribeUrl }}" style="color:#7d867f;">Unsubscribe</a> at any time.
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
