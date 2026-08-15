<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Giriş — PDF Okuyucu</title>
    @vite('resources/css/pdf.css')
</head>

<body>

<div class="auth-container">

    <div class="auth-header">
        <h1>📚 PDF Okuyucu</h1>
        <p>Devam etmek için giriş yapın</p>
    </div>

    <div class="auth-card">

        @if($errors->any())
            <div class="auth-error">
                <p>{{ $errors->first() }}</p>
            </div>
        @endif

        <form action="/login" method="POST">
            @csrf

            <div class="auth-field">
                <label for="email">E-posta</label>

                <input
                    id="email"
                    type="email"
                    name="email"
                    value="{{ old('email') }}"
                    placeholder="ornek@mail.com"
                    required
                >
            </div>

            <div class="auth-field">
                <label for="password">Şifre</label>

                <input
                    id="password"
                    type="password"
                    name="password"
                    placeholder="••••••••"
                    required
                >
            </div>

            <button type="submit" class="auth-button">
                Giriş Yap
            </button>

        </form>

        <p class="auth-register">
            Hesabın yok mu?
            <a href="/register">Kayıt ol</a>
        </p>

    </div>

</div>

</body>
</html>