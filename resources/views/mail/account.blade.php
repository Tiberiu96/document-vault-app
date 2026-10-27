<!DOCTYPE html>
<html lang="{{ $locale }}" dir="ltr">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __('account_mail.'.$kind.'_subject', ['brand' => $brand]) }}</title>
<style>@media only screen and (max-width:620px){.outer{padding:24px 12px!important}.card-body{padding:30px 24px!important}.mail-title{font-size:30px!important}.mail-button{display:block!important;text-align:center!important}}</style></head>
<body style="margin:0;padding:0;background:#F7F5F0;color:#242724;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.65">
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all">{{ __('account_mail.'.$kind.'_title') }}</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#F7F5F0"><tr><td align="center" class="outer" style="padding:48px 20px">
<table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px">
<tr><td style="padding:0 0 28px;font-family:Georgia,'Times New Roman',serif;font-size:36px;font-weight:bold;letter-spacing:-1px">{{ $brand }}<span style="color:#8B7355">.</span></td></tr>
<tr><td style="border:1px solid #E6E2DA;border-top:4px solid #8B7355;background:#FFFFFF;border-radius:4px">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td class="card-body" style="padding:44px 40px">
<p style="margin:0 0 24px;color:#8B7355;font-size:16px;letter-spacing:1px">{{ __('account_mail.eyebrow') }}</p>
<h1 class="mail-title" style="margin:0 0 28px;font-family:Georgia,'Times New Roman',serif;font-size:38px;font-weight:normal;line-height:1.15;letter-spacing:-0.5px">{{ __('account_mail.'.$kind.'_title') }}</h1>
<p style="margin:0 0 12px;font-size:16px">{{ __('account_mail.greeting', ['name' => $name]) }}</p>
<p style="margin:0 0 28px;font-size:16px;color:#5E625C">{{ __('account_mail.'.$kind.'_intro') }}</p>
<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 28px;width:100%"><tr><td><a class="mail-button" href="{{ $actionUrl }}" style="display:inline-block;background:#8B7355;border:1px solid #8B7355;border-radius:4px;color:#FFFFFF;font-family:Arial,Helvetica,sans-serif;font-size:16px;font-weight:bold;line-height:1.5;padding:16px 24px;text-decoration:none;mso-padding-alt:16px 24px">{{ __('account_mail.'.$kind.'_action') }} &rarr;</a></td></tr></table>
@if($kind !== 'changed')<p style="margin:0 0 24px;font-size:16px;color:#5E625C">{{ __('account_mail.expiry', ['minutes' => $minutes]) }}</p>@endif
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td style="background:#F7F5F0;border-left:3px solid #879184;padding:18px 20px;font-size:16px;color:#5E625C">{{ __('account_mail.'.$kind.'_note') }}</td></tr></table>
<p style="margin:28px 0 8px;font-size:16px;color:#77766F">{{ __('account_mail.fallback') }}</p>
<p style="margin:0;font-size:16px;word-break:break-all;overflow-wrap:anywhere"><a href="{{ $actionUrl }}" style="color:#8B7355;text-decoration:underline">{{ $actionUrl }}</a></p>
</td></tr></table></td></tr>
<tr><td style="padding:28px 8px 0;color:#77766F;font-size:16px"><p style="margin:0 0 8px;font-family:Georgia,'Times New Roman',serif;font-size:20px;color:#242724">{{ __('account_mail.footer') }}</p><p style="margin:0;font-size:16px">{{ __('account_mail.automated') }}</p></td></tr>
</table></td></tr></table>
</body></html>
