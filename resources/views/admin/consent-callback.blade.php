<!DOCTYPE html>
<html>
<head><title>Admin Consent</title></head>
<body>
    <p>{{ $success ? 'Consent granted successfully.' : ($error ?? 'Consent failed.') }}</p>
    <script>
        if (window.opener) {
            window.opener.postMessage(
                { type: 'admin-consent', success: @json($success), error: @json($error) },
                window.location.origin
            );
            window.close();
        }
    </script>
</body>
</html>
