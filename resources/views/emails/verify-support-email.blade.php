<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: sans-serif; color: #1e293b;">
    <p>Hallo,</p>
    <p>
        bitte bestätigen Sie die Support-E-Mail-Adresse für <strong>{{ $tenantName }}</strong>,
        indem Sie auf den folgenden Link klicken:
    </p>
    <p>
        <a href="{{ $verificationUrl }}" style="display:inline-block;padding:10px 20px;background-color:#005FD7;color:#ffffff;text-decoration:none;border-radius:8px;">
            E-Mail-Adresse bestätigen
        </a>
    </p>
    {{-- Review-Runde 12: Gültigkeit und Absender nennen. --}}
    <p>Der Link ist 60 Minuten gültig. Danach können Sie im Admin-Bereich unter „Branding“ eine neue Bestätigungs-Mail anfordern.</p>
    <p>Wenn Sie diese Änderung nicht veranlasst haben, können Sie diese E-Mail ignorieren.</p>
    <p>Viele Grüße<br>{{ \App\Models\PlatformSetting::siteName() }}</p>
</body>
</html>
