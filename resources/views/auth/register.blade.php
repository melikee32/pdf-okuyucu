<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kayıt — PDF Okuyucu</title>
    @vite('resources/css/pdf.css')
</head>
<body>

<div class="container" style="max-width:420px; padding-top:80px;">

    <div class="header">
        <h1>📚 PDF Okuyucu</h1>
        <p>Yeni hesap oluştur</p>
    </div>

    <div class="card">

        @if($errors->any())
        <div class="alert-error" style="margin-bottom:16px;">
            @foreach($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
        @endif

        <form action="/register" method="POST">
            @csrf

            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:0.8rem; color:#7A8494; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:6px;">
                    Ad Soyad
                </label>
                <input
                    type="text"
                    name="name"
                    value="{{ old('name') }}"
                    required
                    style="width:100%; padding:12px 14px; border-radius:10px; border:1px solid rgba(255,255,255,0.6); background:rgba(255,255,255,0.32); font-size:0.95rem; color:#374151; outline:none;">
            </div>

            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:0.8rem; color:#7A8494; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:6px;">
                    E-posta
                </label>
                <input
                    type="email"
                    name="email"
                    value="{{ old('email') }}"
                    required
                    style="width:100%; padding:12px 14px; border-radius:10px; border:1px solid rgba(255,255,255,0.6); background:rgba(255,255,255,0.32); font-size:0.95rem; color:#374151; outline:none;">
            </div>

            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:0.8rem; color:#7A8494; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:6px;">
                    Şifre
                </label>
                <input
                    type="password"
                    name="password"
                    required
                    style="width:100%; padding:12px 14px; border-radius:10px; border:1px solid rgba(255,255,255,0.6); background:rgba(255,255,255,0.32); font-size:0.95rem; color:#374151; outline:none;">
            </div>

            <div style="margin-bottom:20px;">
                <label style="display:block; font-size:0.8rem; color:#7A8494; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:6px;">
                    Şifre Tekrar
                </label>
                <input
                    type="password"
                    name="password_confirmation"
                    required
                    style="width:100%; padding:12px 14px; border-radius:10px; border:1px solid rgba(255,255,255,0.6); background:rgba(255,255,255,0.32); font-size:0.95rem; color:#374151; outline:none;">
            </div>

            <button type="submit" class="upload-button" style="width:100%; padding:12px;">
                Kayıt Ol
            </button>

        </form>

        <p style="text-align:center; margin-top:16px; font-size:0.875rem; color:#7A8494;">
            Zaten hesabın var mı?
            <a href="/login" style="color:#687F96; font-weight:600; text-decoration:none;">Giriş yap</a>
        </p>

    </div>

</div>

</body>
</html>