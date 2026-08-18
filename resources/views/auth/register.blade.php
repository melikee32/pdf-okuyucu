<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kayıt — PDF Okuyucu</title>
    @vite('resources/css/pdf.css')
</head>

<body>

<div class="auth-container register-container">

    <div class="auth-header">
        <h1>📚 PDF Okuyucu</h1>
        <p>Yeni hesap oluştur</p>
    </div>

    <div class="auth-card register-card">

        @if($errors->any())
            <div class="auth-error register-error">
                @foreach($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <form action="/register" method="POST">
            @csrf

            <div class="auth-field register-field">
                <label for="name">Ad Soyad 𐀪</label>

                <input
                    id="name"
                    type="text"
                    name="name"
                    value="{{ old('name') }}"
                    placeholder="Ad Soyad"
                    required
                >
            </div>

            <div class="auth-field register-field">
                <label for="email">E-posta 🖂</label>

                <input
                    id="email"
                    type="email"
                    name="email"
                    value="{{ old('email') }}"
                    placeholder="ornek@mail.com"
                    required
                >
            </div>

            <div class="auth-field register-field">
                <label for="password">Şifre 🗝</label>

                <input
                    id="password"
                    type="password"
                    name="password"
                    placeholder="••••••••"
                    required
                >
            </div>

            <div class="auth-field register-field-last">
                <label for="password_confirmation">Şifre Tekrar</label>

                <input
                    id="password_confirmation"
                    type="password"
                    name="password_confirmation"
                    placeholder="••••••••"
                    required
                >
            </div>

            <button type="submit" class="auth-button register-button">
              ✿  Kayıt Ol 
            </button>

        </form>

        <p class="auth-register">
            Zaten hesabın var mı?
            <a href="/login">Giriş yap</a>
        </p>

    </div>

</div>

</body>
</html>